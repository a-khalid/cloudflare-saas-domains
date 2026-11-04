<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Data;

use AKhalid\CloudflareSaasDomains\Enums\HostnameStatus;
use AKhalid\CloudflareSaasDomains\Exceptions\InvalidResponseException;
use AKhalid\CloudflareSaasDomains\Support\Payload;

final readonly class CustomHostname
{
    /**
     * @param  list<string>  $verificationErrors
     * @param  array<array-key, mixed>  $customMetadata
     */
    public function __construct(
        public string $id,
        public string $hostname,
        public HostnameStatus $status,
        public string $rawStatus,
        public ?SslDetails $ssl = null,
        public ?OwnershipVerification $ownershipVerification = null,
        public ?OwnershipVerificationHttp $ownershipVerificationHttp = null,
        public array $verificationErrors = [],
        public array $customMetadata = [],
        public ?string $createdAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = Payload::string($data, 'id');
        $hostname = Payload::string($data, 'hostname');

        if ($id === null || $hostname === null) {
            throw new InvalidResponseException('Cloudflare returned a custom hostname without an id or hostname.');
        }

        $status = Payload::string($data, 'status') ?? HostnameStatus::Pending->value;
        $ssl = Payload::array($data, 'ssl');

        return new self(
            id: $id,
            hostname: $hostname,
            status: HostnameStatus::parse($status),
            rawStatus: $status,
            ssl: $ssl === null ? null : SslDetails::fromArray($ssl),
            ownershipVerification: OwnershipVerification::fromArray(Payload::array($data, 'ownership_verification')),
            ownershipVerificationHttp: OwnershipVerificationHttp::fromArray(Payload::array($data, 'ownership_verification_http')),
            verificationErrors: Payload::strings($data, 'verification_errors'),
            customMetadata: Payload::array($data, 'custom_metadata') ?? [],
            createdAt: Payload::string($data, 'created_at'),
        );
    }

    /**
     * Every error Cloudflare currently reports for this hostname.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return [...$this->verificationErrors, ...($this->ssl->validationErrors ?? [])];
    }

    /**
     * The shape stored in TenantDomain::$verification_records.
     *
     * @return array<string, mixed>
     */
    public function verificationRecords(): array
    {
        return [
            'ownership' => $this->ownershipVerification?->toArray(),
            'ownership_http' => $this->ownershipVerificationHttp?->toArray(),
            'ssl_method' => $this->ssl?->method,
            'ssl_validation' => array_map(
                static fn (ValidationRecord $record): array => $record->toArray(),
                $this->ssl->validationRecords ?? [],
            ),
            'dcv_delegation' => array_map(
                static fn (ValidationRecord $record): array => $record->toArray(),
                $this->ssl->dcvDelegationRecords ?? [],
            ),
        ];
    }
}
