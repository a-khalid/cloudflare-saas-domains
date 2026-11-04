<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Actions;

use AKhalid\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use AKhalid\CloudflareSaasDomains\Data\CustomHostname;
use AKhalid\CloudflareSaasDomains\Data\SslOptions;
use AKhalid\CloudflareSaasDomains\Enums\HostnameStatus;
use AKhalid\CloudflareSaasDomains\Events\DomainAdded;
use AKhalid\CloudflareSaasDomains\Exceptions\ApexDomainNotAllowedException;
use AKhalid\CloudflareSaasDomains\Exceptions\BlockedHostnameException;
use AKhalid\CloudflareSaasDomains\Exceptions\CloudflareSaasDomainsException;
use AKhalid\CloudflareSaasDomains\Exceptions\DomainAlreadyClaimedException;
use AKhalid\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use AKhalid\CloudflareSaasDomains\Exceptions\InvalidHostnameException;
use AKhalid\CloudflareSaasDomains\Exceptions\InvalidTenantException;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Support\DomainStateTransitioner;
use AKhalid\CloudflareSaasDomains\Validation\HostnameValidator;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

final class AddDomain
{
    /** Longer than any single attempt can take, retries included. */
    private const ABANDONED_CLAIM_MINUTES = 15;

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
            $this->release($domain, $rollback);

            throw $exception;
        }

        // A concurrent request for the same hostname may have failed and released the row.
        if (! TenantDomain::query()->whereKey($domain->getKey())->exists()) {
            [$domain] = $this->claim($tenant, $hostname);
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
     * Takes the hostname locally first. The unique index settles concurrent inserts,
     * and reclaim() settles concurrent takeovers of an existing row.
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

        if ($this->reclaim($existing, $tenant)) {
            return [$existing->refresh(), 'trash'];
        }

        $existing->refresh();

        if (! $this->belongsTo($existing, $tenant)) {
            throw new DomainAlreadyClaimedException("\"{$hostname}\" is already connected to another tenant.");
        }

        return [$existing, null];
    }

    /**
     * A row nobody holds any more can be taken by anyone: it was removed, Cloudflare
     * deleted the hostname, or a process died before it got a Cloudflare id. The
     * conditional update means only one concurrent caller can win.
     */
    private function reclaim(TenantDomain $domain, Model $tenant): bool
    {
        $taken = TenantDomain::withTrashed()
            ->whereKey($domain->getKey())
            ->where(static function (Builder $query): void {
                $query->whereNotNull('deleted_at')
                    ->orWhere('hostname_status', HostnameStatus::Deleted->value)
                    ->orWhere(static function (Builder $query): void {
                        $query->whereNull('cloudflare_id')
                            ->where('updated_at', '<=', Carbon::now()->subMinutes(self::ABANDONED_CLAIM_MINUTES));
                    });
            })
            ->update([
                'tenant_type' => $tenant->getMorphClass(),
                'tenant_id' => $tenant->getKey(),
                'cloudflare_id' => null,
                'hostname_status' => null,
                'ssl_status' => null,
                'verification_records' => null,
                'ssl_expires_at' => null,
                'ssl_activated_at' => null,
                'last_checked_at' => null,
                'verified_at' => null,
                'failure_reason' => null,
                'deleted_at' => null,
            ]);

        return $taken === 1;
    }

    /**
     * Undoes a claim after Cloudflare failed, unless a concurrent request for the same
     * hostname has since connected it.
     *
     * @param  'force_delete'|'trash'|null  $rollback
     */
    private function release(TenantDomain $domain, ?string $rollback): void
    {
        $unprovisioned = TenantDomain::query()->whereKey($domain->getKey())->whereNull('cloudflare_id');

        match ($rollback) {
            'force_delete' => $unprovisioned->forceDelete(),
            'trash' => $unprovisioned->delete(),
            default => null,
        };
    }

    private function belongsTo(TenantDomain $domain, Model $tenant): bool
    {
        $key = $tenant->getKey();

        return $domain->tenant_type === $tenant->getMorphClass()
            && is_scalar($key)
            && (string) $domain->tenant_id === (string) $key;
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
            $found = $this->client->findByHostname($hostname);

            if ($found === null || in_array($found->status, [HostnameStatus::PendingDeletion, HostnameStatus::Deleted], true)) {
                throw $exception;
            }

            return $found;
        }
    }
}
