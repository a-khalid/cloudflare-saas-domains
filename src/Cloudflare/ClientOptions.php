<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Cloudflare;

use YourVendor\CloudflareSaasDomains\Support\Payload;

final readonly class ClientOptions
{
    public function __construct(
        public ?string $apiToken,
        public ?string $zoneId,
        public string $baseUrl = 'https://api.cloudflare.com/client/v4',
        public int $timeout = 15,
        public int $connectTimeout = 5,
        public int $retries = 3,
        public int $retryBaseMs = 500,
        public int $retryMaxMs = 10000,
        public int $retryJitterMs = 250,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $http = Payload::array($config, 'http') ?? [];

        return new self(
            apiToken: Payload::string($config, 'api_token'),
            zoneId: Payload::string($config, 'zone_id'),
            baseUrl: rtrim(Payload::string($http, 'base_url') ?? 'https://api.cloudflare.com/client/v4', '/'),
            timeout: Payload::int($http, 'timeout', 15),
            connectTimeout: Payload::int($http, 'connect_timeout', 5),
            retries: max(0, Payload::int($http, 'retries', 3)),
            retryBaseMs: max(0, Payload::int($http, 'retry_base_ms', 500)),
            retryMaxMs: max(0, Payload::int($http, 'retry_max_ms', 10000)),
            retryJitterMs: max(0, Payload::int($http, 'retry_jitter_ms', 250)),
        );
    }
}
