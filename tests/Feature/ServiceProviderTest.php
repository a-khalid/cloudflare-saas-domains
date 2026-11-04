<?php

declare(strict_types=1);

use AKhalid\CloudflareSaasDomains\Cloudflare\CloudflareClient;
use AKhalid\CloudflareSaasDomains\CloudflareSaasDomainsServiceProvider;
use AKhalid\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Resolution\TenantResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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

it('publishes the migration under a date that vendor:publish rewrites', function () {
    $published = ServiceProvider::publishableMigrationPaths();

    expect(array_filter($published, fn (string $path) => is_file($path) && str_ends_with($path, '_create_tenant_domains_table.php')))->toHaveCount(1);
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

    $migration = require __DIR__.'/../../database/migrations/2026_01_01_000000_create_tenant_domains_table.php';
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
