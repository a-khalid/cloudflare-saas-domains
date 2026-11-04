<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;

/**
 * Add to your tenant model.
 *
 * @mixin Model
 */
trait HasCustomDomains
{
    /**
     * @return MorphMany<TenantDomain, $this>
     */
    public function customDomains(): MorphMany
    {
        return $this->morphMany(TenantDomain::class, 'tenant');
    }
}
