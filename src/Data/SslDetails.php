<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Data;

use YourVendor\CloudflareSaasDomains\Enums\SslStatus;
use YourVendor\CloudflareSaasDomains\Support\Payload;

final readonly class SslDetails
{
    /**
     * @param  list<ValidationRecord>  $validationRecords
     * @param  list<ValidationRecord>  $dcvDelegationRecords
     * @param  list<string>  $validationErrors
     */
    public function __construct(
        public SslStatus $status,
        public ?string $rawStatus,
        public ?string $id = null,
        public ?string $method = null,
        public ?string $type = null,
        public ?string $bundleMethod = null,
        public ?string $certificateAuthority = null,
        public ?bool $wildcard = null,
        public ?string $expiresOn = null,
        public array $validationRecords = [],
        public array $dcvDelegationRecords = [],
        public array $validationErrors = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $raw = Payload::string($data, 'status');

        return new self(
            status: SslStatus::parse($raw),
            rawStatus: $raw,
            id: Payload::string($data, 'id'),
            method: Payload::string($data, 'method'),
            type: Payload::string($data, 'type'),
            bundleMethod: Payload::string($data, 'bundle_method'),
            certificateAuthority: Payload::string($data, 'certificate_authority'),
            wildcard: Payload::bool($data, 'wildcard'),
            expiresOn: Payload::string($data, 'expires_on'),
            validationRecords: array_map(ValidationRecord::fromArray(...), Payload::arrays($data, 'validation_records')),
            dcvDelegationRecords: array_map(ValidationRecord::fromArray(...), Payload::arrays($data, 'dcv_delegation_records')),
            validationErrors: array_values(array_filter(array_map(
                static fn (array $error): ?string => Payload::string($error, 'message'),
                Payload::arrays($data, 'validation_errors'),
            ))),
        );
    }
}
