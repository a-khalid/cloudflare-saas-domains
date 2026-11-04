<?php

declare(strict_types=1);

use AKhalid\CloudflareSaasDomains\Facades\CloudflareSaasDomains;
use AKhalid\CloudflareSaasDomains\Http\Middleware\ResolveTenantByHostname;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Resolution\CurrentTenant;
use AKhalid\CloudflareSaasDomains\Resolution\TenantResolver;
use AKhalid\CloudflareSaasDomains\Tests\Fixtures\Merchant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    Route::middleware('cf-domains.tenant')->get('/whoami', fn (Request $request) => [
        'tenant' => $request->attributes->get('tenant')?->name,
        'facade' => CloudflareSaasDomains::currentTenant()?->name,
        'domain' => CloudflareSaasDomains::currentDomain()?->hostname,
        'hostname' => app(CurrentTenant::class)->hostname(),
    ]);

    Route::middleware('cf-domains.tenant:optional')->get('/optional', fn () => [
        'tenant' => CloudflareSaasDomains::currentTenant()?->name,
        'domain' => CloudflareSaasDomains::currentDomain()?->hostname,
    ]);
});

function live(array $attributes = []): TenantDomain
{
    return domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active', ...$attributes]);
}

it('resolves the tenant from the request host', function () {
    live();

    $this->get('https://shop.acme.com/whoami')->assertOk()->assertExactJson([
        'tenant' => 'Acme',
        'facade' => 'Acme',
        'domain' => 'shop.acme.com',
        'hostname' => 'shop.acme.com',
    ]);
});

it('matches regardless of case', function () {
    live();

    $this->get('https://SHOP.Acme.com/whoami')->assertOk()->assertJsonPath('tenant', 'Acme');
});

it('answers 404 for an unknown host', function () {
    $this->get('https://nope.acme.com/whoami')->assertNotFound();
});

it('answers 404 for a malformed or internal host', function () {
    $this->get('http://localhost/whoami')->assertNotFound();
});

it('only resolves active domains', function (string $hostnameStatus, ?string $sslStatus) {
    domainFor(merchant(), ['hostname_status' => $hostnameStatus, 'ssl_status' => $sslStatus]);

    $this->get('https://shop.acme.com/whoami')->assertNotFound();
})->with([
    ['pending', 'pending_validation'],
    ['pending', 'active'],
    ['active', 'pending_validation'],
    ['active', 'initializing'],
    ['active', null],
    ['moved', 'active'],
    ['blocked', 'active'],
    ['deleted', 'deleted'],
    ['active', 'validation_timed_out'],
    ['active', 'expired'],
]);

it('keeps resolving through a renewal while the certificate is unexpired', function () {
    live(['ssl_status' => 'pending_validation', 'ssl_expires_at' => now()->addDay()]);

    $this->get('https://shop.acme.com/whoami')->assertOk();
});

it('stops resolving a renewal once the certificate has expired', function () {
    live(['ssl_status' => 'pending_validation', 'ssl_expires_at' => now()->subDay()]);

    $this->get('https://shop.acme.com/whoami')->assertNotFound();
});

it('agrees with the scope in isResolvable', function (string $hostnameStatus, string $sslStatus, ?string $expires, bool $expected) {
    $domain = domainFor(merchant(), [
        'hostname_status' => $hostnameStatus,
        'ssl_status' => $sslStatus,
        'ssl_expires_at' => $expires === null ? null : now()->modify($expires),
    ]);

    expect($domain->isResolvable())->toBe($expected)
        ->and(TenantDomain::query()->resolvable()->exists())->toBe($expected);
})->with([
    ['active', 'active', null, true],
    ['active_redeploying', 'pending_expiration', null, true],
    ['active', 'pending_issuance', '+1 day', true],
    ['active', 'pending_issuance', '-1 day', false],
    ['active', 'pending_issuance', null, false],
    ['pending', 'active', null, false],
]);

it('does not resolve a soft-deleted domain', function () {
    live()->delete();

    $this->get('https://shop.acme.com/whoami')->assertNotFound();
});

it('answers 404 when the tenant is gone, and drops the cache entry', function () {
    $domain = live();
    $this->get('https://shop.acme.com/whoami')->assertOk();

    $domain->tenant->delete();

    $this->get('https://shop.acme.com/whoami')->assertNotFound();
});

it('continues without a tenant in optional mode', function () {
    $this->get('https://nope.acme.com/optional')->assertOk()->assertExactJson(['tenant' => null, 'domain' => null]);
});

it('caches a resolved host, skipping the domain query', function () {
    live();
    $this->get('https://shop.acme.com/whoami')->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();
    app(TenantResolver::class)->resolve('shop.acme.com');

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('merchants');
});

it('caches a miss, skipping the query entirely', function () {
    app(TenantResolver::class)->resolve('nope.acme.com');

    DB::enableQueryLog();
    DB::flushQueryLog();
    app(TenantResolver::class)->resolve('nope.acme.com');

    expect(DB::getQueryLog())->toBe([]);
});

it('invalidates the cache when a domain goes live', function () {
    $domain = domainFor(merchant());
    $this->get('https://shop.acme.com/whoami')->assertNotFound();

    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active'], ['status' => 'active'])))]);
    CloudflareSaasDomains::refresh($domain);

    $this->get('https://shop.acme.com/whoami')->assertOk();
});

it('invalidates the cache when a live domain stops being active', function () {
    $domain = live();
    $this->get('https://shop.acme.com/whoami')->assertOk();

    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'moved'], ['status' => 'active'])))]);
    CloudflareSaasDomains::refresh($domain);

    $this->get('https://shop.acme.com/whoami')->assertNotFound();
});

it('invalidates the cache when a domain is removed', function () {
    $domain = live();
    $this->get('https://shop.acme.com/whoami')->assertOk();

    Http::fake(['*' => Http::response(['id' => 'cf-id-1'])]);
    CloudflareSaasDomains::remove($domain);

    $this->get('https://shop.acme.com/whoami')->assertNotFound();
});

it('invalidates the cache when a removed domain is restored', function () {
    $domain = live();
    $domain->delete();
    $this->get('https://shop.acme.com/whoami')->assertNotFound();

    $domain->restore();

    $this->get('https://shop.acme.com/whoami')->assertOk();
});

it('keeps the cache when a poll changes nothing', function () {
    $domain = live();
    app(TenantResolver::class)->resolve('shop.acme.com');

    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active'], ['status' => 'active'])))]);
    CloudflareSaasDomains::refresh($domain);

    DB::enableQueryLog();
    DB::flushQueryLog();
    app(TenantResolver::class)->resolve('shop.acme.com');

    expect(collect(DB::getQueryLog())->pluck('query')->join(' '))->not->toContain('tenant_domains');
});

it('resolves string tenant keys and morph map aliases', function () {
    Relation::morphMap(['merchant' => Merchant::class]);

    try {
        live();

        expect(TenantDomain::query()->first()->tenant_type)->toBe('merchant');
        $this->get('https://shop.acme.com/whoami')->assertOk()->assertJsonPath('tenant', 'Acme');
    } finally {
        Relation::morphMap([], false);
        Relation::$morphMap = [];
    }
});

it('returns null for a tenant type that is not a model', function () {
    $domain = live();
    TenantDomain::query()->whereKey($domain->id)->update(['tenant_type' => 'Not\\A\\Class']);
    app(TenantResolver::class)->forget('shop.acme.com');

    expect(app(TenantResolver::class)->resolve('shop.acme.com'))->toBeNull();
});

it('exposes the alias and class as the same middleware', function () {
    expect(app('router')->getMiddleware()['cf-domains.tenant'])->toBe(ResolveTenantByHostname::class);
});
