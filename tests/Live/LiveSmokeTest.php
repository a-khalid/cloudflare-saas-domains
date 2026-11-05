<?php

declare(strict_types=1);

use AKhalid\CloudflareSaasDomains\Cloudflare\ClientOptions;
use AKhalid\CloudflareSaasDomains\Cloudflare\CloudflareClient;
use AKhalid\CloudflareSaasDomains\Data\SslOptions;
use AKhalid\CloudflareSaasDomains\Dns\DnsRecord;
use AKhalid\CloudflareSaasDomains\Enums\DnsRecordPurpose;
use AKhalid\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use AKhalid\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use AKhalid\CloudflareSaasDomains\Facades\CloudflareSaasDomains;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use Dotenv\Dotenv;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/*
 * Talks to the real Cloudflare API and changes a real zone, so it only runs when
 * CF_LIVE_TEST=1 and credentials are in .env.testing. See .env.testing.example.
 *
 *     CF_LIVE_TEST=1 vendor/bin/pest tests/Live
 */

function liveEnv(): array
{
    $file = dirname(__DIR__, 2).'/.env.testing';

    return is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
}

function liveValue(string $key): ?string
{
    $value = getenv($key) ?: (liveEnv()[$key] ?? null);

    return $value === '' ? null : $value;
}

beforeEach(function () {
    $missing = array_filter(
        ['CF_LIVE_API_TOKEN', 'CF_LIVE_ZONE_ID', 'CF_LIVE_HOSTNAME_DOMAIN'],
        fn (string $key) => liveValue($key) === null,
    );

    if (liveValue('CF_LIVE_TEST') !== '1' || $missing !== []) {
        $this->markTestSkipped('Set CF_LIVE_TEST=1 and put real credentials in .env.testing to run this.');
    }
});

function liveClient(): CloudflareClient
{
    return new CloudflareClient(new Factory, new ClientOptions(
        apiToken: liveValue('CF_LIVE_API_TOKEN'),
        zoneId: liveValue('CF_LIVE_ZONE_ID'),
    ));
}

function liveHostname(): string
{
    return 'cf-saas-domains-smoke-'.bin2hex(random_bytes(4)).'.'.liveValue('CF_LIVE_HOSTNAME_DOMAIN');
}

it('creates, reads back and deletes a hostname on a test zone', function () {
    $client = liveClient();
    $hostname = liveHostname();
    $created = $client->createHostname($hostname, new SslOptions('txt', 'dv'));

    try {
        expect($created->hostname)->toBe($hostname);

        $read = $client->getHostname($created->id);

        expect($read->id)->toBe($created->id)
            ->and($read->hostname)->toBe($hostname)
            ->and($read->ssl)->not->toBeNull();
    } finally {
        $client->deleteHostname($created->id);
    }

    expect(fn () => $client->getHostname($created->id))->toThrow(ResourceNotFoundException::class);
})->group('live');

it('matches the API on lookups, duplicates, re-validation and missing hostnames', function () {
    $client = liveClient();
    $hostname = liveHostname();
    $created = $client->createHostname($hostname, new SslOptions('txt', 'dv'));

    try {
        expect($client->findByHostname($hostname)?->id)->toBe($created->id)
            ->and($client->findByHostname(substr($hostname, 1)))->toBeNull();

        try {
            $client->createHostname($hostname, new SslOptions('txt', 'dv'));
            $this->fail('Expected a duplicate error.');
        } catch (DuplicateHostnameException $exception) {
            expect($exception->statusCode)->toBe(409)
                ->and($exception->errors[0]->code)->toBe(1406);
        }

        $ssl = $client->getHostname($created->id)->ssl;

        expect($ssl)->not->toBeNull()
            ->and($client->revalidateHostname($created->id, SslOptions::matching($ssl, new SslOptions))->id)->toBe($created->id);
    } finally {
        $client->deleteHostname($created->id);
    }

    expect(fn () => $client->deleteHostname($created->id))->toThrow(ResourceNotFoundException::class);
})->group('live');

it('runs the package actions against the API', function () {
    Http::allowStrayRequests();
    config()->set('cloudflare-saas-domains.api_token', liveValue('CF_LIVE_API_TOKEN'));
    config()->set('cloudflare-saas-domains.zone_id', liveValue('CF_LIVE_ZONE_ID'));

    $merchant = merchant();
    $hostname = liveHostname();
    $domain = CloudflareSaasDomains::add($merchant, $hostname);

    try {
        expect($domain->cloudflare_id)->not->toBeNull();

        // Certificate tokens arrive asynchronously, so give Cloudflare a moment.
        for ($attempt = 0; $attempt < 10 && $domain->ssl_status === 'initializing'; $attempt++) {
            sleep(3);
            CloudflareSaasDomains::refresh($domain);
        }

        $purposes = array_map(
            fn (DnsRecord $record) => $record->purpose,
            CloudflareSaasDomains::instructions($domain)->required(),
        );

        expect($domain->ssl_status)->toBe('pending_validation')
            ->and($purposes)->toContain(DnsRecordPurpose::SslValidation);

        CloudflareSaasDomains::retryValidation($domain);

        // A row lost after Cloudflare created the hostname: adding again adopts it.
        $id = $domain->cloudflare_id;
        TenantDomain::query()->whereKey($domain->id)->forceDelete();
        $domain = CloudflareSaasDomains::add($merchant, $hostname);

        expect($domain->cloudflare_id)->toBe($id);
    } finally {
        CloudflareSaasDomains::remove($domain);
    }

    expect($domain->trashed())->toBeTrue()
        ->and(fn () => liveClient()->getHostname((string) $domain->cloudflare_id))->toThrow(ResourceNotFoundException::class);
})->group('live');
