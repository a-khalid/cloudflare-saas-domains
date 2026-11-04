<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;
use YourVendor\CloudflareSaasDomains\Tests\Fixtures\Merchant;
use YourVendor\CloudflareSaasDomains\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

const HOSTNAMES_URL = 'https://api.cloudflare.com/client/v4/zones/zone-123/custom_hostnames';

function merchant(string $name = 'Acme'): Merchant
{
    return Merchant::query()->create(['name' => $name]);
}

/**
 * A custom hostname in the documented API shape.
 *
 * @param  array<string, mixed>  $overrides
 * @param  array<string, mixed>  $ssl
 * @return array<string, mixed>
 */
function hostnamePayload(array $overrides = [], array $ssl = []): array
{
    return [
        'id' => 'cf-id-1',
        'hostname' => 'shop.acme.com',
        'status' => 'pending',
        'created_at' => '2026-01-01T00:00:00Z',
        'custom_metadata' => [],
        'verification_errors' => ['custom hostname does not CNAME to this zone.'],
        'ownership_verification' => [
            'type' => 'txt',
            'name' => '_cf-custom-hostname.shop.acme.com',
            'value' => 'ownership-token',
        ],
        'ownership_verification_http' => [
            'http_url' => 'http://shop.acme.com/.well-known/cf-custom-hostname-challenge/cf-id-1',
            'http_body' => 'ownership-token',
        ],
        'ssl' => [
            'id' => 'ssl-id-1',
            'status' => 'pending_validation',
            'method' => 'txt',
            'type' => 'dv',
            'bundle_method' => 'ubiquitous',
            'certificate_authority' => 'google',
            'wildcard' => false,
            'validation_errors' => [],
            'validation_records' => [[
                'status' => 'pending',
                'txt_name' => '_acme-challenge.shop.acme.com',
                'txt_value' => 'dcv-token',
            ]],
            'dcv_delegation_records' => [],
            ...$ssl,
        ],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>|list<mixed>  $result
 * @return array<string, mixed>
 */
function envelope(array $result): array
{
    return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];
}

/**
 * @return array<string, mixed>
 */
function errorEnvelope(int $code, string $message): array
{
    return ['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'messages' => [], 'result' => null];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function domainFor(Merchant $merchant, array $attributes = []): TenantDomain
{
    $domain = new TenantDomain([
        'hostname' => 'shop.acme.com',
        'cloudflare_id' => 'cf-id-1',
        'hostname_status' => 'pending',
        'ssl_status' => 'pending_validation',
        ...$attributes,
    ]);
    $domain->tenant()->associate($merchant);
    $domain->save();

    return $domain;
}

function httpFactory(): Factory
{
    return Http::getFacadeRoot();
}
