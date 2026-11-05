<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Data;

use AKhalid\CloudflareSaasDomains\Support\Payload;

/** The ssl object sent on create and on re-validation. */
final readonly class SslOptions
{
    public function __construct(
        public string $method = 'txt',
        public string $type = 'dv',
        public ?string $bundleMethod = null,
        public ?string $certificateAuthority = null,
        public ?bool $wildcard = null,
        public ?string $minTlsVersion = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            method: Payload::string($config, 'method') ?? 'txt',
            type: Payload::string($config, 'type') ?? 'dv',
            bundleMethod: Payload::string($config, 'bundle_method'),
            certificateAuthority: Payload::string($config, 'certificate_authority'),
            wildcard: Payload::bool($config, 'wildcard'),
            minTlsVersion: Payload::string($config, 'min_tls_version'),
        );
    }

    /**
     * Cloudflare re-checks validation when the PATCH repeats the hostname's method
     * and type. Nothing else is copied: the live API answers 400 (code 1459) to
     * certificate_authority below the Enterprise plan, and sending wildcard moved an
     * active hostname to active_redeploying.
     */
    public static function matching(SslDetails $details, self $defaults): self
    {
        return new self(
            method: $details->method ?? $defaults->method,
            type: $details->type ?? $defaults->type,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'method' => $this->method,
            'type' => $this->type,
            'bundle_method' => $this->bundleMethod,
            'certificate_authority' => $this->certificateAuthority,
            'wildcard' => $this->wildcard,
            'settings' => $this->minTlsVersion === null ? null : ['min_tls_version' => $this->minTlsVersion],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
