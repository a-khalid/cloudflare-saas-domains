<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Validation;

use AKhalid\CloudflareSaasDomains\Exceptions\ApexDomainNotAllowedException;
use AKhalid\CloudflareSaasDomains\Exceptions\BlockedHostnameException;
use AKhalid\CloudflareSaasDomains\Exceptions\InvalidHostnameException;

final class HostnameValidator
{
    /**
     * Common multi-part public suffixes. Deliberately not the full Public Suffix
     * List, see README limitations. Extend it with validation.public_suffixes.
     */
    private const MULTI_PART_SUFFIXES = [
        'ac.uk', 'co.uk', 'gov.uk', 'ltd.uk', 'me.uk', 'net.uk', 'org.uk', 'plc.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz',
        'co.za', 'net.za', 'org.za', 'web.za',
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in',
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'ad.jp', 'go.jp',
        'co.kr', 'or.kr', 'ne.kr',
        'com.cn', 'net.cn', 'org.cn', 'gov.cn',
        'com.hk', 'net.hk', 'org.hk',
        'com.tw', 'net.tw', 'org.tw',
        'com.sg', 'net.sg', 'org.sg',
        'com.my', 'net.my', 'org.my',
        'co.id', 'or.id', 'web.id',
        'co.th', 'in.th', 'or.th',
        'com.ph', 'net.ph', 'org.ph',
        'com.vn', 'net.vn', 'org.vn',
        'com.pk', 'net.pk', 'org.pk',
        'com.bd', 'net.bd', 'org.bd',
        'com.br', 'net.br', 'org.br',
        'com.mx', 'net.mx', 'org.mx',
        'com.ar', 'net.ar', 'org.ar',
        'com.co', 'net.co', 'nom.co',
        'com.pe', 'net.pe', 'org.pe',
        'com.tr', 'net.tr', 'org.tr', 'web.tr',
        'com.ua', 'net.ua', 'org.ua',
        'co.il', 'org.il', 'net.il',
        'com.sa', 'net.sa', 'org.sa', 'med.sa', 'sch.sa', 'edu.sa', 'gov.sa',
        'com.eg', 'net.eg', 'org.eg', 'edu.eg', 'gov.eg',
        'com.kw', 'net.kw', 'org.kw', 'edu.kw',
        'com.qa', 'net.qa', 'org.qa', 'edu.qa',
        'com.bh', 'net.bh', 'org.bh', 'edu.bh',
        'com.om', 'net.om', 'org.om', 'co.om', 'edu.om',
        'ae.org', 'co.ae', 'net.ae', 'org.ae', 'sch.ae', 'ac.ae', 'gov.ae',
        'com.jo', 'net.jo', 'org.jo',
        'com.lb', 'net.lb', 'org.lb',
        'com.iq', 'net.iq', 'org.iq',
        'com.ly', 'net.ly', 'org.ly',
        'com.ma', 'net.ma', 'org.ma', 'co.ma',
        'com.tn', 'net.tn', 'org.tn',
        'com.dz', 'net.dz', 'org.dz',
        'com.sd', 'net.sd', 'org.sd',
        'com.ng', 'net.ng', 'org.ng',
        'co.ke', 'or.ke', 'ne.ke',
        'com.gh', 'net.gh', 'org.gh',
        'com.es', 'org.es', 'nom.es',
        'com.pt', 'org.pt',
        'com.pl', 'net.pl', 'org.pl',
        'com.ru', 'net.ru', 'org.ru',
    ];

    /** @var list<string> */
    private readonly array $suffixes;

    /**
     * @param  list<string>  $blockedDomains
     * @param  list<string>  $extraPublicSuffixes
     */
    public function __construct(
        private readonly array $blockedDomains = [],
        private readonly bool $allowApex = false,
        array $extraPublicSuffixes = [],
    ) {
        $this->suffixes = [
            ...self::MULTI_PART_SUFFIXES,
            ...array_map(static fn (string $suffix): string => strtolower(trim($suffix, ". \t\n\r\0\x0B")), $extraPublicSuffixes),
        ];
    }

    /**
     * Returns the normalized hostname, or throws why it cannot be used.
     *
     * @throws InvalidHostnameException
     * @throws ApexDomainNotAllowedException
     * @throws BlockedHostnameException
     */
    public function validate(string $hostname): string
    {
        $normalized = HostnameNormalizer::normalize($hostname);

        foreach ($this->blockedDomains as $blocked) {
            $blocked = strtolower(rtrim(trim($blocked), '.'));

            if ($blocked !== '' && ($normalized === $blocked || str_ends_with($normalized, ".{$blocked}"))) {
                throw new BlockedHostnameException("\"{$normalized}\" is on a platform domain and cannot be connected.");
            }
        }

        if (! $this->allowApex && $this->isApex($normalized)) {
            throw new ApexDomainNotAllowedException(
                "\"{$normalized}\" is an apex domain. Connect a subdomain such as www.{$normalized}. Apex domains need Apex Proxying or BYOIP on Cloudflare.",
            );
        }

        return $normalized;
    }

    public function isApex(string $hostname): bool
    {
        $labels = explode('.', $hostname);
        $count = count($labels);

        if ($count <= 2) {
            return true;
        }

        return $count === 3 && in_array("{$labels[1]}.{$labels[2]}", $this->suffixes, true);
    }
}
