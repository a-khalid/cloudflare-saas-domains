<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare credentials
    |--------------------------------------------------------------------------
    |
    | The token needs the "SSL and Certificates Write" permission on the zone
    | that holds your custom hostnames.
    |
    */

    'api_token' => env('CF_SAAS_API_TOKEN'),

    'zone_id' => env('CF_SAAS_ZONE_ID'),

    /*
    |--------------------------------------------------------------------------
    | DNS targets
    |--------------------------------------------------------------------------
    |
    | fallback_origin is where Cloudflare sends custom hostname traffic.
    | cname_target is what merchants point their CNAME at. It falls back to
    | fallback_origin when it is not set.
    |
    */

    'fallback_origin' => env('CF_SAAS_FALLBACK_ORIGIN'),

    'cname_target' => env('CF_SAAS_CNAME_TARGET'),

    /*
    |--------------------------------------------------------------------------
    | Status polling
    |--------------------------------------------------------------------------
    |
    | polling_interval is the minimum number of seconds between two checks of
    | the same domain. batch_size caps one sync run.
    |
    */

    'polling_interval' => (int) env('CF_SAAS_POLLING_INTERVAL', 300),

    'sync' => [
        'batch_size' => (int) env('CF_SAAS_SYNC_BATCH_SIZE', 500),
        'queue' => env('CF_SAAS_SYNC_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage and tenants
    |--------------------------------------------------------------------------
    |
    | tenant_model is optional. When set, only instances of that class can own
    | a domain. tenant_key_type is read by the migration: int, uuid, ulid or
    | string (use string for stancl/tenancy's default ids).
    |
    */

    'table' => env('CF_SAAS_TABLE', 'tenant_domains'),

    'tenant_model' => env('CF_SAAS_TENANT_MODEL'),

    'tenant_key_type' => env('CF_SAAS_TENANT_KEY_TYPE', 'int'),

    /*
    |--------------------------------------------------------------------------
    | Certificate defaults
    |--------------------------------------------------------------------------
    |
    | method: txt, http or email. type: dv is the only documented value.
    | The remaining options are left to Cloudflare's defaults when null.
    |
    */

    'ssl' => [
        'method' => env('CF_SAAS_SSL_METHOD', 'txt'),
        'type' => env('CF_SAAS_SSL_TYPE', 'dv'),
        'bundle_method' => env('CF_SAAS_SSL_BUNDLE_METHOD'),
        'certificate_authority' => env('CF_SAAS_SSL_CA'),
        'min_tls_version' => env('CF_SAAS_SSL_MIN_TLS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    |
    | Times are in seconds except the retry delays, which are milliseconds.
    | retries is the number of extra attempts after the first one, used on
    | 429, 5xx and connection failures.
    |
    */

    'http' => [
        'base_url' => env('CF_SAAS_API_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('CF_SAAS_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('CF_SAAS_HTTP_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('CF_SAAS_HTTP_RETRIES', 3),
        'retry_base_ms' => (int) env('CF_SAAS_HTTP_RETRY_BASE_MS', 500),
        'retry_max_ms' => (int) env('CF_SAAS_HTTP_RETRY_MAX_MS', 10000),
        'retry_jitter_ms' => (int) env('CF_SAAS_HTTP_RETRY_JITTER_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hostname validation
    |--------------------------------------------------------------------------
    |
    | allow_apex: enable only if Cloudflare gave you Apex Proxying or BYOIP.
    | blocked_domains: your platform domains. The domain and all of its
    | subdomains are refused. cname_target and fallback_origin are always
    | refused too. public_suffixes: extra multi-part suffixes such as "co.uk"
    | to treat as a TLD when detecting apex domains.
    |
    */

    'validation' => [
        'allow_apex' => (bool) env('CF_SAAS_ALLOW_APEX', false),
        'blocked_domains' => array_values(array_filter(explode(',', (string) env('CF_SAAS_BLOCKED_DOMAINS', '')))),
        'public_suffixes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant resolution cache
    |--------------------------------------------------------------------------
    |
    | Times are in seconds. store null uses the default cache store. Entries
    | are invalidated automatically when a domain's status changes.
    |
    */

    'cache' => [
        'store' => env('CF_SAAS_CACHE_STORE'),
        'prefix' => 'cf-domains:host:',
        'ttl' => (int) env('CF_SAAS_CACHE_TTL', 300),
        'negative_ttl' => (int) env('CF_SAAS_CACHE_NEGATIVE_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | stancl/tenancy is used only when it is installed and this is true.
    |
    */

    'integrations' => [
        'stancl_tenancy' => (bool) env('CF_SAAS_STANCL_TENANCY', true),
    ],

];
