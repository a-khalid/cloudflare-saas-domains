<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Facades;

use AKhalid\CloudflareSaasDomains\CloudflareSaasDomainsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AKhalid\CloudflareSaasDomains\Models\TenantDomain add(\Illuminate\Database\Eloquent\Model $tenant, string $hostname)
 * @method static void remove(\AKhalid\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \AKhalid\CloudflareSaasDomains\Models\TenantDomain refresh(\AKhalid\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \AKhalid\CloudflareSaasDomains\Models\TenantDomain retryValidation(\AKhalid\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \AKhalid\CloudflareSaasDomains\Dns\DnsInstructions instructions(\AKhalid\CloudflareSaasDomains\Models\TenantDomain $domain)
 * @method static \AKhalid\CloudflareSaasDomains\Data\FallbackOrigin fallbackOrigin()
 * @method static \AKhalid\CloudflareSaasDomains\Data\FallbackOrigin setFallbackOrigin(string $origin)
 * @method static \AKhalid\CloudflareSaasDomains\Data\FallbackOrigin deleteFallbackOrigin()
 * @method static \AKhalid\CloudflareSaasDomains\Contracts\CustomHostnameClient client()
 * @method static \Illuminate\Database\Eloquent\Model|null currentTenant()
 * @method static \AKhalid\CloudflareSaasDomains\Models\TenantDomain|null currentDomain()
 * @method static \AKhalid\CloudflareSaasDomains\Testing\FakeCustomHostnameClient fake()
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
