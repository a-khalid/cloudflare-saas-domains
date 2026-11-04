<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Support;

/**
 * Typed reads from decoded JSON. Cloudflare leaves fields out freely, so absent
 * and wrongly typed values both read as null or empty.
 *
 * @internal
 */
final class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    public static function array(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function arrays(array $data, string $key): array
    {
        return array_values(array_filter(self::array($data, $key) ?? [], is_array(...)));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        return array_values(array_filter(self::array($data, $key) ?? [], is_string(...)));
    }
}
