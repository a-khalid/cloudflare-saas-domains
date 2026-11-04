<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Concerns;

use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
