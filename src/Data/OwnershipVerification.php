<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Data;

use YourVendor\CloudflareSaasDomains\Support\Payload;

/** The TXT record that proves hostname ownership. Drives the hostname status. */
final readonly class OwnershipVerification
{
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $name = $data === null ? null : Payload::string($data, 'name');
        $value = $data === null ? null : Payload::string($data, 'value');

        if ($data === null || $name === null || $value === null) {
            return null;
        }

        return new self(Payload::string($data, 'type') ?? 'txt', $name, $value);
    }

    /**
     * @return array{type: string, name: string, value: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'name' => $this->name, 'value' => $this->value];
    }
}
