<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use YourVendor\CloudflareSaasDomains\CloudflareSaasDomainsServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the network unless it asks to, and the live suite does.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [CloudflareSaasDomainsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('cache.default', 'array');
            $config->set('queue.default', 'sync');
            $config->set('cloudflare-saas-domains.api_token', 'test-token');
            $config->set('cloudflare-saas-domains.zone_id', 'zone-123');
            $config->set('cloudflare-saas-domains.cname_target', 'customers.saas.io');
            $config->set('cloudflare-saas-domains.fallback_origin', 'fallback.saas.io');
            $config->set('cloudflare-saas-domains.validation.blocked_domains', ['saas.io']);
            $config->set('cloudflare-saas-domains.http.retry_jitter_ms', 0);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['config']->set('cloudflare-saas-domains.tenant_key_type', 'string');

        Schema::create('merchants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
