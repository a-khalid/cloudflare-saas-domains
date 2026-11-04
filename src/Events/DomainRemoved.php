<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;

/** The domain was removed from Cloudflare and soft deleted. */
final class DomainRemoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly TenantDomain $domain) {}
}
