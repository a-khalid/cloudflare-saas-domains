<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YourVendor\CloudflareSaasDomains\Events\DomainAdded;
use YourVendor\CloudflareSaasDomains\Events\DomainFailed;
use YourVendor\CloudflareSaasDomains\Events\DomainRemoved;
use YourVendor\CloudflareSaasDomains\Events\DomainVerified;
use YourVendor\CloudflareSaasDomains\Events\SslActivated;
use YourVendor\CloudflareSaasDomains\Exceptions\DomainNotProvisionedException;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use YourVendor\CloudflareSaasDomains\Exceptions\ServerErrorException;
use YourVendor\CloudflareSaasDomains\Facades\CloudflareSaasDomains;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;

beforeEach(function () {
    Sleep::fake();
    Event::fake([DomainAdded::class, DomainVerified::class, SslActivated::class, DomainFailed::class, DomainRemoved::class]);
});

function respondWith(array $overrides = [], array $ssl = []): void
{
    Http::fake(['*' => Http::response(envelope(hostnamePayload($overrides, $ssl)))]);
}

it('fires nothing while a poll sees no change', function () {
    respondWith();
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);
    CloudflareSaasDomains::refresh($domain);

    expect($domain->last_checked_at)->not->toBeNull();
    Event::assertNothingDispatched();
});

it('fires DomainVerified exactly once when the hostname becomes active', function () {
    respondWith(['status' => 'active', 'verification_errors' => []]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);
    CloudflareSaasDomains::refresh($domain);
    CloudflareSaasDomains::refresh($domain);

    expect($domain->hostname_status)->toBe('active')
        ->and($domain->verified_at)->not->toBeNull()
        ->and($domain->failure_reason)->toBeNull()
        ->and($domain->isLive())->toBeFalse();

    Event::assertDispatchedTimes(DomainVerified::class, 1);
    Event::assertNotDispatched(SslActivated::class);
});

it('fires SslActivated exactly once when the certificate becomes active', function () {
    respondWith(['status' => 'active', 'verification_errors' => []], ['status' => 'active']);
    $domain = domainFor(merchant(), ['hostname_status' => 'active', 'verified_at' => now()]);

    CloudflareSaasDomains::refresh($domain);
    CloudflareSaasDomains::refresh($domain);

    expect($domain->isLive())->toBeTrue();

    Event::assertNotDispatched(DomainVerified::class);
    Event::assertDispatchedTimes(SslActivated::class, 1);
    Event::assertDispatched(SslActivated::class, fn (SslActivated $event) => $event->previousSslStatus === 'pending_validation' && $event->wasVerifiedBefore);
});

it('treats active_redeploying as already active', function () {
    respondWith(['status' => 'active_redeploying']);
    $domain = domainFor(merchant(), ['hostname_status' => 'active', 'verified_at' => now()->subDay()]);
    $verifiedAt = $domain->verified_at->toIso8601String();

    CloudflareSaasDomains::refresh($domain);

    expect($domain->verified_at->toIso8601String())->toBe($verifiedAt);
    Event::assertNotDispatched(DomainVerified::class);
});

it('does not re-fire SslActivated moving between serving states', function () {
    respondWith(['status' => 'active'], ['status' => 'pending_expiration']);
    $domain = domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active']);

    CloudflareSaasDomains::refresh($domain);

    Event::assertNotDispatched(SslActivated::class);
});

it('fires DomainFailed once for each failing hostname status', function (string $status) {
    respondWith(['status' => $status, 'verification_errors' => ['nope']]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);
    CloudflareSaasDomains::refresh($domain);

    expect($domain->isFailed())->toBeTrue()->and($domain->failure_reason)->toBe('nope');
    Event::assertDispatchedTimes(DomainFailed::class, 1);
    Event::assertDispatched(DomainFailed::class, fn (DomainFailed $event) => $event->reason === 'nope');
})->with(['moved', 'deleted', 'blocked', 'pending_blocked', 'test_blocked', 'test_failed']);

it('fires DomainFailed once for each failing SSL status', function (string $status) {
    respondWith(['verification_errors' => []], ['status' => $status, 'validation_errors' => [['message' => 'CAA failure']]]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);
    CloudflareSaasDomains::refresh($domain);

    expect($domain->failure_reason)->toBe('CAA failure');
    Event::assertDispatchedTimes(DomainFailed::class, 1);
})->with(['validation_timed_out', 'issuance_timed_out', 'initializing_timed_out', 'deployment_timed_out', 'deletion_timed_out', 'expired', 'deleted']);

it('describes the failure when Cloudflare gives no message', function () {
    respondWith(['status' => 'blocked', 'verification_errors' => []]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);

    expect($domain->failure_reason)->toContain('"blocked"');
    Event::assertDispatched(DomainFailed::class, fn (DomainFailed $event) => str_contains($event->reason, '"blocked"'));
});

it('does not fire DomainFailed for ordinary pending errors, but keeps the reason', function () {
    respondWith(['verification_errors' => ['custom hostname does not CNAME to this zone.']]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);

    expect($domain->failure_reason)->toContain('CNAME');
    Event::assertNotDispatched(DomainFailed::class);
});

it('stays quiet and keeps the raw value for an unknown status', function () {
    respondWith(['status' => 'brand_new_status']);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);

    expect($domain->hostname_status)->toBe('brand_new_status');
    Event::assertNothingDispatched();
});

it('fires again after recovering and failing a second time', function () {
    $domain = domainFor(merchant());

    Http::fake(['*' => Http::sequence()
        ->push(envelope(hostnamePayload(['status' => 'moved'])))
        ->push(envelope(hostnamePayload(['status' => 'active', 'verification_errors' => []])))
        ->push(envelope(hostnamePayload(['status' => 'moved'])))
        ->push(envelope(hostnamePayload(['status' => 'active', 'verification_errors' => []])))]);

    foreach (range(1, 4) as $poll) {
        CloudflareSaasDomains::refresh($domain);
    }

    Event::assertDispatchedTimes(DomainFailed::class, 2);
    Event::assertDispatchedTimes(DomainVerified::class, 2);
});

it('treats a 404 as remote deletion: one event, no exception', function () {
    Http::fake(['*' => Http::response(errorEnvelope(1436, 'not found'), 404)]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);
    CloudflareSaasDomains::refresh($domain);

    expect($domain->hostname_status)->toBe('deleted')
        ->and($domain->ssl_status)->toBe('deleted')
        ->and($domain->failure_reason)->toContain('no longer exists');

    Event::assertDispatchedTimes(DomainFailed::class, 1);
});

it('raises other API errors and leaves the domain unchanged', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response(errorEnvelope(1, 'down'), 500)]);
    $domain = domainFor(merchant());

    expect(fn () => CloudflareSaasDomains::refresh($domain))->toThrow(ServerErrorException::class);

    expect($domain->fresh()->last_checked_at)->toBeNull();
    Event::assertNothingDispatched();
});

it('refuses to refresh a domain with no Cloudflare hostname', function () {
    Http::fake();

    CloudflareSaasDomains::refresh(domainFor(merchant(), ['cloudflare_id' => null]));
})->throws(DomainNotProvisionedException::class);

it('uses the row, not a stale model, to decide what changed', function () {
    respondWith(['status' => 'active']);
    $merchant = merchant();
    $stale = domainFor($merchant);
    TenantDomain::query()->whereKey($stale->id)->update(['hostname_status' => 'active']);

    CloudflareSaasDomains::refresh($stale);

    Event::assertNotDispatched(DomainVerified::class);
});

it('stores the certificate expiry', function () {
    respondWith(['status' => 'active'], ['status' => 'active', 'expires_on' => '2030-01-01T00:00:00Z']);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::refresh($domain);

    expect($domain->ssl_expires_at->year)->toBe(2030);
});

it('retries validation with the certificate settings Cloudflare holds', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::retryValidation($domain);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request->data() === ['ssl' => [
        'method' => 'txt',
        'type' => 'dv',
        'bundle_method' => 'ubiquitous',
        'certificate_authority' => 'google',
        'wildcard' => false,
    ]]);
});

it('falls back to configured defaults when Cloudflare returned no certificate', function () {
    $payload = hostnamePayload();
    unset($payload['ssl']);
    Http::fake(['*' => Http::response(envelope($payload))]);

    CloudflareSaasDomains::retryValidation(domainFor(merchant()));

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request->data() === ['ssl' => ['method' => 'txt', 'type' => 'dv']]);
});

it('applies the state a retry returns and fires the transition', function () {
    Http::fake(['*' => Http::sequence()
        ->push(envelope(hostnamePayload()))
        ->push(envelope(hostnamePayload(['status' => 'active', 'verification_errors' => []])))]);

    $domain = CloudflareSaasDomains::retryValidation(domainFor(merchant()));

    expect($domain->hostname_status)->toBe('active');
    Event::assertDispatchedTimes(DomainVerified::class, 1);
});

it('does not call Cloudflare to retry a live domain', function () {
    Http::fake();

    CloudflareSaasDomains::retryValidation(domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'active']));

    Http::assertNothingSent();
});

it('refuses a retry without a Cloudflare hostname', function () {
    Http::fake();

    CloudflareSaasDomains::retryValidation(domainFor(merchant(), ['cloudflare_id' => null]));
})->throws(DomainNotProvisionedException::class);

it('marks the domain deleted and raises when the retried hostname is gone', function () {
    Http::fake(['*' => Http::response(errorEnvelope(1436, 'not found'), 404)]);
    $domain = domainFor(merchant());

    expect(fn () => CloudflareSaasDomains::retryValidation($domain))->toThrow(ResourceNotFoundException::class);

    expect($domain->fresh()->hostname_status)->toBe('deleted');
    Event::assertDispatchedTimes(DomainFailed::class, 1);
});

it('removes from Cloudflare, soft deletes, and fires once', function () {
    Http::fake(['*' => Http::response(['id' => 'cf-id-1'])]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::remove($domain);
    CloudflareSaasDomains::remove($domain);

    expect($domain->trashed())->toBeTrue()
        ->and(TenantDomain::query()->count())->toBe(0)
        ->and(TenantDomain::onlyTrashed()->first()->hostname_status)->toBe('deleted');

    Http::assertSentCount(1);
    Event::assertDispatchedTimes(DomainRemoved::class, 1);
    Event::assertNotDispatched(DomainFailed::class);
});

it('still removes locally when Cloudflare already lost the hostname', function () {
    Http::fake(['*' => Http::response(errorEnvelope(1436, 'not found'), 404)]);
    $domain = domainFor(merchant());

    CloudflareSaasDomains::remove($domain);

    expect($domain->trashed())->toBeTrue();
    Event::assertDispatchedTimes(DomainRemoved::class, 1);
});

it('removes a claim that never reached Cloudflare without calling it', function () {
    Http::fake();
    $domain = domainFor(merchant(), ['cloudflare_id' => null]);

    CloudflareSaasDomains::remove($domain);

    Http::assertNothingSent();
    expect($domain->trashed())->toBeTrue();
});

it('keeps the domain when Cloudflare refuses the delete', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response(errorEnvelope(1, 'down'), 500)]);
    $domain = domainFor(merchant());

    expect(fn () => CloudflareSaasDomains::remove($domain))->toThrow(ServerErrorException::class);

    expect($domain->fresh()->trashed())->toBeFalse()
        ->and($domain->fresh()->hostname_status)->toBe('pending');
    Event::assertNotDispatched(DomainRemoved::class);
});
