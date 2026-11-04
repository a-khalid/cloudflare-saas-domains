# Cloudflare for SaaS Domains for Laravel

Let the merchants on your multi-tenant SaaS connect their own domains through [Cloudflare for SaaS](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/) (Custom Hostnames, formerly SSL for SaaS).

## The problem

Generic Cloudflare API wrappers give you the HTTP calls and leave the rest to you. A custom domain is a long-running state machine: validate the hostname, create it, tell the merchant exactly what to put in DNS, poll until Cloudflare is happy, react to success and failure once each, route requests to the right tenant, and clean up afterwards.

This package manages that whole lifecycle:

- **Actions** to add, remove, refresh and re-validate a domain
- **DNS instructions** computed from the domain's current state
- **Status polling** by command or queued job
- **Events** fired only on real state transitions
- **Middleware** that resolves the tenant from the request host, with caching
- **A fake** so your own tests need no network
- Optional **stancl/tenancy** integration

## Requirements

- PHP 8.3+ with the `intl` extension
- Laravel 12 or 13 (for Laravel 10 and 11, see [below](#laravel-10-and-11))
- A Cloudflare zone with Cloudflare for SaaS enabled, an active [fallback origin](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/getting-started/), and an API token with `SSL and Certificates Write` on that zone

## Installation

```bash
composer require a-khalid/cloudflare-saas-domains
```

```bash
php artisan vendor:publish --tag=cloudflare-saas-domains-config
```

```bash
php artisan vendor:publish --tag=cloudflare-saas-domains-migrations
```

Tenant IDs default to big integers. If yours are not, set `CF_SAAS_TENANT_KEY_TYPE` to `uuid`, `ulid` or `string` before migrating (`stancl/tenancy` uses `string` by default).

```bash
php artisan migrate
```

### Laravel 10 and 11

These have a separate release, tagged `v0.0.1`. Both versions are past security support, so it gets fixes on a best-effort basis and no new features. It needs PHP 8.3 too.

```bash
composer require a-khalid/cloudflare-saas-domains:~0.0.1
```

## Configuration

Everything lives in `config/cloudflare-saas-domains.php` and reads from the environment.

```dotenv
CF_SAAS_API_TOKEN=your-token
CF_SAAS_ZONE_ID=your-zone-id
CF_SAAS_FALLBACK_ORIGIN=fallback.your-saas.com
CF_SAAS_CNAME_TARGET=customers.your-saas.com
CF_SAAS_BLOCKED_DOMAINS=your-saas.com,your-other-brand.com
```

| Variable | Default | Purpose |
|---|---|---|
| `CF_SAAS_API_TOKEN`, `CF_SAAS_ZONE_ID` | none | Cloudflare credentials |
| `CF_SAAS_FALLBACK_ORIGIN` | none | Where Cloudflare sends custom hostname traffic |
| `CF_SAAS_CNAME_TARGET` | fallback origin | What merchants point their CNAME at |
| `CF_SAAS_POLLING_INTERVAL` | `300` | Minimum seconds between checks of one domain |
| `CF_SAAS_TABLE` | `tenant_domains` | Table name |
| `CF_SAAS_DB_CONNECTION` | default at boot | Connection for that table |
| `CF_SAAS_TENANT_MODEL` | any model | Restrict domain owners to this class |
| `CF_SAAS_TENANT_KEY_TYPE` | `int` | `int`, `uuid`, `ulid` or `string`, read by the migration |
| `CF_SAAS_SSL_METHOD` | `txt` | `txt`, `http` or `email` |
| `CF_SAAS_SSL_TYPE` | `dv` | Certificate type |
| `CF_SAAS_BLOCKED_DOMAINS` | none | Comma-separated platform domains. Subdomains are blocked too. |
| `CF_SAAS_ALLOW_APEX` | `false` | Allow apex domains. Needs Apex Proxying or BYOIP. |
| `CF_SAAS_HTTP_TIMEOUT`, `CF_SAAS_HTTP_CONNECT_TIMEOUT` | `15`, `5` | Seconds, applied to every call |
| `CF_SAAS_HTTP_RETRIES` | `3` | Extra attempts on 429, 5xx and connection failures |

The published file has the rest: retry delays, sync batch size and queue, cache store and TTLs, extra public suffixes.

## Quick start

Add the trait to your tenant model. It gives you a `customDomains()` relation.

```php
use AKhalid\CloudflareSaasDomains\Concerns\HasCustomDomains;

class Merchant extends Model
{
    use HasCustomDomains;
}
```

Then manage domains through the facade:

```php
use AKhalid\CloudflareSaasDomains\Facades\CloudflareSaasDomains;

$domain = CloudflareSaasDomains::add($merchant, 'shop.merchant.com');

$instructions = CloudflareSaasDomains::instructions($domain); // what to show the merchant

CloudflareSaasDomains::refresh($domain);          // check now, for a "Verify" button
CloudflareSaasDomains::retryValidation($domain);  // ask Cloudflare to re-check now
CloudflareSaasDomains::remove($domain);           // delete from Cloudflare, soft delete locally
```

Schedule the sync so pending domains move forward by themselves:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cf-domains:sync')->everyFiveMinutes()->withoutOverlapping();

// Catches live domains that stop pointing at you, and lapsed certificates.
Schedule::command('cf-domains:sync --all')->daily()->withoutOverlapping();
```

`--queue` dispatches `SyncDomainsJob` instead of running inline, and skips it if one is already waiting. The job's timeout is 900 seconds, so give its queue connection a higher `retry_after`, or lower `CF_SAAS_SYNC_BATCH_SIZE`. `--limit=` caps a run. The command exits non-zero if a domain could not be checked.

### What `add` guarantees

- The hostname is normalized: lowercase, IDN to punycode, no trailing dot.
- Invalid hostnames, apex domains and platform domains are rejected before any API call.
- Adding a hostname the tenant already has returns the existing domain, with no API call and no event.
- A hostname held by another tenant throws `DomainAlreadyClaimedException`.
- If Cloudflare fails, the local claim is released, so you can simply try again.
- A hostname that was removed, or that Cloudflare deleted, is free for any tenant to claim. So is a claim abandoned by a crashed process, after 15 minutes.
- If an earlier attempt reached Cloudflare but its response was lost, the existing hostname on your zone is adopted.

## DNS instructions

`DnsInstructions` reflects the domain's current state, so a merchant only sees what is still needed.

```php
$instructions = CloudflareSaasDomains::instructions($domain);

foreach ($instructions->required() as $record) {
    echo "{$record->type} {$record->name} {$record->value} ({$record->purpose->value})\n";
}
```

For a new domain:

```text
CNAME shop.merchant.com                     customers.your-saas.com               (routing)
TXT   _cf-custom-hostname.shop.merchant.com 5cc07c04-ea62-4a5a-95f0-419334a875a4  (ownership)
TXT   _acme-challenge.shop.merchant.com     810b7d5f01154524b961ba0cd578acc2      (ssl_validation)
```

| State | `required()` returns |
|---|---|
| New, hostname pending | CNAME, ownership TXT, SSL TXT |
| Hostname active, certificate pending validation | SSL TXT |
| Certificate past validation, or HTTP validation in use | Nothing beyond any record above that is still unmet |
| Live | Empty, and `$instructions->complete` is `true` |

Cloudflare generates certificate tokens asynchronously, so they may be missing just after creation. When `$instructions->awaitingValidationRecords` is `true`, refresh the domain and ask again. When `retryRequired` is `true`, validation failed: once DNS is fixed, call `retryValidation()`. When `hostnameGone` is `true`, Cloudflare no longer has the hostname, so `records` is empty and the domain has to be added again. `optional()` lists DCV delegation records when Cloudflare provides them. `toArray()` and `json_encode()` both work for API responses.

The CNAME must stay in place for as long as the domain is connected. The ownership TXT can be removed once the hostname is active. Renewals can bring new certificate tokens, which `required()` shows when a sync sees them.

## Events

| Event | Fires when | Properties |
|---|---|---|
| `DomainAdded` | The hostname is created on Cloudflare for this tenant | `domain` |
| `DomainVerified` | The hostname status moves into an active state | `domain` |
| `SslActivated` | The certificate moves into a serving state | `domain`, `previousSslStatus`, `isRenewal` |
| `DomainFailed` | The hostname or certificate moves into a failed state | `domain`, `reason` |
| `DomainRemoved` | The domain is removed | `domain` |

They live in `AKhalid\CloudflareSaasDomains\Events`, and fire only on a transition, never on an unchanged poll. A domain that fails, recovers and fails again fires `DomainFailed` twice. `SslActivated` also fires when a certificate returns to a serving state after a renewal, with `isRenewal` set to `true`.

`failure_reason` also carries Cloudflare's current message while a domain is merely pending, such as "custom hostname does not CNAME to this zone". That is normal until the merchant finishes DNS and does not fire `DomainFailed`.

## Middleware

```php
Route::middleware('cf-domains.tenant')->group(function () {
    Route::get('/', function (Request $request) {
        $tenant = $request->attributes->get('tenant');
        // or CloudflareSaasDomains::currentTenant()
        // and CloudflareSaasDomains::currentDomain()
    });
});
```

An unknown or inactive host gets a 404. Use `cf-domains.tenant:optional` to continue without a tenant, for example on routes shared with your own domain.

A domain resolves when its hostname status is active and its certificate is serving. Results, including misses, are cached and invalidated when a domain's status changes. Use a cache store shared between your servers (`CF_SAAS_CACHE_STORE`), otherwise the other servers only notice at the TTL.

If you use Laravel's `TrustHosts` middleware, merchant domains need to pass it.

## stancl/tenancy

Nothing to configure. When `stancl/tenancy` is installed, the middleware calls `tenancy()->initialize($tenant)` for tenants that implement its `Tenant` contract. Other tenant models are left alone. The `tenant_domains` table stays on the connection that was the default at boot, so it keeps working after tenancy switches to a tenant database. Set `CF_SAAS_STANCL_TENANCY=false` to turn this off.

## Testing with fakes

`CloudflareSaasDomains::fake()` swaps the API client for an in-memory zone, so your flows run for real, with real events and database rows, without network access.

```php
use AKhalid\CloudflareSaasDomains\Facades\CloudflareSaasDomains;

it('connects a merchant domain', function () {
    Event::fake([DomainVerified::class]);
    $fake = CloudflareSaasDomains::fake();

    $domain = CloudflareSaasDomains::add($merchant, 'shop.merchant.com');
    $fake->assertCreated('shop.merchant.com');

    $fake->activate('shop.merchant.com');
    CloudflareSaasDomains::refresh($domain);

    expect($domain->isLive())->toBeTrue();
    Event::assertDispatched(DomainVerified::class);
});
```

| Move a hostname along | Simulate trouble | Assert |
|---|---|---|
| `verifyHostname($host)` | `setStatus($host, $status, $sslStatus, $errors)` | `assertCreated`, `assertNotCreated` |
| `activateSsl($host)` | `withoutValidationRecords($host)` | `assertCreatedCount`, `assertNothingCreated` |
| `activate($host)` | `forget($host)` | `assertDeleted`, `assertNothingDeleted` |
| `seed($host, id: $id)` | `failNextWith($exception)` | `assertRevalidated`, `assertNothingRevalidated` |

The fake does not poll by itself: calling `verifyHostname` changes the zone, and your code sees it on the next refresh or sync. It also lasts for one process, so use `seed()` if a later process needs to act on an existing domain.

## Errors

Everything thrown extends `CloudflareSaasDomainsException`. API errors extend `CloudflareApiException`, with `statusCode` and `errors`.

| Situation | Exception |
|---|---|
| 401 | `AuthenticationFailedException` |
| 403 | `PermissionDeniedException` |
| 404 | `ResourceNotFoundException` |
| 409, or a "duplicate" message | `DuplicateHostnameException` |
| 429 after retries | `RateLimitedException`, with `retryAfterSeconds` |
| 5xx after retries | `ServerErrorException` |
| Other rejections, including HTTP 200 with `success: false` | `InvalidRequestException` |
| An undocumented response shape | `InvalidResponseException` |
| Timeouts and connection failures after retries | `ConnectionFailedException` |
| Bad hostnames | `InvalidHostnameException`, `ApexDomainNotAllowedException`, `BlockedHostnameException` |

During a sync, one failing domain is logged and the run continues, a rate limit ends the run early, and credential errors stop it. A hostname that Cloudflare no longer has is marked `deleted` and fires `DomainFailed`.

## Limitations

- **Not yet verified against the live API.** The client is built from Cloudflare's current documentation and tested with `Http::fake()`. Run the [live smoke test](CONTRIBUTING.md#live-smoke-test) on a test zone before relying on it in production.
- **Apex domains need Cloudflare's help.** `merchant.com` can't use a CNAME in standard DNS, so it needs [Apex Proxying](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/apex-proxying/) or BYOIP, which are limited to certain plans and cost extra. Apex domains are blocked by default. `CF_SAAS_ALLOW_APEX=true` lets them through, but `DnsInstructions` still shows a CNAME: you need to tell those merchants your A records yourself.
- **Apex detection is approximate.** It uses a built-in list of common suffixes such as `co.uk` and `com.sa`, not the full Public Suffix List. An unlisted suffix can let an apex through as if it were a subdomain. Add suffixes in `validation.public_suffixes`.
- **A brief cache race.** A request that read a domain just as it was removed can write its result back after the invalidation. That host then keeps resolving until `CF_SAAS_CACHE_TTL` (300 seconds by default). Lower the TTL if that matters to you.
- **Polling only.** Status changes appear on your next sync, not instantly. Live domains are only re-checked with `--all`.
- **Renewal behavior is an assumption.** The docs do not describe `ssl.status` during renewal. So a store doesn't go dark, a domain keeps resolving through renewal-looking states while its stored certificate expiry is in the future. See [assumption 6](#assumptions).
- **Duplicate detection is by message.** Cloudflare documents no error code for it. See [assumption 1](#assumptions).
- **Cloudflare-to-Cloudflare hostnames.** Cloudflare doesn't support pre-validation when the merchant's own zone is also on Cloudflare. The ownership TXT won't help them, and they need the CNAME in place.
- **Not covered yet:** wildcard hostnames, custom certificates, per-hostname `custom_origin_server`, and custom metadata through `add()` (the client supports metadata).
- **Retries block.** A retried call can wait several seconds in the current process, which matters in a web request. Lower `CF_SAAS_HTTP_RETRIES` if needed, or call `add` from a job.
- **Laravel 10 and 11 get a separate, best-effort release.** See [Laravel 10 and 11](#laravel-10-and-11).

## Assumptions

Cloudflare's docs do not settle these, so the package takes the safest reading. Please open an issue if the live API behaves differently.

1. **Duplicate errors.** No numeric duplicate-hostname code is documented. A response counts as a duplicate when it is HTTP 409 or an error message contains "duplicate" or "already exists". Adoption requires an exact hostname match in our own zone, checked client-side too.
2. **Re-validation body.** Docs say to send an `ssl` object with the same `method` and `type`, and elsewhere "the same values as your initial POST", while the Edit example has no `ssl` at all. `RetryValidation` GETs the current hostname and PATCHes its `method`, `type`, `bundle_method`, `certificate_authority` and `wildcard`, falling back to configured defaults if Cloudflare returned no `ssl`.
3. **Retrying non-idempotent calls.** POST is retried on 429/5xx/connection errors. A duplicate from a lost response is handled by adoption (1), and a DELETE retried into 404 counts as already removed.
4. **Failed states are not documented as such.** Treated as failed: hostname `moved, deleted, blocked, pending_blocked, test_blocked, test_failed`; SSL `*_timed_out, expired, deleted`. `pending_deletion`, `inactive` and unknown values are not failures on their own.
5. **Active states.** Hostname active means `active` or `active_redeploying`. `provisioned` and `test_*` are not mapped by the docs and count as not active.
6. **Certificate renewal.** Docs do not say how `ssl.status` behaves during renewal. To avoid taking a live store down, `pending_expiration` counts as serving, and renewal-looking states (`pending_validation, pending_issuance, pending_deployment, holding_deployment, backup_issued`) still resolve while the stored `ssl.expires_on` is in the future. The hostname must be active in every case, and Cloudflare's edge is still what terminates TLS.
7. **`hostname.exact` query parameter** uses the dotted form the List docs name.
8. **A 404 while polling** means Cloudflare no longer has the hostname: it is marked `deleted`, `DomainFailed` fires once, and polling stops. It is not raised as an exception, so one lost domain cannot break a sync run.
9. **Ownership TXT is shown as required until the hostname is active.** A live CNAME alone can validate it, but adding the TXT is harmless and avoids a second round with the merchant.
10. **Delegated DCV.** The docs describe getting the target from the dashboard, while the API schema exposes `ssl.dcv_delegation_records`. They are surfaced only as optional records, when both `cname` and `cname_target` are present.
11. **Apex detection** uses a label count plus a curated multi-part suffix list (extendable in config), not the full Public Suffix List. It can miss unusual suffixes, which is listed as a limitation.
12. **HTTP and email DCV** produce no DNS records for the merchant, because HTTP DCV needs only the CNAME. Records for them stay readable in `verification_records`.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
