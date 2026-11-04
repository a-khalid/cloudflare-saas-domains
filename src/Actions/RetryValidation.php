<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Actions;

use AKhalid\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use AKhalid\CloudflareSaasDomains\Data\SslOptions;
use AKhalid\CloudflareSaasDomains\Exceptions\DomainNotProvisionedException;
use AKhalid\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Support\DomainStateTransitioner;
use Illuminate\Contracts\Config\Repository as Config;

final class RetryValidation
{
    public function __construct(
        private readonly CustomHostnameClient $client,
        private readonly DomainStateTransitioner $transitioner,
        private readonly Config $config,
    ) {}

    /**
     * Asks Cloudflare to re-check now, which also resets its validation backoff.
     * Does nothing for a domain that is already live.
     *
     * @throws DomainNotProvisionedException
     * @throws ResourceNotFoundException when Cloudflare no longer has the hostname. The domain is marked deleted first.
     */
    public function handle(TenantDomain $domain): TenantDomain
    {
        if ($domain->cloudflare_id === null || $domain->trashed()) {
            throw new DomainNotProvisionedException("\"{$domain->hostname}\" has no Cloudflare hostname to validate.");
        }

        if ($domain->isLive()) {
            return $domain;
        }

        $config = $this->config->get('cloudflare-saas-domains.ssl');
        $defaults = SslOptions::fromConfig(is_array($config) ? $config : []);

        try {
            $current = $this->client->getHostname($domain->cloudflare_id);

            $remote = $this->client->revalidateHostname(
                $domain->cloudflare_id,
                $current->ssl === null ? $defaults : SslOptions::matching($current->ssl, $defaults),
            );
        } catch (ResourceNotFoundException $exception) {
            $this->transitioner->applyRemoteDeletion($domain);

            throw $exception;
        }

        return $this->transitioner->apply($domain, $remote);
    }
}
