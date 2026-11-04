<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Validation;

use YourVendor\CloudflareSaasDomains\Exceptions\InvalidHostnameException;

/**
 * Turns user input into the one form that is stored and sent to Cloudflare:
 * lowercase ASCII, IDN labels in punycode, no trailing dot.
 */
final class HostnameNormalizer
{
    private const MAX_LENGTH = 253;

    /** Reserved by RFC 2606, RFC 6761 and friends, so they can never be publicly validated. */
    private const RESERVED_TLDS = ['example', 'internal', 'invalid', 'local', 'localhost', 'onion', 'test'];

    public static function normalize(string $input): string
    {
        $hostname = mb_strtolower(trim($input));

        if ($hostname === '') {
            throw new InvalidHostnameException('The hostname is empty.');
        }

        if (preg_match('/[\s\/\\\\:?#@*\[\]]/u', $hostname) === 1) {
            throw new InvalidHostnameException("\"{$input}\" is not a bare hostname. Remove any scheme, port, path or wildcard.");
        }

        $hostname = self::toAscii(str_ends_with($hostname, '.') ? substr($hostname, 0, -1) : $hostname, $input);

        if (strlen($hostname) > self::MAX_LENGTH) {
            throw new InvalidHostnameException("\"{$input}\" is longer than ".self::MAX_LENGTH.' characters.');
        }

        $labels = explode('.', $hostname);

        if (count($labels) < 2) {
            throw new InvalidHostnameException("\"{$input}\" is not a fully qualified hostname.");
        }

        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                throw new InvalidHostnameException("\"{$input}\" has an invalid label.");
            }
        }

        $tld = $labels[count($labels) - 1];

        if (ctype_digit($tld)) {
            throw new InvalidHostnameException('IP addresses are not valid custom hostnames.');
        }

        if (in_array($tld, self::RESERVED_TLDS, true)) {
            throw new InvalidHostnameException("\"{$input}\" uses the reserved TLD \".{$tld}\".");
        }

        return $hostname;
    }

    private static function toAscii(string $hostname, string $input): string
    {
        $ascii = idn_to_ascii($hostname, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false) {
            throw new InvalidHostnameException("\"{$input}\" is not a valid internationalized hostname.");
        }

        return strtolower($ascii);
    }
}
