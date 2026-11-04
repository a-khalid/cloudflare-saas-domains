<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Events;

use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The certificate moved into a serving state. $isRenewal is true when a certificate
 * had already served on this domain, and false on the first issue.
 */
final class SslActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly TenantDomain $domain,
        public readonly ?string $previousSslStatus = null,
        public readonly bool $isRenewal = false,
    ) {}
}
