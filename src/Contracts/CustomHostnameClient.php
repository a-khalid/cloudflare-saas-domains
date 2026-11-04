<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Contracts;

use AKhalid\CloudflareSaasDomains\Data\CustomHostname;
use AKhalid\CloudflareSaasDomains\Data\CustomHostnamePage;
use AKhalid\CloudflareSaasDomains\Data\FallbackOrigin;
use AKhalid\CloudflareSaasDomains\Data\SslOptions;

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
