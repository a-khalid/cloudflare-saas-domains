<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Data;

final readonly class CustomHostnamePage
{
    /**
     * @param  list<CustomHostname>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $totalCount,
        public int $totalPages,
    ) {}
}
