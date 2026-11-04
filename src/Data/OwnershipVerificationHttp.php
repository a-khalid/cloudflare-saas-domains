<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Data;

use YourVendor\CloudflareSaasDomains\Support\Payload;

final readonly class OwnershipVerificationHttp
{
    public function __construct(
        public string $httpUrl,
        public string $httpBody,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $url = $data === null ? null : Payload::string($data, 'http_url');
        $body = $data === null ? null : Payload::string($data, 'http_body');

        return $url === null || $body === null ? null : new self($url, $body);
    }

    /**
     * @return array{http_url: string, http_body: string}
     */
    public function toArray(): array
    {
        return ['http_url' => $this->httpUrl, 'http_body' => $this->httpBody];
    }
}
