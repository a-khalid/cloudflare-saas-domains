<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use YourVendor\CloudflareSaasDomains\Cloudflare\CloudflareClient;
use YourVendor\CloudflareSaasDomains\CloudflareSaasDomainsServiceProvider;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Resolution\TenantResolver;

it('binds the real client by default', function () {
    expect(app(CustomHostnameClient::class))->toBeInstanceOf(CloudflareClient::class);
});

it('publishes the config and migration', function () {
    $paths = ServiceProvider::pathsToPublish(
        CloudflareSaasDomainsServiceProvider::class,
    );

    expect(array_keys($paths))->toHaveCount(2)
        ->and(implode(',', array_values($paths)))->toContain('cloudflare-saas-domains.php');
});

it('reads a custom cache store and TTLs', function () {
    config()->set('cloudflare-saas-domains.cache.store', 'array');
    config()->set('cloudflare-saas-domains.cache.prefix', 'x:');

    expect(app(TenantResolver::class))->toBeInstanceOf(TenantResolver::class);
});

it('normalizes the hostname on the model', function () {
    expect((new TenantDomain(['hostname' => 'SHOP.bücher.de.']))->hostname)->toBe('shop.xn--bcher-kva.de');
});

it('registers the sync command', function () {
    expect(array_keys(Artisan::all()))->toContain('cf-domains:sync');
});

it('migrates every tenant key type', function (string $type, string $column) {
    config()->set('cloudflare-saas-domains.tenant_key_type', $type);
    config()->set('cloudflare-saas-domains.table', "domains_{$type}");

    $migration = require __DIR__.'/../../database/migrations/create_tenant_domains_table.php';
    $migration->up();

    expect(Schema::getColumnType("domains_{$type}", 'tenant_id'))->toBe($column);

    $migration->down();

    expect(Schema::hasTable("domains_{$type}"))->toBeFalse();
})->with([
    ['int', 'integer'],
    ['string', 'varchar'],
    ['uuid', 'varchar'],
    ['ulid', 'varchar'],
]);
