<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Data;

use YourVendor\CloudflareSaasDomains\Support\Payload;

/** One certificate validation (DCV) record. Drives the SSL status. */
final readonly class ValidationRecord
{
    /**
     * @param  list<string>  $emails
     */
    public function __construct(
        public ?string $txtName = null,
        public ?string $txtValue = null,
        public ?string $cname = null,
        public ?string $cnameTarget = null,
        public ?string $httpUrl = null,
        public ?string $httpBody = null,
        public array $emails = [],
        public ?string $status = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            txtName: Payload::string($data, 'txt_name'),
            txtValue: Payload::string($data, 'txt_value'),
            cname: Payload::string($data, 'cname'),
            cnameTarget: Payload::string($data, 'cname_target'),
            httpUrl: Payload::string($data, 'http_url'),
            httpBody: Payload::string($data, 'http_body'),
            emails: Payload::strings($data, 'emails'),
            status: Payload::string($data, 'status'),
        );
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function toArray(): array
    {
        return array_filter([
            'txt_name' => $this->txtName,
            'txt_value' => $this->txtValue,
            'cname' => $this->cname,
            'cname_target' => $this->cnameTarget,
            'http_url' => $this->httpUrl,
            'http_body' => $this->httpBody,
            'emails' => $this->emails,
            'status' => $this->status,
        ], static fn (string|array|null $value): bool => $value !== null && $value !== []);
    }
}
