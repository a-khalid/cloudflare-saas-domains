<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use YourVendor\CloudflareSaasDomains\Actions\AddDomain;
use YourVendor\CloudflareSaasDomains\Actions\RefreshDomainStatus;
use YourVendor\CloudflareSaasDomains\Actions\RemoveDomain;
use YourVendor\CloudflareSaasDomains\Actions\RetryValidation;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Data\FallbackOrigin;
use YourVendor\CloudflareSaasDomains\Dns\DnsInstructions;
use YourVendor\CloudflareSaasDomains\Exceptions\MissingConfigurationException;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Resolution\CurrentTenant;
use YourVendor\CloudflareSaasDomains\Testing\FakeCustomHostnameClient;

/**
 * The public API behind the facade. Actions are resolved on each call so that
 * fake() takes effect immediately.
 */
final class CloudflareSaasDomainsManager
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function add(Model $tenant, string $hostname): TenantDomain
    {
        return $this->container->make(AddDomain::class)->handle($tenant, $hostname);
    }

    public function remove(TenantDomain $domain): void
    {
        $this->container->make(RemoveDomain::class)->handle($domain);
    }

    public function refresh(TenantDomain $domain): TenantDomain
    {
        return $this->container->make(RefreshDomainStatus::class)->handle($domain);
    }

    public function retryValidation(TenantDomain $domain): TenantDomain
    {
        return $this->container->make(RetryValidation::class)->handle($domain);
    }

    public function instructions(TenantDomain $domain): DnsInstructions
    {
        foreach (['cname_target', 'fallback_origin'] as $key) {
            $target = $this->config->get("cloudflare-saas-domains.{$key}");

            if (is_string($target) && $target !== '') {
                return DnsInstructions::for($domain, $target);
            }
        }

        throw new MissingConfigurationException('Set cloudflare-saas-domains.cname_target or fallback_origin to build DNS instructions.');
    }

    public function fallbackOrigin(): FallbackOrigin
    {
        return $this->client()->getFallbackOrigin();
    }

    public function setFallbackOrigin(string $origin): FallbackOrigin
    {
        return $this->client()->updateFallbackOrigin($origin);
    }

    public function deleteFallbackOrigin(): FallbackOrigin
    {
        return $this->client()->deleteFallbackOrigin();
    }

    public function client(): CustomHostnameClient
    {
        return $this->container->make(CustomHostnameClient::class);
    }

    public function currentTenant(): ?Model
    {
        return $this->container->make(CurrentTenant::class)->get();
    }

    public function currentDomain(): ?TenantDomain
    {
        return $this->container->make(CurrentTenant::class)->domain();
    }

    /**
     * Replaces the Cloudflare client with an in-memory zone for this application instance.
     */
    public function fake(): FakeCustomHostnameClient
    {
        $fake = new FakeCustomHostnameClient;

        $this->container->instance(CustomHostnameClient::class, $fake);

        return $fake;
    }
}
