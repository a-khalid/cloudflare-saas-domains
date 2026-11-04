<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Support;

final readonly class SyncResult
{
    /**
     * @param  array<string, string>  $failures  Error message by hostname.
     */
    public function __construct(
        public int $checked = 0,
        public array $failures = [],
        public bool $rateLimited = false,
    ) {}

    public function hasProblems(): bool
    {
        return $this->failures !== [] || $this->rateLimited;
    }
}
