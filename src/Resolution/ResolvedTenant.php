<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Resolution;

use Illuminate\Database\Eloquent\Model;

final readonly class ResolvedTenant
{
    public function __construct(
        public Model $tenant,
        public int $domainId,
        public string $hostname,
    ) {}
}
