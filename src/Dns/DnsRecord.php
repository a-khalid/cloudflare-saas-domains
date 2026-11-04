<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Dns;

use JsonSerializable;
use YourVendor\CloudflareSaasDomains\Enums\DnsRecordPurpose;

final readonly class DnsRecord implements JsonSerializable
{
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
        public DnsRecordPurpose $purpose,
        public bool $required = true,
        public bool $satisfied = false,
    ) {}

    /**
     * @return array{type: string, name: string, value: string, purpose: string, required: bool, satisfied: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'value' => $this->value,
            'purpose' => $this->purpose->value,
            'required' => $this->required,
            'satisfied' => $this->satisfied,
        ];
    }

    /**
     * @return array{type: string, name: string, value: string, purpose: string, required: bool, satisfied: bool}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
