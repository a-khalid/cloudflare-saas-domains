<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Enums\HostnameStatus;
use YourVendor\CloudflareSaasDomains\Enums\SslStatus;
use YourVendor\CloudflareSaasDomains\Events\DomainRemoved;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;

final class RemoveDomain
{
    public function __construct(
        private readonly CustomHostnameClient $client,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Cloudflare goes first, so a failure there leaves the domain untouched and
     * the call can be repeated. Removing a domain twice is a no-op.
     */
    public function handle(TenantDomain $domain): void
    {
        if ($domain->trashed()) {
            return;
        }

        if ($domain->cloudflare_id !== null) {
            try {
                $this->client->deleteHostname($domain->cloudflare_id);
            } catch (ResourceNotFoundException) {
                // Already gone, which is the outcome we wanted.
            }
        }

        $domain->hostname_status = HostnameStatus::Deleted->value;
        $domain->ssl_status = SslStatus::Deleted->value;
        $domain->verification_records = null;
        $domain->failure_reason = null;
        $domain->save();
        $domain->delete();

        $this->events->dispatch(new DomainRemoved($domain));
    }
}
