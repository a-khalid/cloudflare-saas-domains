<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Events;

use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The domain was removed from Cloudflare and soft deleted. */
final class DomainRemoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly TenantDomain $domain) {}
}
