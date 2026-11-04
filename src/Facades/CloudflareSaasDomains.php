<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Facades;

use Illuminate\Support\Facades\Facade;
use YourVendor\CloudflareSaasDomains\CloudflareSaasDomainsManager;

/**
 * @method static \YourVendor\CloudflareSaasDomains\Models\TenantDomain add(\Illuminate\Database\Eloquent\Model $tenant, string $hostname)
 * @method static void remove(\YourVendor\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \YourVendor\CloudflareSaasDomains\Models\TenantDomain refresh(\YourVendor\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \YourVendor\CloudflareSaasDomains\Models\TenantDomain retryValidation(\YourVendor\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \YourVendor\CloudflareSaasDomains\Dns\DnsInstructions instructions(\YourVendor\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \YourVendor\CloudflareSaasDomains\Data\FallbackOrigin fallbackOrigin()
 * @method static \YourVendor\CloudflareSaasDomains\Data\FallbackOrigin setFallbackOrigin(string $origin)
 * @method static \YourVendor\CloudflareSaasDomains\Data\FallbackOrigin deleteFallbackOrigin()
 * @method static \YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient client()
 * @method static \Illuminate\Database\Eloquent\Model|null currentTenant()
 * @method static \YourVendor\CloudflareSaasDomains\Models\TenantDomain|null currentDomain()
 * @method static \YourVendor\CloudflareSaasDomains\Testing\FakeCustomHostnameClient fake()
 *
 * @see CloudflareSaasDomainsManager
 */
final class CloudflareSaasDomains extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CloudflareSaasDomainsManager::class;
    }
}
