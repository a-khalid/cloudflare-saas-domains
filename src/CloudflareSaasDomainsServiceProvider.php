<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Tenancy;
use YourVendor\CloudflareSaasDomains\Cloudflare\ClientOptions;
use YourVendor\CloudflareSaasDomains\Cloudflare\CloudflareClient;
use YourVendor\CloudflareSaasDomains\Console\SyncDomainsCommand;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Http\Middleware\ResolveTenantByHostname;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Observers\TenantDomainObserver;
use YourVendor\CloudflareSaasDomains\Resolution\CurrentTenant;
use YourVendor\CloudflareSaasDomains\Resolution\TenantResolver;
use YourVendor\CloudflareSaasDomains\Support\Payload;
use YourVendor\CloudflareSaasDomains\Tenancy\NullTenantInitializer;
use YourVendor\CloudflareSaasDomains\Tenancy\StanclTenantInitializer;
use YourVendor\CloudflareSaasDomains\Tenancy\TenantInitializer;
use YourVendor\CloudflareSaasDomains\Validation\HostnameValidator;

final class CloudflareSaasDomainsServiceProvider extends ServiceProvider
{
    private const CONFIG = 'cloudflare-saas-domains';

    /** The date is replaced with the publish date by vendor:publish. */
    private const MIGRATION = '2026_01_01_000000_create_tenant_domains_table.php';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cloudflare-saas-domains.php', self::CONFIG);

        $this->app->bind(CustomHostnameClient::class, static fn (Container $app): CloudflareClient => new CloudflareClient(
            $app->make(HttpFactory::class),
            ClientOptions::fromConfig(self::settings($app)),
        ));

        $this->app->bind(HostnameValidator::class, static function (Container $app): HostnameValidator {
            $settings = self::settings($app);
            $validation = Payload::array($settings, 'validation') ?? [];

            return new HostnameValidator(
                blockedDomains: array_values(array_filter([
                    ...Payload::strings($validation, 'blocked_domains'),
                    Payload::string($settings, 'cname_target'),
                    Payload::string($settings, 'fallback_origin'),
                ])),
                allowApex: Payload::bool($validation, 'allow_apex') ?? false,
                extraPublicSuffixes: Payload::strings($validation, 'public_suffixes'),
            );
        });

        $this->app->bind(TenantResolver::class, static function (Container $app): TenantResolver {
            $cache = Payload::array(self::settings($app), 'cache') ?? [];

            return new TenantResolver(
                $app->make(CacheFactory::class)->store(Payload::string($cache, 'store')),
                Payload::string($cache, 'prefix') ?? 'cf-domains:host:',
                Payload::int($cache, 'ttl', 300),
                Payload::int($cache, 'negative_ttl', 60),
            );
        });

        $this->app->bind(TenantInitializer::class, static function (Container $app): TenantInitializer {
            $integrations = Payload::array(self::settings($app), 'integrations') ?? [];

            return (Payload::bool($integrations, 'stancl_tenancy') ?? true) && class_exists(Tenancy::class)
                ? $app->make(StanclTenantInitializer::class)
                : new NullTenantInitializer;
        });

        $this->app->scoped(CurrentTenant::class);
        $this->app->scoped(CloudflareSaasDomainsManager::class);
    }

    public function boot(): void
    {
        $config = $this->app->make(Config::class);

        if (! $config->get(self::CONFIG.'.connection')) {
            $config->set(self::CONFIG.'.connection', $config->get('database.default'));
        }

        $this->publishes([
            __DIR__.'/../config/cloudflare-saas-domains.php' => $this->app->configPath('cloudflare-saas-domains.php'),
        ], self::CONFIG.'-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/'.self::MIGRATION => $this->app->databasePath('migrations/'.self::MIGRATION),
        ], self::CONFIG.'-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncDomainsCommand::class]);
        }

        $this->callAfterResolving(Router::class, static function (Router $router): void {
            $router->aliasMiddleware('cf-domains.tenant', ResolveTenantByHostname::class);
        });

        TenantDomain::observe(TenantDomainObserver::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function settings(Container $app): array
    {
        $settings = $app->make(Config::class)->get(self::CONFIG);

        return is_array($settings) ? $settings : [];
    }
}
