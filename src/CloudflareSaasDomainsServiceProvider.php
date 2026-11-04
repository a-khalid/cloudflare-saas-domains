<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains;

use AKhalid\CloudflareSaasDomains\Cloudflare\ClientOptions;
use AKhalid\CloudflareSaasDomains\Cloudflare\CloudflareClient;
use AKhalid\CloudflareSaasDomains\Console\SyncDomainsCommand;
use AKhalid\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use AKhalid\CloudflareSaasDomains\Http\Middleware\ResolveTenantByHostname;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Observers\TenantDomainObserver;
use AKhalid\CloudflareSaasDomains\Resolution\CurrentTenant;
use AKhalid\CloudflareSaasDomains\Resolution\TenantResolver;
use AKhalid\CloudflareSaasDomains\Support\Payload;
use AKhalid\CloudflareSaasDomains\Tenancy\NullTenantInitializer;
use AKhalid\CloudflareSaasDomains\Tenancy\StanclTenantInitializer;
use AKhalid\CloudflareSaasDomains\Tenancy\TenantInitializer;
use AKhalid\CloudflareSaasDomains\Validation\HostnameValidator;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Tenancy;

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

        $this->publishMigration();

        if ($this->app->runningInConsole()) {
            $this->commands([SyncDomainsCommand::class]);
        }

        $this->callAfterResolving(Router::class, static function (Router $router): void {
            $router->aliasMiddleware('cf-domains.tenant', ResolveTenantByHostname::class);
        });

        TenantDomain::observe(TenantDomainObserver::class);
    }

    private function publishMigration(): void
    {
        $source = __DIR__.'/../database/migrations/'.self::MIGRATION;
        $tag = self::CONFIG.'-migrations';

        if (version_compare($this->app->version(), '11.0', '>=')) {
            $this->publishesMigrations([$source => $this->app->databasePath('migrations/'.self::MIGRATION)], $tag);

            return;
        }

        // Laravel 10 has no publishesMigrations(), so the date is set here instead.
        $name = date('Y_m_d_His').substr(self::MIGRATION, strlen('2026_01_01_000000'));

        $this->publishes([$source => $this->app->databasePath('migrations/'.$name)], $tag);
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
