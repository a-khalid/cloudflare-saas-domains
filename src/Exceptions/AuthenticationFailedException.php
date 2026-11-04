<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

/** HTTP 401, the API token was rejected. */
final class AuthenticationFailedException extends CloudflareApiException {}
