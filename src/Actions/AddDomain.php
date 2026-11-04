<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Actions;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Data\CustomHostname;
use YourVendor\CloudflareSaasDomains\Data\SslOptions;
use YourVendor\CloudflareSaasDomains\Events\DomainAdded;
use YourVendor\CloudflareSaasDomains\Exceptions\ApexDomainNotAllowedException;
use YourVendor\CloudflareSaasDomains\Exceptions\BlockedHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\CloudflareSaasDomainsException;
use YourVendor\CloudflareSaasDomains\Exceptions\DomainAlreadyClaimedException;
use YourVendor\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidTenantException;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Support\DomainStateTransitioner;
use YourVendor\CloudflareSaasDomains\Validation\HostnameValidator;

final class AddDomain
{
    public function __construct(
        private readonly CustomHostnameClient $client,
        private readonly HostnameValidator $validator,
        private readonly DomainStateTransitioner $transitioner,
        private readonly Config $config,
    ) {}

    /**
     * Idempotent per tenant: adding a hostname the tenant already has returns the
     * existing domain without calling Cloudflare or firing an event.
     *
     * @throws InvalidHostnameException
     * @throws ApexDomainNotAllowedException
     * @throws BlockedHostnameException
     * @throws DomainAlreadyClaimedException
     * @throws InvalidTenantException
     * @throws CloudflareSaasDomainsException
     */
    public function handle(Model $tenant, string $hostname): TenantDomain
    {
        $this->assertTenant($tenant);

        $hostname = $this->validator->validate($hostname);

        [$domain, $rollback] = $this->claim($tenant, $hostname);

        if ($rollback === null && $domain->cloudflare_id !== null) {
            return $domain;
        }

        try {
            $remote = $this->createOrAdopt($hostname);
        } catch (Throwable $exception) {
            match ($rollback) {
                'force_delete' => $domain->forceDelete(),
                'trash' => $domain->delete(),
                default => null,
            };

            throw $exception;
        }

        return $this->transitioner->apply($domain, $remote, [new DomainAdded($domain)]);
    }

    private function assertTenant(Model $tenant): void
    {
        $class = $this->config->get('cloudflare-saas-domains.tenant_model');

        if (is_string($class) && $class !== '' && ! $tenant instanceof $class) {
            throw new InvalidTenantException(sprintf('Domains can only belong to %s, got %s.', $class, $tenant::class));
        }

        if ($tenant->getKey() === null) {
            throw new InvalidTenantException('Save the tenant before adding a domain to it.');
        }
    }

    /**
     * Takes the hostname locally first. The unique index settles concurrent requests.
     *
     * @return array{TenantDomain, 'force_delete'|'trash'|null} The domain, and how to undo a new claim if Cloudflare fails.
     */
    private function claim(Model $tenant, string $hostname): array
    {
        $existing = TenantDomain::withTrashed()->where('hostname', $hostname)->first();

        if ($existing === null) {
            try {
                $domain = new TenantDomain(['hostname' => $hostname]);
                $domain->tenant()->associate($tenant);
                $domain->save();

                return [$domain, 'force_delete'];
            } catch (UniqueConstraintViolationException) {
                $existing = TenantDomain::withTrashed()->where('hostname', $hostname)->firstOrFail();
            }
        }

        if ($existing->trashed()) {
            $existing->tenant()->associate($tenant);
            $existing->fill([
                'cloudflare_id' => null,
                'hostname_status' => null,
                'ssl_status' => null,
                'verification_records' => null,
                'ssl_expires_at' => null,
                'last_checked_at' => null,
                'verified_at' => null,
                'failure_reason' => null,
            ]);
            $existing->restore();

            return [$existing, 'trash'];
        }

        if ((string) $existing->tenant_id !== (string) $tenant->getKey() || $existing->tenant_type !== $tenant->getMorphClass()) {
            throw new DomainAlreadyClaimedException("\"{$hostname}\" is already connected to another tenant.");
        }

        return [$existing, null];
    }

    /**
     * A duplicate means an earlier attempt reached Cloudflare but its response was
     * lost, or a retry hit its own create. The hostname is on our zone, so use it.
     */
    private function createOrAdopt(string $hostname): CustomHostname
    {
        $ssl = $this->config->get('cloudflare-saas-domains.ssl');

        try {
            return $this->client->createHostname($hostname, SslOptions::fromConfig(is_array($ssl) ? $ssl : []));
        } catch (DuplicateHostnameException $exception) {
            return $this->client->findByHostname($hostname) ?? throw $exception;
        }
    }
}
