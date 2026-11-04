<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Contracts;

use YourVendor\CloudflareSaasDomains\Data\CustomHostname;
use YourVendor\CloudflareSaasDomains\Data\CustomHostnamePage;
use YourVendor\CloudflareSaasDomains\Data\FallbackOrigin;
use YourVendor\CloudflareSaasDomains\Data\SslOptions;

interface CustomHostnameClient
{
    /**
     * @param  array<string, string>  $customMetadata
     */
    public function createHostname(string $hostname, SslOptions $ssl, array $customMetadata = []): CustomHostname;

    public function getHostname(string $id): CustomHostname;

    public function listHostnames(int $page = 1, int $perPage = 50, ?string $hostname = null): CustomHostnamePage;

    /**
     * Exact match only. Returns null when this zone has no such hostname.
     */
    public function findByHostname(string $hostname): ?CustomHostname;

    /**
     * Sends a no-change PATCH, which makes Cloudflare re-check validation now.
     */
    public function revalidateHostname(string $id, SslOptions $ssl): CustomHostname;

    public function deleteHostname(string $id): void;

    public function getFallbackOrigin(): FallbackOrigin;

    public function updateFallbackOrigin(string $origin): FallbackOrigin;

    public function deleteFallbackOrigin(): FallbackOrigin;
}
