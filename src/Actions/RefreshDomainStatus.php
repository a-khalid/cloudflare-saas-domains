<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Actions;

use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Exceptions\DomainNotProvisionedException;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Support\DomainStateTransitioner;

final class RefreshDomainStatus
{
    public function __construct(
        private readonly CustomHostnameClient $client,
        private readonly DomainStateTransitioner $transitioner,
    ) {}

    public function handle(TenantDomain $domain): TenantDomain
    {
        if ($domain->cloudflare_id === null || $domain->trashed()) {
            throw new DomainNotProvisionedException("\"{$domain->hostname}\" has no Cloudflare hostname to refresh.");
        }

        try {
            $remote = $this->client->getHostname($domain->cloudflare_id);
        } catch (ResourceNotFoundException) {
            return $this->transitioner->applyRemoteDeletion($domain);
        }

        return $this->transitioner->apply($domain, $remote);
    }
}
