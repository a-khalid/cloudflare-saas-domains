<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Events;

use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The hostname or its certificate moved into a failed state. */
final class DomainFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly TenantDomain $domain,
        public readonly string $reason,
    ) {}
}
