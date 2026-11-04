<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YourVendor\CloudflareSaasDomains\Events\DomainAdded;
use YourVendor\CloudflareSaasDomains\Events\DomainVerified;
use YourVendor\CloudflareSaasDomains\Events\SslActivated;
use YourVendor\CloudflareSaasDomains\Exceptions\ApexDomainNotAllowedException;
use YourVendor\CloudflareSaasDomains\Exceptions\BlockedHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\DomainAlreadyClaimedException;
use YourVendor\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidTenantException;
use YourVendor\CloudflareSaasDomains\Exceptions\ServerErrorException;
use YourVendor\CloudflareSaasDomains\Facades\CloudflareSaasDomains;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Tests\Fixtures\Merchant;
use YourVendor\CloudflareSaasDomains\Tests\Fixtures\OtherTenant;

beforeEach(fn () => Sleep::fake());

it('creates the hostname and stores what Cloudflare returned', function () {
    Event::fake();
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $merchant = merchant();

    $domain = CloudflareSaasDomains::add($merchant, 'Shop.ACME.com');

    expect($domain->hostname)->toBe('shop.acme.com')
        ->and($domain->cloudflare_id)->toBe('cf-id-1')
        ->and($domain->hostname_status)->toBe('pending')
        ->and($domain->ssl_status)->toBe('pending_validation')
        ->and($domain->failure_reason)->toContain('does not CNAME')
        ->and($domain->last_checked_at)->not->toBeNull()
        ->and($domain->verified_at)->toBeNull()
        ->and($domain->verification_records['ownership']['value'])->toBe('ownership-token')
        ->and($domain->verification_records['ssl_validation'][0]['txt_value'])->toBe('dcv-token')
        ->and($domain->tenant->is($merchant))->toBeTrue()
        ->and($merchant->customDomains()->count())->toBe(1);

    Http::assertSent(fn (Request $request) => $request->data()['hostname'] === 'shop.acme.com'
        && $request->data()['ssl'] === ['method' => 'txt', 'type' => 'dv']);

    Event::assertDispatchedTimes(DomainAdded::class, 1);
    Event::assertNotDispatched(DomainVerified::class);
    Event::assertNotDispatched(SslActivated::class);
});

it('stores punycode for IDN hostnames', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['hostname' => 'shop.xn--bcher-kva.de'])))]);

    $domain = CloudflareSaasDomains::add(merchant(), 'shop.bücher.de');

    expect($domain->hostname)->toBe('shop.xn--bcher-kva.de');
    Http::assertSent(fn (Request $request) => $request->data()['hostname'] === 'shop.xn--bcher-kva.de');
});

it('uses the configured SSL defaults', function () {
    config()->set('cloudflare-saas-domains.ssl.method', 'http');
    config()->set('cloudflare-saas-domains.ssl.certificate_authority', 'lets_encrypt');
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);

    CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    Http::assertSent(fn (Request $request) => $request->data()['ssl'] === [
        'method' => 'http', 'type' => 'dv', 'certificate_authority' => 'lets_encrypt',
    ]);
});

it('never calls Cloudflare for a rejected hostname', function (string $hostname, string $exception) {
    Http::fake();

    expect(fn () => CloudflareSaasDomains::add(merchant(), $hostname))->toThrow($exception);

    Http::assertNothingSent();
    expect(TenantDomain::withTrashed()->count())->toBe(0);
})->with([
    'invalid' => ['not a domain', InvalidHostnameException::class],
    'apex' => ['acme.com', ApexDomainNotAllowedException::class],
    'multi-part apex' => ['acme.com.sa', ApexDomainNotAllowedException::class],
    'platform' => ['shop.saas.io', BlockedHostnameException::class],
    'platform root' => ['saas.io', BlockedHostnameException::class],
]);

it('refuses the configured CNAME target and fallback origin', function (string $hostname) {
    config()->set('cloudflare-saas-domains.validation.blocked_domains', []);
    config()->set('cloudflare-saas-domains.cname_target', 'customers.other.io');
    config()->set('cloudflare-saas-domains.fallback_origin', 'fallback.other.io');
    Http::fake();

    CloudflareSaasDomains::add(merchant(), $hostname);
})->throws(BlockedHostnameException::class)->with(['customers.other.io', 'fallback.other.io']);

it('allows apex domains when Apex Proxying is enabled', function () {
    config()->set('cloudflare-saas-domains.validation.allow_apex', true);
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['hostname' => 'acme.com'])))]);

    expect(CloudflareSaasDomains::add(merchant(), 'acme.com')->hostname)->toBe('acme.com');
});

it('is idempotent for the same tenant', function () {
    Event::fake();
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $merchant = merchant();

    $first = CloudflareSaasDomains::add($merchant, 'shop.acme.com');
    $second = CloudflareSaasDomains::add($merchant, 'SHOP.acme.com.');

    expect($second->is($first))->toBeTrue()
        ->and(TenantDomain::query()->count())->toBe(1);

    Http::assertSentCount(1);
    Event::assertDispatchedTimes(DomainAdded::class, 1);
});

it('refuses a hostname that another tenant holds', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);

    CloudflareSaasDomains::add(merchant('A'), 'shop.acme.com');
    CloudflareSaasDomains::add(merchant('B'), 'shop.acme.com');
})->throws(DomainAlreadyClaimedException::class);

it('adopts the hostname when Cloudflare says it already exists', function () {
    Event::fake();
    Http::fake([
        HOSTNAMES_URL.'?*' => Http::response(envelope([hostnamePayload(['id' => 'existing-id'])])),
        HOSTNAMES_URL => Http::response(errorEnvelope(1406, 'Duplicate custom hostname found.'), 409),
    ]);

    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    expect($domain->cloudflare_id)->toBe('existing-id');
    Event::assertDispatchedTimes(DomainAdded::class, 1);
});

it('raises the duplicate when the hostname cannot be found on the zone', function () {
    Http::fake([
        HOSTNAMES_URL.'?*' => Http::response(envelope([])),
        HOSTNAMES_URL => Http::response(errorEnvelope(1406, 'Duplicate custom hostname found.'), 409),
    ]);

    expect(fn () => CloudflareSaasDomains::add(merchant(), 'shop.acme.com'))->toThrow(DuplicateHostnameException::class);
    expect(TenantDomain::withTrashed()->count())->toBe(0);
});

it('releases the claim when Cloudflare fails, so the call can be repeated', function () {
    Event::fake();
    Http::fake(['*' => Http::sequence()
        ->push(errorEnvelope(1, 'down'), 500)->push(errorEnvelope(1, 'down'), 500)
        ->push(errorEnvelope(1, 'down'), 500)->push(errorEnvelope(1, 'down'), 500)
        ->push(envelope(hostnamePayload()))]);
    $merchant = merchant();

    expect(fn () => CloudflareSaasDomains::add($merchant, 'shop.acme.com'))->toThrow(ServerErrorException::class);
    expect(TenantDomain::withTrashed()->count())->toBe(0);
    Event::assertNotDispatched(DomainAdded::class);

    expect(CloudflareSaasDomains::add($merchant, 'shop.acme.com')->cloudflare_id)->toBe('cf-id-1');
    Event::assertDispatchedTimes(DomainAdded::class, 1);
});

it('finishes provisioning a claim left behind by a crash', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $merchant = merchant();
    domainFor($merchant, ['cloudflare_id' => null, 'hostname_status' => null, 'ssl_status' => null]);

    expect(CloudflareSaasDomains::add($merchant, 'shop.acme.com')->cloudflare_id)->toBe('cf-id-1');
    Http::assertSentCount(1);
});

it('keeps a crashed claim in place when the repeat attempt fails', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response(errorEnvelope(1, 'down'), 500)]);
    $merchant = merchant();
    domainFor($merchant, ['cloudflare_id' => null]);

    expect(fn () => CloudflareSaasDomains::add($merchant, 'shop.acme.com'))->toThrow(ServerErrorException::class);
    expect(TenantDomain::query()->count())->toBe(1);
});

it('reuses a soft-deleted row, even for a new tenant', function () {
    Event::fake();
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['id' => 'new-id'])))]);
    $old = merchant('Old');
    domainFor($old, ['verified_at' => now(), 'failure_reason' => 'old'])->delete();
    $new = merchant('New');

    $domain = CloudflareSaasDomains::add($new, 'shop.acme.com');

    expect($domain->trashed())->toBeFalse()
        ->and($domain->tenant->is($new))->toBeTrue()
        ->and($domain->cloudflare_id)->toBe('new-id')
        ->and($domain->verified_at)->toBeNull()
        ->and(TenantDomain::withTrashed()->count())->toBe(1);

    Event::assertDispatchedTimes(DomainAdded::class, 1);
});

it('trashes a restored row again when Cloudflare fails', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response(errorEnvelope(1, 'down'), 500)]);
    $merchant = merchant();
    domainFor($merchant)->delete();

    expect(fn () => CloudflareSaasDomains::add($merchant, 'shop.acme.com'))->toThrow(ServerErrorException::class);

    expect(TenantDomain::query()->count())->toBe(0)
        ->and(TenantDomain::onlyTrashed()->count())->toBe(1);
});

it('fires verified and SSL events when a hostname is already live on create', function () {
    Event::fake();
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active'], ['status' => 'active'])))]);

    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    expect($domain->verified_at)->not->toBeNull();
    Event::assertDispatchedTimes(DomainVerified::class, 1);
    Event::assertDispatchedTimes(SslActivated::class, 1);
});

it('fires DomainAdded before the transition events', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active'], ['status' => 'active'])))]);
    $order = [];

    foreach ([DomainAdded::class, DomainVerified::class, SslActivated::class] as $event) {
        Event::listen($event, function () use (&$order, $event) {
            $order[] = class_basename($event);
        });
    }

    CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    expect($order)->toBe(['DomainAdded', 'DomainVerified', 'SslActivated']);
});

it('gives DomainAdded listeners the stored records', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $seen = null;

    Event::listen(DomainAdded::class, function (DomainAdded $event) use (&$seen) {
        $seen = $event->domain->verification_records['ownership']['value'] ?? null;
    });

    CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    expect($seen)->toBe('ownership-token');
});

it('enforces the configured tenant model', function () {
    config()->set('cloudflare-saas-domains.tenant_model', Merchant::class);
    Http::fake();

    CloudflareSaasDomains::add(OtherTenant::query()->create(['name' => 'x']), 'shop.acme.com');
})->throws(InvalidTenantException::class);

it('refuses an unsaved tenant', function () {
    Http::fake();

    CloudflareSaasDomains::add(new Merchant, 'shop.acme.com');
})->throws(InvalidTenantException::class);

it('settles a race for the same hostname through the unique index', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $merchant = merchant();
    $raced = false;

    TenantDomain::creating(function () use ($merchant, &$raced) {
        if (! $raced) {
            $raced = true;
            DB::table('tenant_domains')->insert([
                'hostname' => 'shop.acme.com',
                'tenant_type' => $merchant->getMorphClass(),
                'tenant_id' => $merchant->getKey(),
            ]);
        }
    });

    expect(CloudflareSaasDomains::add($merchant, 'shop.acme.com')->cloudflare_id)->toBe('cf-id-1')
        ->and(TenantDomain::query()->count())->toBe(1);
});

it('refuses the loser of a race from another tenant', function () {
    Http::fake();
    $winner = merchant('Winner');
    $raced = false;

    TenantDomain::creating(function () use ($winner, &$raced) {
        if (! $raced) {
            $raced = true;
            DB::table('tenant_domains')->insert([
                'hostname' => 'shop.acme.com',
                'tenant_type' => $winner->getMorphClass(),
                'tenant_id' => $winner->getKey(),
            ]);
        }
    });

    expect(fn () => CloudflareSaasDomains::add(merchant('Loser'), 'shop.acme.com'))->toThrow(DomainAlreadyClaimedException::class);
    Http::assertNothingSent();
});
