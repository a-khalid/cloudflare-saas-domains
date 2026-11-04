<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Data\SslOptions;
use YourVendor\CloudflareSaasDomains\Enums\FallbackOriginStatus;
use YourVendor\CloudflareSaasDomains\Enums\HostnameStatus;
use YourVendor\CloudflareSaasDomains\Enums\SslStatus;
use YourVendor\CloudflareSaasDomains\Events\DomainAdded;
use YourVendor\CloudflareSaasDomains\Events\DomainFailed;
use YourVendor\CloudflareSaasDomains\Events\DomainRemoved;
use YourVendor\CloudflareSaasDomains\Events\DomainVerified;
use YourVendor\CloudflareSaasDomains\Events\SslActivated;
use YourVendor\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\RateLimitedException;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use YourVendor\CloudflareSaasDomains\Facades\CloudflareSaasDomains;
use YourVendor\CloudflareSaasDomains\Testing\FakeCustomHostnameClient;

it('replaces the real client and never touches the network', function () {
    Http::fake();
    $fake = CloudflareSaasDomains::fake();

    CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    expect(app(CustomHostnameClient::class))->toBe($fake)
        ->and(CloudflareSaasDomains::client())->toBe($fake);
    Http::assertNothingSent();
    $fake->assertCreated('shop.acme.com');
    $fake->assertCreatedCount(1);
    $fake->assertNotCreated('other.acme.com');
});

it('runs a complete lifecycle with the same events as the real thing', function () {
    Event::fake();
    $fake = CloudflareSaasDomains::fake();

    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');
    Event::assertDispatched(DomainAdded::class);
    expect(CloudflareSaasDomains::instructions($domain)->required())->toHaveCount(3);

    $this->travel(10)->minutes();
    $this->artisan('cf-domains:sync')->assertSuccessful();
    Event::assertNotDispatched(DomainVerified::class);

    $fake->verifyHostname('shop.acme.com');
    $this->travel(10)->minutes();
    $this->artisan('cf-domains:sync')->assertSuccessful();
    Event::assertDispatchedTimes(DomainVerified::class, 1);
    Event::assertNotDispatched(SslActivated::class);

    $fake->activateSsl('shop.acme.com');
    $this->travel(10)->minutes();
    $this->artisan('cf-domains:sync')->assertSuccessful();
    Event::assertDispatchedTimes(SslActivated::class, 1);
    expect($domain->fresh()->isLive())->toBeTrue()
        ->and($domain->fresh()->ssl_expires_at)->not->toBeNull();

    CloudflareSaasDomains::remove($domain->fresh());
    $fake->assertDeleted('shop.acme.com');
    Event::assertDispatched(DomainRemoved::class);
});

it('activates in one step', function () {
    $fake = CloudflareSaasDomains::fake();
    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    $fake->activate('shop.acme.com');

    expect(CloudflareSaasDomains::refresh($domain)->isLive())->toBeTrue();
});

it('simulates failures through statuses', function () {
    Event::fake();
    $fake = CloudflareSaasDomains::fake();
    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    $fake->setStatus('shop.acme.com', HostnameStatus::Blocked, SslStatus::ValidationTimedOut, ['blocked by policy']);
    CloudflareSaasDomains::refresh($domain);

    expect($domain->failure_reason)->toBe('blocked by policy');
    Event::assertDispatchedTimes(DomainFailed::class, 1);
});

it('accepts raw status strings', function () {
    $fake = CloudflareSaasDomains::fake();
    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    $fake->setStatus('shop.acme.com', 'surprise', 'also_surprise');
    CloudflareSaasDomains::refresh($domain);

    expect($domain->hostname_status)->toBe('surprise')->and($domain->ssl_status)->toBe('also_surprise');
});

it('keeps the SSL status when only the hostname status is set', function () {
    $fake = CloudflareSaasDomains::fake();
    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    $fake->setStatus('shop.acme.com', HostnameStatus::Active);

    expect(CloudflareSaasDomains::refresh($domain)->ssl_status)->toBe('pending_validation');
});

it('simulates tokens that are not ready yet', function () {
    $fake = CloudflareSaasDomains::fake();
    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    $fake->withoutValidationRecords('shop.acme.com');
    CloudflareSaasDomains::refresh($domain);

    expect(CloudflareSaasDomains::instructions($domain)->awaitingValidationRecords)->toBeTrue();
});

it('simulates a hostname expiring on Cloudflare', function () {
    Event::fake();
    $fake = CloudflareSaasDomains::fake();
    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    $fake->forget('shop.acme.com');
    CloudflareSaasDomains::refresh($domain);

    expect($domain->hostname_status)->toBe('deleted');
    Event::assertDispatchedTimes(DomainFailed::class, 1);
});

it('simulates API failures once', function () {
    $fake = CloudflareSaasDomains::fake();
    $merchant = merchant();

    $fake->failNextWith(new RateLimitedException('Slow down'));

    expect(fn () => CloudflareSaasDomains::add($merchant, 'shop.acme.com'))->toThrow(RateLimitedException::class);

    expect(CloudflareSaasDomains::add($merchant, 'shop.acme.com')->cloudflare_id)->not->toBeNull();
});

it('rejects duplicates like Cloudflare does', function () {
    $fake = new FakeCustomHostnameClient;
    $fake->createHostname('shop.acme.com', new SslOptions);

    $fake->createHostname('shop.acme.com', new SslOptions);
})->throws(DuplicateHostnameException::class);

it('adopts a seeded hostname through the duplicate path', function () {
    $fake = CloudflareSaasDomains::fake();
    $seeded = $fake->seed('shop.acme.com');

    expect(CloudflareSaasDomains::add(merchant(), 'shop.acme.com')->cloudflare_id)->toBe($seeded->id);
    $fake->assertNothingCreated();
});

it('accepts a chosen id when seeding', function () {
    expect((new FakeCustomHostnameClient)->seed('shop.acme.com', id: 'chosen')->id)->toBe('chosen');
});

it('models HTTP validation', function () {
    $hostname = (new FakeCustomHostnameClient)->createHostname('shop.acme.com', new SslOptions('http'));

    expect($hostname->ssl?->method)->toBe('http')
        ->and($hostname->ssl?->validationRecords[0]->httpUrl)->toContain('pki-validation');
});

it('lists and paginates', function () {
    $fake = new FakeCustomHostnameClient;
    $fake->seed('a.acme.com');
    $fake->seed('b.acme.com');

    expect($fake->listHostnames(1, 1)->items)->toHaveCount(1)
        ->and($fake->listHostnames(1, 1)->totalPages)->toBe(2)
        ->and($fake->listHostnames(hostname: 'b.acme.com')->items[0]->hostname)->toBe('b.acme.com')
        ->and($fake->findByHostname('missing.acme.com'))->toBeNull();
});

it('tracks retries and deletes', function () {
    $fake = CloudflareSaasDomains::fake();
    $domain = CloudflareSaasDomains::add(merchant(), 'shop.acme.com');

    $fake->assertNothingRevalidated();
    $fake->assertNothingDeleted();

    CloudflareSaasDomains::retryValidation($domain);
    $fake->assertRevalidated('shop.acme.com');
});

it('manages a fallback origin', function () {
    CloudflareSaasDomains::fake();

    expect(fn () => CloudflareSaasDomains::fallbackOrigin())->toThrow(ResourceNotFoundException::class);

    expect(CloudflareSaasDomains::setFallbackOrigin('fallback.saas.io')->status)->toBe(FallbackOriginStatus::Active)
        ->and(CloudflareSaasDomains::fallbackOrigin()->origin)->toBe('fallback.saas.io')
        ->and(CloudflareSaasDomains::deleteFallbackOrigin()->origin)->toBe('fallback.saas.io');

    expect(fn () => CloudflareSaasDomains::fallbackOrigin())->toThrow(ResourceNotFoundException::class);
});

it('raises not found for unknown hostnames', function () {
    (new FakeCustomHostnameClient)->verifyHostname('missing.acme.com');
})->throws(ResourceNotFoundException::class);

it('has assertions that really fail', function () {
    $fake = new FakeCustomHostnameClient;

    expect(fn () => $fake->assertCreated('x.acme.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertDeleted('x.acme.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertRevalidated('x.acme.com'))->toThrow(ExpectationFailedException::class);
});
