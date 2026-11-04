<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Observers;

use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Resolution\TenantResolver;

/**
 * Keeps the resolver cache honest. Timestamps like last_checked_at do not touch it.
 *
 * Changes are read when each save happens, not after commit: by then wasChanged()
 * only describes the last save in the transaction. Only the forget waits for commit.
 */
final class TenantDomainObserver
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function created(TenantDomain $domain): void
    {
        $this->forgetAfterCommit($domain, $domain->hostname);
    }

    public function updated(TenantDomain $domain): void
    {
        if (! $domain->wasChanged([
            'hostname', 'hostname_status', 'ssl_status', 'ssl_expires_at', 'tenant_type', 'tenant_id', 'deleted_at',
        ])) {
            return;
        }

        $this->forgetAfterCommit($domain, $domain->hostname);

        $previous = $domain->getPrevious()['hostname'] ?? null;

        if (is_string($previous)) {
            $this->forgetAfterCommit($domain, $previous);
        }
    }

    public function deleted(TenantDomain $domain): void
    {
        $this->forgetAfterCommit($domain, $domain->hostname);
    }

    public function restored(TenantDomain $domain): void
    {
        $this->forgetAfterCommit($domain, $domain->hostname);
    }

    private function forgetAfterCommit(TenantDomain $domain, string $hostname): void
    {
        $domain->getConnection()->afterCommit(fn () => $this->resolver->forget($hostname));
    }
}
