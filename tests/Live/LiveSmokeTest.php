<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Http\Client\Factory;
use YourVendor\CloudflareSaasDomains\Cloudflare\ClientOptions;
use YourVendor\CloudflareSaasDomains\Cloudflare\CloudflareClient;
use YourVendor\CloudflareSaasDomains\Data\SslOptions;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;

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

it('creates, reads back and deletes a hostname on a test zone', function () {
    $missing = array_filter(
        ['CF_LIVE_API_TOKEN', 'CF_LIVE_ZONE_ID', 'CF_LIVE_HOSTNAME_DOMAIN'],
        fn (string $key) => liveValue($key) === null,
    );

    if (liveValue('CF_LIVE_TEST') !== '1' || $missing !== []) {
        $this->markTestSkipped('Set CF_LIVE_TEST=1 and put real credentials in .env.testing to run this.');
    }

    $client = new CloudflareClient(new Factory, new ClientOptions(
        apiToken: liveValue('CF_LIVE_API_TOKEN'),
        zoneId: liveValue('CF_LIVE_ZONE_ID'),
    ));

    $hostname = 'cf-saas-domains-smoke-'.bin2hex(random_bytes(4)).'.'.liveValue('CF_LIVE_HOSTNAME_DOMAIN');
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
