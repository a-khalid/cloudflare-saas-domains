<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Events;

use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The certificate moved into a serving state. $wasVerifiedBefore is true when the
 * hostname had already been verified, which helps tell a renewal from a first issue.
 */
final class SslActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly TenantDomain $domain,
        public readonly ?string $previousSslStatus = null,
        public readonly bool $wasVerifiedBefore = false,
    ) {}
}
