<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Tenancy;

/**
 * Bound only when stancl/tenancy is installed. Tenants that are not stancl tenants
 * are left alone, so a host app can mix both kinds.
 */
final class StanclTenantInitializer implements TenantInitializer
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function initialize(Model $tenant): void
    {
        if ($tenant instanceof Tenant) {
            $this->tenancy->initialize($tenant);
        }
    }
}
