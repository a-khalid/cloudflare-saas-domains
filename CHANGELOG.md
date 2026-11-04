# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - Unreleased

### Added

- Typed Cloudflare client for custom hostnames and the fallback origin, with explicit timeouts, retries with backoff on 429, 5xx and connection failures, and errors mapped to package exceptions.
- `TenantDomain` model and migration: polymorphic tenant, normalized unique hostname, statuses, verification records, soft deletes.
- `AddDomain`, `RemoveDomain`, `RefreshDomainStatus` and `RetryValidation` actions.
- Hostname validation: IDN to punycode, malformed input, apex and platform domain blocking, per-tenant idempotency.
- `DnsInstructions` value object driven by the domain's current state.
- `cf-domains:sync` command and `SyncDomainsJob`.
- `DomainAdded`, `DomainVerified`, `SslActivated`, `DomainFailed` and `DomainRemoved` events, fired only on real transitions.
- `ResolveTenantByHostname` middleware with caching and automatic invalidation.
- Optional `stancl/tenancy` integration.
- `CloudflareSaasDomains::fake()` for testing without network access.
- Support for Laravel 10, 11, 12 and 13 on PHP 8.3+. Laravel 10 and 11 are end of life, and Composer's advisory blocking affects full updates and new projects on them.
