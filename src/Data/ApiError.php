<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Data;

use AKhalid\CloudflareSaasDomains\Support\Payload;

final readonly class ApiError
{
    public function __construct(
        public int $code,
        public string $message,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::int($data, 'code'), Payload::string($data, 'message') ?? '');
    }
}
