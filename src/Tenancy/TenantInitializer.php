<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Tenancy;

use Illuminate\Database\Eloquent\Model;

/** Runs after the middleware has resolved a tenant. */
interface TenantInitializer
{
    public function initialize(Model $tenant): void;
}
