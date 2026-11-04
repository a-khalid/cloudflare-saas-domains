<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

use AKhalid\CloudflareSaasDomains\Data\ApiError;

/** HTTP 429 after all retries. */
final class RateLimitedException extends CloudflareApiException
{
    /**
     * @param  list<ApiError>  $errors
     */
    public function __construct(
        string $message,
        int $statusCode = 429,
        array $errors = [],
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $statusCode, $errors);
    }
}
