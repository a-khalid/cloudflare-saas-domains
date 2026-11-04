<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YourVendor\CloudflareSaasDomains\Events\DomainAdded;
use YourVendor\CloudflareSaasDomains\Events\DomainFailed;
use YourVendor\CloudflareSaasDomains\Exceptions\DomainAlreadyClaimedException;
use YourVendor\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\ServerErrorException;
use YourVendor\CloudflareSaasDomains\Facades\CloudflareSaasDomains;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Resolution\TenantResolver;

beforeEach(function () {
    Sleep::fake();
    config()->set('cloudflare-saas-domains.http.retries', 0);
});

/** Runs $callback once, right after the first time a TenantDomain row is read. */
function afterFirstRead(Closure $callback): void
{
    $done = false;

    TenantDomain::retrieved(function () use (&$done, $callback) {
        if (! $done) {
            $done = true;
            $callback();
        }
    });
}

describe('tenant database switching', function () {
    beforeEach(function () {
        config()->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    });

    afterEach(fn () => DB::setDefaultConnection('testing'));

    it('stays on the central connection after the default connection moves', function () {
        $domain = domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active']);

        DB::setDefaultConnection('tenant');

        expect(TenantDomain::query()->whereKey($domain->id)->exists())->toBeTrue();
    });

    it('honors an explicit connection', function () {
        config()->set('cloudflare-saas-domains.connection', 'tenant');

        expect((new TenantDomain)->getConnectionName())->toBe('tenant');
    });
});

describe('claiming a hostname', function () {
    it('does not let the loser of a race for a trashed row think it won', function () {
        Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
        $winner = merchant('Winner');
        domainFor(merchant('Old'))->delete();

        afterFirstRead(function () use ($winner) {
            TenantDomain::withTrashed()->where('hostname', 'shop.acme.com')->update([
                'deleted_at' => null,
                'tenant_id' => $winner->id,
                'cloudflare_id' => 'winner-id',
                'hostname_status' => 'pending',
            ]);
        });

        expect(fn () => CloudflareSaasDomains::add(merchant('Loser'), 'shop.acme.com'))
            ->toThrow(DomainAlreadyClaimedException::class);

        Http::assertNothingSent();
        expect(TenantDomain::query()->first()->tenant_id)->toBe((string) $winner->id);
    });

    it('re-creates a hostname Cloudflare deleted, for the same tenant', function () {
        Event::fake([DomainAdded::class]);
        Http::fake(['*' => Http::response(envelope(hostnamePayload(['id' => 'new-id'])))]);
        $merchant = merchant();
        domainFor($merchant, ['hostname_status' => 'deleted', 'ssl_status' => 'deleted', 'verified_at' => now()]);

        $domain = CloudflareSaasDomains::add($merchant, 'shop.acme.com');

        expect($domain->cloudflare_id)->toBe('new-id')
            ->and($domain->hostname_status)->toBe('pending')
            ->and($domain->verified_at)->toBeNull();
        Event::assertDispatchedTimes(DomainAdded::class, 1);
    });

    it('lets the real owner take a hostname Cloudflare deleted', function () {
        Http::fake(['*' => Http::response(envelope(hostnamePayload(['id' => 'new-id'])))]);
        domainFor(merchant('Squatter'), ['hostname_status' => 'deleted', 'ssl_status' => 'deleted']);
        $owner = merchant('Owner');

        expect(CloudflareSaasDomains::add($owner, 'shop.acme.com')->tenant->is($owner))->toBeTrue();
    });

    it('frees a claim abandoned by a killed process, but only once it is old', function () {
        Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
        domainFor(merchant('Dead'), ['cloudflare_id' => null, 'hostname_status' => null, 'ssl_status' => null]);
        $other = merchant('Other');

        expect(fn () => CloudflareSaasDomains::add($other, 'shop.acme.com'))->toThrow(DomainAlreadyClaimedException::class);

        $this->travel(16)->minutes();

        expect(CloudflareSaasDomains::add($other, 'shop.acme.com')->tenant->is($other))->toBeTrue();
    });

    it('does not release a row a concurrent request has connected', function () {
        Http::fake(function () {
            TenantDomain::query()->where('hostname', 'shop.acme.com')->update(['cloudflare_id' => 'other-request']);

            return Http::response(errorEnvelope(1, 'down'), 500);
        });

        expect(fn () => CloudflareSaasDomains::add(merchant(), 'shop.acme.com'))->toThrow(ServerErrorException::class);

        expect(TenantDomain::query()->value('cloudflare_id'))->toBe('other-request');
    });

    it('keeps the hostname when a concurrent failure released the row mid-create', function () {
        Event::fake([DomainAdded::class]);
        Http::fake(function () {
            TenantDomain::query()->where('hostname', 'shop.acme.com')->forceDelete();

            return Http::response(envelope(hostnamePayload()));
        });

        $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

        expect($domain->fresh()->cloudflare_id)->toBe('cf-id-1');
        Event::assertDispatchedTimes(DomainAdded::class, 1);
    });

    it('fires no events for a row that no longer exists', function () {
        Event::fake([DomainFailed::class]);
        Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'moved'])))]);
        $domain = domainFor(merchant());
        TenantDomain::query()->whereKey($domain->id)->forceDelete();

        CloudflareSaasDomains::refresh($domain);

        Event::assertNotDispatched(DomainFailed::class);
    });

    it('will not adopt a hostname Cloudflare is deleting', function () {
        Http::fake([
            HOSTNAMES_URL.'?*' => Http::response(envelope([hostnamePayload(['status' => 'pending_deletion'])])),
            HOSTNAMES_URL => Http::response(errorEnvelope(1, 'Duplicate custom hostname found.'), 409),
        ]);

        expect(fn () => CloudflareSaasDomains::add(merchant(), 'shop.acme.com'))->toThrow(DuplicateHostnameException::class);
        expect(TenantDomain::withTrashed()->count())->toBe(0);
    });
});

describe('certificate state', function () {
    it('keeps a live store resolving when a renewal poll omits expires_on', function () {
        Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active', 'verification_errors' => []])))]);
        $domain = domainFor(merchant(), [
            'hostname_status' => 'active',
            'ssl_status' => 'active',
            'ssl_expires_at' => now()->addDays(20),
        ]);

        CloudflareSaasDomains::refresh($domain);

        expect($domain->ssl_status)->toBe('pending_validation')
            ->and($domain->ssl_expires_at)->not->toBeNull()
            ->and($domain->isResolvable())->toBeTrue()
            ->and(app(TenantResolver::class)->resolve('shop.acme.com'))->not->toBeNull();
    });

    it('stores the expiry as the right instant in a non-UTC app', function () {
        date_default_timezone_set('Asia/Riyadh');

        try {
            Http::fake(['*' => Http::response(envelope(hostnamePayload([], ['expires_on' => '2030-01-01T00:00:00Z'])))]);
            $domain = domainFor(merchant());

            CloudflareSaasDomains::refresh($domain);

            expect($domain->fresh()->ssl_expires_at->utc()->toIso8601ZuluString())->toBe('2030-01-01T00:00:00Z');
        } finally {
            date_default_timezone_set('UTC');
        }
    });

    it('keeps the failure reason on later polls', function () {
        Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'blocked', 'verification_errors' => []])))]);
        $domain = domainFor(merchant());

        CloudflareSaasDomains::refresh($domain);
        CloudflareSaasDomains::refresh($domain);

        expect($domain->fresh()->failure_reason)->toContain('"blocked"');
    });
});

describe('resolver cache', function () {
    it('forgets the old hostname on a rename', function () {
        $domain = domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active']);
        expect(app(TenantResolver::class)->resolve('shop.acme.com'))->not->toBeNull();

        $domain->update(['hostname' => 'new.acme.com']);

        expect(app(TenantResolver::class)->resolve('shop.acme.com'))->toBeNull()
            ->and(app(TenantResolver::class)->resolve('new.acme.com'))->not->toBeNull();
    });

    it('notices a status change followed by another save in one transaction', function () {
        $domain = domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active']);
        expect(app(TenantResolver::class)->resolve('shop.acme.com'))->not->toBeNull();
        Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'moved'], ['status' => 'active'])))]);

        DB::transaction(function () use ($domain) {
            CloudflareSaasDomains::refresh($domain);
            $domain->update(['failure_reason' => 'note']);
        });

        expect(app(TenantResolver::class)->resolve('shop.acme.com'))->toBeNull();
    });

    it('does not forget until the transaction commits', function () {
        $domain = domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active']);
        app(TenantResolver::class)->resolve('shop.acme.com');

        try {
            DB::transaction(function () use ($domain) {
                $domain->update(['hostname_status' => 'moved']);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(TenantResolver::class)->resolve('shop.acme.com');

        expect(collect(DB::getQueryLog())->pluck('query')->join(' '))->not->toContain('tenant_domains');
    });
});

describe('DNS instructions in terminal states', function () {
    it('tells the merchant nothing is left when the hostname is gone', function () {
        $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
            'hostname_status' => 'deleted',
            'ssl_status' => 'deleted',
            'verification_records' => ['ownership' => ['name' => 'x', 'value' => 'y']],
        ]));

        expect($instructions->records)->toBe([])
            ->and($instructions->hostnameGone)->toBeTrue()
            ->and($instructions->retryRequired)->toBeFalse()
            ->and($instructions->toArray()['hostname_gone'])->toBeTrue();
    });

    it('flags a retry when validation timed out', function () {
        $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
            'hostname_status' => 'active',
            'ssl_status' => 'validation_timed_out',
        ]));

        expect($instructions->retryRequired)->toBeTrue()
            ->and($instructions->complete)->toBeFalse()
            ->and($instructions->toArray()['retry_required'])->toBeTrue();
    });

    it('needs no retry on a healthy domain', function () {
        expect(CloudflareSaasDomains::instructions(domainFor(merchant()))->retryRequired)->toBeFalse();
    });
});
