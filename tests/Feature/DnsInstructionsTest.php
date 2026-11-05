<?php

declare(strict_types=1);

use AKhalid\CloudflareSaasDomains\Dns\DnsInstructions;
use AKhalid\CloudflareSaasDomains\Dns\DnsRecord;
use AKhalid\CloudflareSaasDomains\Enums\DnsRecordPurpose;
use AKhalid\CloudflareSaasDomains\Exceptions\MissingConfigurationException;
use AKhalid\CloudflareSaasDomains\Facades\CloudflareSaasDomains;

function storedRecords(array $overrides = []): array
{
    return [
        'ownership' => ['type' => 'txt', 'name' => '_cf-custom-hostname.shop.acme.com', 'value' => 'own-token'],
        'ownership_http' => ['http_url' => 'http://x', 'http_body' => 'y'],
        'ssl_method' => 'txt',
        'ssl_validation' => [['txt_name' => '_acme-challenge.shop.acme.com', 'txt_value' => 'dcv-token', 'status' => 'pending']],
        'dcv_delegation' => [],
        ...$overrides,
    ];
}

it('asks for the CNAME, ownership TXT and SSL TXT on a new domain', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), ['verification_records' => storedRecords()]));

    $records = array_map(fn (DnsRecord $record) => [$record->type, $record->name, $record->value, $record->purpose], $instructions->required());

    expect($records)->toBe([
        ['CNAME', 'shop.acme.com', 'customers.saas.io', DnsRecordPurpose::Routing],
        ['TXT', '_cf-custom-hostname.shop.acme.com', 'own-token', DnsRecordPurpose::Ownership],
        ['TXT', '_acme-challenge.shop.acme.com', 'dcv-token', DnsRecordPurpose::SslValidation],
    ])->and($instructions->awaitingValidationRecords)->toBeFalse()
        ->and($instructions->complete)->toBeFalse();
});

it('asks for every certificate token, even under one name', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'hostname_status' => 'active',
        'verification_records' => storedRecords(['ssl_validation' => [
            ['txt_name' => '_acme-challenge.shop.acme.com', 'txt_value' => 'token-one', 'status' => 'pending'],
            ['txt_name' => '_acme-challenge.shop.acme.com', 'txt_value' => 'token-two', 'status' => 'pending'],
        ]]),
    ]));

    expect(array_map(fn (DnsRecord $record) => $record->value, $instructions->required()))->toBe(['token-one', 'token-two']);
});

it('drops the ownership TXT once the hostname is active', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'hostname_status' => 'active',
        'verification_records' => storedRecords(),
    ]));

    expect(array_map(fn (DnsRecord $record) => $record->purpose, $instructions->required()))->toBe([DnsRecordPurpose::SslValidation])
        ->and($instructions->records[0]->satisfied)->toBeTrue();
});

it('asks for nothing new once live, and keeps the CNAME visible', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'hostname_status' => 'active',
        'ssl_status' => 'active',
        'verification_records' => storedRecords(),
    ]));

    expect($instructions->required())->toBe([])
        ->and($instructions->complete)->toBeTrue()
        ->and($instructions->records)->toHaveCount(1)
        ->and($instructions->records[0]->type)->toBe('CNAME');
});

it('flags tokens that Cloudflare has not generated yet', function (?string $sslStatus, array $records) {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'ssl_status' => $sslStatus,
        'verification_records' => storedRecords($records),
    ]));

    expect($instructions->awaitingValidationRecords)->toBeTrue();
})->with([
    'pending, empty' => ['pending_validation', ['ssl_validation' => []]],
    'initializing' => ['initializing', ['ssl_validation' => []]],
    'no status yet' => [null, ['ssl_validation' => []]],
]);

it('is not awaiting tokens once the certificate moved on', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'ssl_status' => 'pending_issuance',
        'verification_records' => storedRecords(),
    ]));

    expect($instructions->awaitingValidationRecords)->toBeFalse();
});

it('asks for no SSL records when HTTP validation is used', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'verification_records' => storedRecords([
            'ssl_method' => 'http',
            'ssl_validation' => [['http_url' => 'http://x', 'http_body' => 'y']],
        ]),
    ]));

    expect(array_map(fn (DnsRecord $record) => $record->type, $instructions->required()))->toBe(['CNAME', 'TXT'])
        ->and($instructions->awaitingValidationRecords)->toBeFalse();
});

it('supports CNAME validation records', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'verification_records' => storedRecords([
            'ssl_validation' => [['cname' => '_acme-challenge.shop.acme.com', 'cname_target' => 'x.dcv.cloudflare.com']],
        ]),
    ]));

    expect($instructions->required()[2]->type)->toBe('CNAME')
        ->and($instructions->required()[2]->value)->toBe('x.dcv.cloudflare.com');
});

it('lists DCV delegation as optional', function () {
    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant(), [
        'verification_records' => storedRecords([
            'dcv_delegation' => [['cname' => '_acme-challenge.shop.acme.com', 'cname_target' => 'dcv.cloudflare.com']],
        ]),
    ]));

    expect($instructions->optional())->toHaveCount(1)
        ->and($instructions->optional()[0]->purpose)->toBe(DnsRecordPurpose::DcvDelegation)
        ->and($instructions->required())->toHaveCount(3);
});

it('falls back to the fallback origin for the CNAME target', function () {
    config()->set('cloudflare-saas-domains.cname_target', null);

    $instructions = CloudflareSaasDomains::instructions(domainFor(merchant()));

    expect($instructions->records[0]->value)->toBe('fallback.saas.io');
});

it('fails clearly when there is no CNAME target', function () {
    config()->set('cloudflare-saas-domains.cname_target', null);
    config()->set('cloudflare-saas-domains.fallback_origin', null);

    CloudflareSaasDomains::instructions(domainFor(merchant()));
})->throws(MissingConfigurationException::class);

it('serializes for an API response', function () {
    $instructions = DnsInstructions::for(domainFor(merchant(), ['verification_records' => storedRecords()]), 'customers.saas.io');

    $json = json_decode((string) json_encode($instructions), true);

    expect($json['hostname'])->toBe('shop.acme.com')
        ->and($json['complete'])->toBeFalse()
        ->and($json['awaiting_validation_records'])->toBeFalse()
        ->and($json['records'][0])->toBe([
            'type' => 'CNAME',
            'name' => 'shop.acme.com',
            'value' => 'customers.saas.io',
            'purpose' => 'routing',
            'required' => true,
            'satisfied' => false,
        ]);
});
