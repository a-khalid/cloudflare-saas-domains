<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Resolution;

use Illuminate\Database\Eloquent\Model;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;

/** The tenant for the current request. Bound as scoped, so it resets between Octane requests. */
final class CurrentTenant
{
    private ?ResolvedTenant $resolved = null;

    private ?TenantDomain $domain = null;

    public function set(ResolvedTenant $resolved): void
    {
        $this->resolved = $resolved;
        $this->domain = null;
    }

    public function get(): ?Model
    {
        return $this->resolved?->tenant;
    }

    /**
     * Loaded on first use: resolving a cached host does not need the domain row.
     */
    public function domain(): ?TenantDomain
    {
        if ($this->resolved === null) {
            return null;
        }

        return $this->domain ??= TenantDomain::query()->find($this->resolved->domainId);
    }

    public function hostname(): ?string
    {
        return $this->resolved?->hostname;
    }
}
