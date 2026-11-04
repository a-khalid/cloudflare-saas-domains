<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Tenancy;

use Illuminate\Database\Eloquent\Model;

final class NullTenantInitializer implements TenantInitializer
{
    public function initialize(Model $tenant): void {}
}
