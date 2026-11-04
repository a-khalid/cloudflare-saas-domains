<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

/** HTTP 5xx after all retries. */
final class ServerErrorException extends CloudflareApiException {}
