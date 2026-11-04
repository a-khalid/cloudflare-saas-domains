<?php

declare(strict_types=1);

use AKhalid\CloudflareSaasDomains\Data\CustomHostname;
use AKhalid\CloudflareSaasDomains\Data\FallbackOrigin;
use AKhalid\CloudflareSaasDomains\Data\SslDetails;
use AKhalid\CloudflareSaasDomains\Data\SslOptions;
use AKhalid\CloudflareSaasDomains\Data\ValidationRecord;
use AKhalid\CloudflareSaasDomains\Enums\FallbackOriginStatus;
use AKhalid\CloudflareSaasDomains\Enums\HostnameStatus;
use AKhalid\CloudflareSaasDomains\Enums\SslStatus;
use AKhalid\CloudflareSaasDomains\Exceptions\InvalidResponseException;

it('knows every documented hostname status', function () {
    $documented = ['active', 'pending', 'active_redeploying', 'moved', 'pending_deletion', 'deleted', 'pending_blocked', 'pending_migration', 'pending_provisioned', 'test_pending', 'test_active', 'test_active_apex', 'test_blocked', 'test_failed', 'provisioned', 'blocked'];

    foreach ($documented as $value) {
        expect(HostnameStatus::parse($value)->value)->toBe($value);
    }

    expect(HostnameStatus::cases())->toHaveCount(count($documented) + 1);
});

it('knows every documented SSL status', function () {
    $documented = ['initializing', 'pending_validation', 'deleted', 'pending_issuance', 'pending_deployment', 'pending_deletion', 'pending_expiration', 'expired', 'active', 'initializing_timed_out', 'validation_timed_out', 'issuance_timed_out', 'deployment_timed_out', 'deletion_timed_out', 'pending_cleanup', 'staging_deployment', 'staging_active', 'deactivating', 'inactive', 'backup_issued', 'holding_deployment'];

    foreach ($documented as $value) {
        expect(SslStatus::parse($value)->value)->toBe($value);
    }

    expect(SslStatus::cases())->toHaveCount(count($documented) + 1);
});

it('knows every documented fallback origin status', function () {
    foreach (['initializing', 'pending_deployment', 'pending_deletion', 'active', 'deployment_timed_out', 'deletion_timed_out'] as $value) {
        expect(FallbackOriginStatus::parse($value)->value)->toBe($value);
    }
});

it('maps new Cloudflare values to Unknown instead of crashing', function () {
    expect(HostnameStatus::parse('brand_new'))->toBe(HostnameStatus::Unknown)
        ->and(SslStatus::parse('brand_new'))->toBe(SslStatus::Unknown)
        ->and(FallbackOriginStatus::parse('brand_new'))->toBe(FallbackOriginStatus::Unknown)
        ->and(HostnameStatus::parse(null))->toBe(HostnameStatus::Unknown)
        ->and(HostnameStatus::Unknown->isActive())->toBeFalse()
        ->and(HostnameStatus::Unknown->isFailed())->toBeFalse()
        ->and(SslStatus::Unknown->isServing())->toBeFalse()
        ->and(SslStatus::Unknown->isFailed())->toBeFalse();
});

it('classifies statuses', function () {
    expect(HostnameStatus::Active->isActive())->toBeTrue()
        ->and(HostnameStatus::ActiveRedeploying->isActive())->toBeTrue()
        ->and(HostnameStatus::Pending->isActive())->toBeFalse()
        ->and(HostnameStatus::Blocked->isFailed())->toBeTrue()
        ->and(HostnameStatus::Pending->isFailed())->toBeFalse()
        ->and(SslStatus::Active->isServing())->toBeTrue()
        ->and(SslStatus::PendingExpiration->isServing())->toBeTrue()
        ->and(SslStatus::PendingIssuance->isRenewing())->toBeTrue()
        ->and(SslStatus::ValidationTimedOut->isFailed())->toBeTrue();
});

it('parses the documented hostname shape into typed objects', function () {
    $hostname = CustomHostname::fromArray(hostnamePayload(['custom_metadata' => ['a' => 'b']], [
        'expires_on' => '2027-01-01T00:00:00Z',
        'validation_errors' => [['message' => 'SERVFAIL looking up CAA']],
        'dcv_delegation_records' => [['cname' => '_acme-challenge.shop.acme.com', 'cname_target' => 'dcv.cloudflare.com']],
    ]));

    expect($hostname->id)->toBe('cf-id-1')
        ->and($hostname->status)->toBe(HostnameStatus::Pending)
        ->and($hostname->createdAt)->toBe('2026-01-01T00:00:00Z')
        ->and($hostname->customMetadata)->toBe(['a' => 'b'])
        ->and($hostname->ownershipVerification?->name)->toBe('_cf-custom-hostname.shop.acme.com')
        ->and($hostname->ownershipVerificationHttp?->httpBody)->toBe('ownership-token')
        ->and($hostname->ssl?->status)->toBe(SslStatus::PendingValidation)
        ->and($hostname->ssl?->certificateAuthority)->toBe('google')
        ->and($hostname->ssl?->expiresOn)->toBe('2027-01-01T00:00:00Z')
        ->and($hostname->ssl?->validationRecords[0]->txtValue)->toBe('dcv-token')
        ->and($hostname->ssl?->dcvDelegationRecords[0]->cnameTarget)->toBe('dcv.cloudflare.com')
        ->and($hostname->errors())->toBe(['custom hostname does not CNAME to this zone.', 'SERVFAIL looking up CAA']);
});

it('tolerates missing optional fields', function () {
    $hostname = CustomHostname::fromArray(['id' => 'x', 'hostname' => 'a.b.com']);

    expect($hostname->ssl)->toBeNull()
        ->and($hostname->ownershipVerification)->toBeNull()
        ->and($hostname->ownershipVerificationHttp)->toBeNull()
        ->and($hostname->status)->toBe(HostnameStatus::Pending)
        ->and($hostname->errors())->toBe([])
        ->and($hostname->verificationRecords()['ssl_validation'])->toBe([]);
});

it('refuses a hostname without an id', function () {
    CustomHostname::fromArray(['hostname' => 'a.b.com']);
})->throws(InvalidResponseException::class);

it('parses fallback origins', function () {
    $origin = FallbackOrigin::fromArray([
        'origin' => 'fallback.saas.io',
        'status' => 'pending_deployment',
        'errors' => ['DNS records are not setup correctly.'],
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-02T00:00:00Z',
    ]);

    expect($origin->status)->toBe(FallbackOriginStatus::PendingDeployment)
        ->and($origin->errors)->toHaveCount(1)
        ->and($origin->updatedAt)->toBe('2026-01-02T00:00:00Z');
});

it('sends only the SSL options that are set', function () {
    expect((new SslOptions)->toArray())->toBe(['method' => 'txt', 'type' => 'dv'])
        ->and(SslOptions::fromConfig(['method' => 'http', 'type' => 'dv', 'min_tls_version' => '1.2', 'bundle_method' => null])->toArray())
        ->toBe(['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']]);
});

it('builds re-validation options from the existing certificate', function () {
    $details = new SslDetails(SslStatus::PendingValidation, 'pending_validation', method: 'http', type: 'dv', bundleMethod: 'force', certificateAuthority: 'lets_encrypt', wildcard: false);

    expect(SslOptions::matching($details, new SslOptions)->toArray())->toBe([
        'method' => 'http',
        'type' => 'dv',
        'bundle_method' => 'force',
        'certificate_authority' => 'lets_encrypt',
        'wildcard' => false,
    ]);
});

it('drops empty fields when storing a validation record', function () {
    expect((new ValidationRecord(txtName: 'a', txtValue: 'b'))->toArray())->toBe(['txt_name' => 'a', 'txt_value' => 'b']);
});
