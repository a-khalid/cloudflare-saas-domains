<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Exceptions;

/** Any other rejected request, including HTTP 200 with success false. */
final class InvalidRequestException extends CloudflareApiException {}
