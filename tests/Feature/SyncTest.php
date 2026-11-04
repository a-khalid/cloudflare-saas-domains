<?php

declare(strict_types=1);

use AKhalid\CloudflareSaasDomains\Events\DomainVerified;
use AKhalid\CloudflareSaasDomains\Exceptions\AuthenticationFailedException;
use AKhalid\CloudflareSaasDomains\Jobs\SyncDomainsJob;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Support\DomainSynchronizer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

it('refreshes only pending domains', function () {
    Event::fake([DomainVerified::class]);
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active', 'verification_errors' => []])))]);
    $merchant = merchant();
    $pending = domainFor($merchant, ['hostname' => 'pending.acme.com', 'cloudflare_id' => 'a']);
    domainFor($merchant, ['hostname' => 'live.acme.com', 'cloudflare_id' => 'b', 'hostname_status' => 'active', 'ssl_status' => 'active']);
    domainFor($merchant, ['hostname' => 'deleted.acme.com', 'cloudflare_id' => 'c', 'hostname_status' => 'deleted']);
    domainFor($merchant, ['hostname' => 'unprovisioned.acme.com', 'cloudflare_id' => null]);
    domainFor($merchant, ['hostname' => 'trashed.acme.com', 'cloudflare_id' => 'd'])->delete();

    $this->artisan('cf-domains:sync')
        ->expectsOutputToContain('Checked 1 domain(s)')
        ->assertSuccessful();

    Http::assertSentCount(1);
    expect($pending->fresh()->hostname_status)->toBe('active');
    Event::assertDispatchedTimes(DomainVerified::class, 1);
});

it('includes hostnames that are active while the certificate is still pending', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active'])))]);
    domainFor(merchant(), ['hostname_status' => 'active', 'ssl_status' => 'pending_issuance']);

    $this->artisan('cf-domains:sync')->assertSuccessful();

    Http::assertSentCount(1);
});

it('includes domains in a failed state, since they can recover', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    domainFor(merchant(), ['hostname_status' => 'moved']);

    $this->artisan('cf-domains:sync')->assertSuccessful();

    Http::assertSentCount(1);
});

it('respects the polling interval', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    config()->set('cloudflare-saas-domains.polling_interval', 300);
    $merchant = merchant();
    domainFor($merchant, ['hostname' => 'recent.acme.com', 'cloudflare_id' => 'a', 'last_checked_at' => now()->subSeconds(100)]);
    domainFor($merchant, ['hostname' => 'old.acme.com', 'cloudflare_id' => 'b', 'last_checked_at' => now()->subSeconds(400)]);

    $this->artisan('cf-domains:sync')->expectsOutputToContain('Checked 1 domain(s)');

    Http::assertSentCount(1);
});

it('does not poll the same domain twice within the interval', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    domainFor(merchant());

    $this->artisan('cf-domains:sync');
    $this->artisan('cf-domains:sync');

    Http::assertSentCount(1);
});

it('also checks live domains with --all', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active'], ['status' => 'active'])))]);
    $merchant = merchant();
    domainFor($merchant, ['hostname' => 'live.acme.com', 'cloudflare_id' => 'b', 'hostname_status' => 'active', 'ssl_status' => 'active']);
    domainFor($merchant, ['hostname' => 'gone.acme.com', 'cloudflare_id' => 'c', 'hostname_status' => 'deleted']);

    $this->artisan('cf-domains:sync --all')->expectsOutputToContain('Checked 1 domain(s)')->assertSuccessful();
});

it('caps a run with --limit and checks the oldest first', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $merchant = merchant();
    $old = domainFor($merchant, ['hostname' => 'a.acme.com', 'cloudflare_id' => 'a', 'last_checked_at' => now()->subHour()]);
    $never = domainFor($merchant, ['hostname' => 'b.acme.com', 'cloudflare_id' => 'b']);
    $older = domainFor($merchant, ['hostname' => 'c.acme.com', 'cloudflare_id' => 'c', 'last_checked_at' => now()->subDay()]);

    $this->artisan('cf-domains:sync --limit=2')->expectsOutputToContain('Checked 2 domain(s)');

    expect($never->fresh()->last_checked_at->isToday())->toBeTrue()
        ->and($older->fresh()->last_checked_at->diffInMinutes(now()))->toBeLessThan(1)
        ->and($old->fresh()->last_checked_at->diffInMinutes(now()))->toBeGreaterThan(30);
});

it('caps a run with the configured batch size', function () {
    config()->set('cloudflare-saas-domains.sync.batch_size', 1);
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);
    $merchant = merchant();
    domainFor($merchant, ['hostname' => 'a.acme.com', 'cloudflare_id' => 'a']);
    domainFor($merchant, ['hostname' => 'b.acme.com', 'cloudflare_id' => 'b']);

    $this->artisan('cf-domains:sync');

    Http::assertSentCount(1);
});

it('keeps going past one failing domain, reports it, and exits non-zero', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Log::spy();
    $merchant = merchant();
    domainFor($merchant, ['hostname' => 'bad.acme.com', 'cloudflare_id' => 'bad']);
    $good = domainFor($merchant, ['hostname' => 'good.acme.com', 'cloudflare_id' => 'good']);

    Http::fake([
        '*/custom_hostnames/bad' => Http::response(errorEnvelope(1, 'down'), 500),
        '*/custom_hostnames/good' => Http::response(envelope(hostnamePayload(['status' => 'active']))),
    ]);

    $this->artisan('cf-domains:sync')
        ->expectsOutputToContain('Checked 1 domain(s)')
        ->expectsOutputToContain('bad.acme.com')
        ->assertFailed();

    expect($good->fresh()->hostname_status)->toBe('active');
});

it('ends the run early on a rate limit and exits non-zero', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response(errorEnvelope(1, 'slow down'), 429)]);
    $merchant = merchant();
    domainFor($merchant, ['hostname' => 'a.acme.com', 'cloudflare_id' => 'a']);
    domainFor($merchant, ['hostname' => 'b.acme.com', 'cloudflare_id' => 'b']);

    $this->artisan('cf-domains:sync')
        ->expectsOutputToContain('rate limit')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('stops on bad credentials rather than failing every domain', function () {
    Http::fake(['*' => Http::response(errorEnvelope(10000, 'Authentication error'), 401)]);
    $merchant = merchant();
    domainFor($merchant, ['hostname' => 'a.acme.com', 'cloudflare_id' => 'a']);
    domainFor($merchant, ['hostname' => 'b.acme.com', 'cloudflare_id' => 'b']);

    $this->artisan('cf-domains:sync')
        ->expectsOutputToContain('Authentication error')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('dispatches a queued job with --queue', function () {
    Bus::fake();
    config()->set('cloudflare-saas-domains.sync.queue', 'domains');

    $this->artisan('cf-domains:sync --queue --all --limit=5')->assertSuccessful();

    Bus::assertDispatched(SyncDomainsJob::class, fn (SyncDomainsJob $job) => $job->all && $job->limit === 5 && $job->queue === 'domains');
});

it('does not stack queued jobs when the command runs repeatedly', function () {
    Queue::fake();

    $this->artisan('cf-domains:sync --queue')->assertSuccessful();
    $this->artisan('cf-domains:sync --queue')->assertSuccessful();

    Queue::assertPushed(SyncDomainsJob::class, 1);
});

it('runs the sync from the queued job', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload(['status' => 'active'])))]);
    $domain = domainFor(merchant());

    SyncDomainsJob::dispatch();

    expect($domain->fresh()->hostname_status)->toBe('active');
});

it('lets the job fail on bad credentials', function () {
    Http::fake(['*' => Http::response(errorEnvelope(10000, 'Authentication error'), 401)]);
    domainFor(merchant());

    app(DomainSynchronizer::class)->sync();
})->throws(AuthenticationFailedException::class);

it('keeps pending and all jobs unique separately', function () {
    expect((new SyncDomainsJob)->uniqueId())->toBe('pending')
        ->and((new SyncDomainsJob(all: true))->uniqueId())->toBe('all');
});

it('uses the configured table name', function () {
    config()->set('cloudflare-saas-domains.table', 'custom_table');

    expect((new TenantDomain)->getTable())->toBe('custom_table');
});
