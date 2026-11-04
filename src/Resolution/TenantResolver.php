<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Resolution;

use AKhalid\CloudflareSaasDomains\Exceptions\InvalidHostnameException;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use AKhalid\CloudflareSaasDomains\Validation\HostnameNormalizer;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class TenantResolver
{
    private const MISS = ['miss' => true];

    public function __construct(
        private readonly Cache $cache,
        private readonly string $prefix = 'cf-domains:host:',
        private readonly int $ttl = 300,
        private readonly int $negativeTtl = 60,
    ) {}

    public function resolve(string $host): ?ResolvedTenant
    {
        try {
            $hostname = HostnameNormalizer::normalize($host);
        } catch (InvalidHostnameException) {
            return null;
        }

        $entry = $this->entry($hostname);

        if ($entry === null) {
            return null;
        }

        $tenant = $this->loadTenant($entry['type'], $entry['key']);

        if ($tenant === null) {
            $this->forget($hostname);

            return null;
        }

        return new ResolvedTenant($tenant, $entry['id'], $hostname);
    }

    public function forget(string $hostname): void
    {
        $this->cache->forget($this->key($hostname));
    }

    /**
     * @return array{id: int, type: string, key: int|string}|null
     */
    private function entry(string $hostname): ?array
    {
        $cached = $this->cache->get($this->key($hostname));

        if ($cached === self::MISS) {
            return null;
        }

        if (is_array($cached) && isset($cached['id'], $cached['type'], $cached['key'])) {
            /** @var array{id: int, type: string, key: int|string} $cached */
            return $cached;
        }

        $domain = TenantDomain::query()->resolvable()->where('hostname', $hostname)->first();

        if ($domain === null) {
            $this->cache->put($this->key($hostname), self::MISS, $this->negativeTtl);

            return null;
        }

        $entry = ['id' => $domain->id, 'type' => $domain->tenant_type, 'key' => $domain->tenant_id];

        $this->cache->put($this->key($hostname), $entry, $this->ttl);

        return $entry;
    }

    private function loadTenant(string $type, int|string $key): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($key);
    }

    /**
     * Hashed because the Host header is attacker-controlled.
     */
    private function key(string $hostname): string
    {
        return $this->prefix.sha1($hostname);
    }
}
