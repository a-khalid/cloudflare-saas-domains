<?php

declare(strict_types=1);

use YourVendor\CloudflareSaasDomains\Exceptions\ApexDomainNotAllowedException;
use YourVendor\CloudflareSaasDomains\Exceptions\BlockedHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidHostnameException;
use YourVendor\CloudflareSaasDomains\Validation\HostnameNormalizer;
use YourVendor\CloudflareSaasDomains\Validation\HostnameValidator;

it('normalizes case, whitespace and the trailing dot', function (string $input, string $expected) {
    expect(HostnameNormalizer::normalize($input))->toBe($expected);
})->with([
    ['Shop.Acme.COM', 'shop.acme.com'],
    ['  shop.acme.com  ', 'shop.acme.com'],
    ['shop.acme.com.', 'shop.acme.com'],
]);

it('converts IDN labels to punycode', function (string $input, string $expected) {
    expect(HostnameNormalizer::normalize($input))->toBe($expected);
})->with([
    ['shop.bücher.de', 'shop.xn--bcher-kva.de'],
    ['SHOP.BÜCHER.DE', 'shop.xn--bcher-kva.de'],
    ['متجر.example.com', 'xn--pgbep1f.example.com'],
    ['shop.xn--bcher-kva.de', 'shop.xn--bcher-kva.de'],
]);

it('rejects malformed hostnames', function (string $input) {
    HostnameNormalizer::normalize($input);
})->throws(InvalidHostnameException::class)->with([
    'empty' => [''],
    'scheme' => ['https://shop.acme.com'],
    'path' => ['shop.acme.com/store'],
    'port' => ['shop.acme.com:8080'],
    'query' => ['shop.acme.com?x=1'],
    'space' => ['shop acme.com'],
    'wildcard' => ['*.acme.com'],
    'single label' => ['localhost'],
    'leading hyphen' => ['-shop.acme.com'],
    'trailing hyphen' => ['shop-.acme.com'],
    'underscore' => ['sh_op.acme.com'],
    'empty label' => ['shop..acme.com'],
    'long label' => [str_repeat('a', 64).'.acme.com'],
    'too long' => [implode('.', array_fill(0, 50, 'abcdefgh')).'.com'],
    'ipv4' => ['192.168.1.1'],
    'ipv6' => ['[::1]'],
    'reserved test' => ['shop.acme.test'],
    'reserved local' => ['shop.acme.local'],
    'reserved example' => ['shop.acme.example'],
    'userinfo' => ['user@shop.acme.com'],
]);

it('detects apex domains', function (string $hostname, bool $apex) {
    expect((new HostnameValidator)->isApex($hostname))->toBe($apex);
})->with([
    ['acme.com', true],
    ['acme.co.uk', true],
    ['acme.com.sa', true],
    ['shop.acme.com', false],
    ['shop.acme.co.uk', false],
    ['a.b.acme.com', false],
]);

it('rejects apex domains by default', function () {
    (new HostnameValidator)->validate('acme.com');
})->throws(ApexDomainNotAllowedException::class);

it('allows apex domains when configured', function () {
    expect((new HostnameValidator(allowApex: true))->validate('acme.com'))->toBe('acme.com');
});

it('honors extra public suffixes', function () {
    $validator = new HostnameValidator(extraPublicSuffixes: ['.Custom.Zone.']);

    expect($validator->isApex('acme.custom.zone'))->toBeTrue();
});

it('blocks platform domains and their subdomains', function (string $hostname) {
    (new HostnameValidator(['saas.io', 'Customers.Other.io.']))->validate($hostname);
})->throws(BlockedHostnameException::class)->with([
    ['saas.io'],
    ['shop.saas.io'],
    ['a.b.saas.io'],
    ['customers.other.io'],
    ['x.customers.other.io'],
]);

it('does not confuse lookalike domains with platform domains', function () {
    expect((new HostnameValidator(['saas.io']))->validate('shop.notsaas.io'))->toBe('shop.notsaas.io');
});
