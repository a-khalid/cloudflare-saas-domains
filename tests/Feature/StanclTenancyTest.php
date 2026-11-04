<?php

declare(strict_types=1);

use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Tenancy\NullTenantInitializer;
use AKhalid\CloudflareSaasDomains\Tenancy\StanclTenantInitializer;
use AKhalid\CloudflareSaasDomains\Tenancy\TenantInitializer;
use AKhalid\CloudflareSaasDomains\Tests\Fixtures\StanclMerchant;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Tenancy;

it('auto-enables when stancl/tenancy is installed', function () {
    expect(app(TenantInitializer::class))->toBeInstanceOf(StanclTenantInitializer::class);
});

it('can be switched off in config', function () {
    config()->set('cloudflare-saas-domains.integrations.stancl_tenancy', false);

    expect(app(TenantInitializer::class))->toBeInstanceOf(NullTenantInitializer::class);
});

it('initializes tenancy for a resolved stancl tenant', function () {
    $tenancy = new Tenancy;
    app()->instance(Tenancy::class, $tenancy);

    $tenant = StanclMerchant::query()->create(['name' => 'Stancl']);
    $domain = new TenantDomain(['hostname' => 'shop.acme.com', 'cloudflare_id' => 'x', 'hostname_status' => 'active', 'ssl_status' => 'active']);
    $domain->tenant()->associate($tenant);
    $domain->save();

    Route::middleware('cf-domains.tenant')->get('/whoami', fn () => 'ok');

    $this->get('https://shop.acme.com/whoami')->assertOk();

    expect($tenancy->initialized)->toBeTrue()
        ->and($tenancy->tenant->is($tenant))->toBeTrue();
});

it('leaves tenants that are not stancl tenants alone', function () {
    $tenancy = new Tenancy;
    app()->instance(Tenancy::class, $tenancy);

    domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active']);
    Route::middleware('cf-domains.tenant')->get('/whoami', fn () => 'ok');

    $this->get('https://shop.acme.com/whoami')->assertOk();

    expect($tenancy->initialized)->toBeFalse();
});

it('does nothing with the null initializer', function () {
    (new NullTenantInitializer)->initialize(merchant());

    expect(true)->toBeTrue();
});
