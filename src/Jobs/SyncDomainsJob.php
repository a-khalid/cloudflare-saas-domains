<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use YourVendor\CloudflareSaasDomains\Support\DomainSynchronizer;

final class SyncDomainsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The next scheduled run picks up the work, so a failed run is not retried. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly bool $all = false,
        public readonly ?int $limit = null,
    ) {}

    public function handle(DomainSynchronizer $synchronizer): void
    {
        $synchronizer->sync($this->all, $this->limit);
    }

    public function uniqueId(): string
    {
        return $this->all ? 'all' : 'pending';
    }
}
