<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Data;

use YourVendor\CloudflareSaasDomains\Enums\FallbackOriginStatus;
use YourVendor\CloudflareSaasDomains\Support\Payload;

final readonly class FallbackOrigin
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public ?string $origin,
        public FallbackOriginStatus $status,
        public array $errors = [],
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            origin: Payload::string($data, 'origin'),
            status: FallbackOriginStatus::parse(Payload::string($data, 'status')),
            errors: Payload::strings($data, 'errors'),
            createdAt: Payload::string($data, 'created_at'),
            updatedAt: Payload::string($data, 'updated_at'),
        );
    }
}
