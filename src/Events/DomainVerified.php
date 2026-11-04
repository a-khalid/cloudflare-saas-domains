<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;

/** The hostname status moved into an active state. */
final class DomainVerified implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly TenantDomain $domain) {}
}
