<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Resolution\TenantResolver;

/** Keeps the resolver cache honest. Timestamps like last_checked_at do not touch it. */
final class TenantDomainObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function created(TenantDomain $domain): void
    {
        $this->resolver->forget($domain->hostname);
    }

    public function updated(TenantDomain $domain): void
    {
        if ($domain->wasChanged([
            'hostname', 'hostname_status', 'ssl_status', 'ssl_expires_at', 'tenant_type', 'tenant_id', 'deleted_at',
        ])) {
            $this->resolver->forget($domain->hostname);
        }
    }

    public function deleted(TenantDomain $domain): void
    {
        $this->resolver->forget($domain->hostname);
    }

    public function restored(TenantDomain $domain): void
    {
        $this->resolver->forget($domain->hostname);
    }
}
