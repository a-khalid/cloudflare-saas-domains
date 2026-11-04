<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

use AKhalid\CloudflareSaasDomains\Data\ApiError;
use Throwable;

/** Cloudflare answered, and the answer was an error. */
class CloudflareApiException extends CloudflareSaasDomainsException
{
    /**
     * @param  list<ApiError>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
