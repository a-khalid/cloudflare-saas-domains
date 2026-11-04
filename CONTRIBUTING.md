# Contributing

Thanks for helping out. Bug reports with a failing test and focused pull requests are the most useful contributions.

## Setup

PHP 8.3+ with the `intl` extension, and Composer.

```bash
composer install
```

## Before opening a pull request

```bash
composer test
```

```bash
composer analyse
```

```bash
composer format:check
```

All three run in CI across the supported PHP and Laravel versions. Run `composer format` to fix style.

- Add or update tests for every behavior change. `composer test:coverage` needs Xdebug or PCOV and enforces 90%.
- Tests must not touch the network. Use `Http::fake()` for client behavior and `CloudflareSaasDomains::fake()` for flows.
- Update `CHANGELOG.md` under `[Unreleased]`.
- Check Cloudflare behavior against [the current API docs](https://developers.cloudflare.com/api/resources/custom_hostnames/). If the docs leave something open, choose the safest reading and record it under "Assumptions" in the README.

## Conventions

- `declare(strict_types=1)` everywhere, final classes where reasonable, constructor injection.
- No facades in core logic. The facade is the public API only.
- Every remote state change goes through `DomainStateTransitioner`, so events stay tied to real transitions.
- Every HTTP call goes through `CloudflareClient::request()`, so it always has a timeout.

## Live smoke test

`tests/Live` changes a real zone, so it is opt-in and maintainers run it by hand. Copy `.env.testing.example` to `.env.testing`, fill it in, then:

```bash
CF_LIVE_TEST=1 vendor/bin/pest tests/Live
```

Never commit credentials. `.env.testing` is ignored by git.
