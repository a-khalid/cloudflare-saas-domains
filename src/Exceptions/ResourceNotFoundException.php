<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Exceptions;

/** HTTP 404, the hostname or fallback origin does not exist. */
final class ResourceNotFoundException extends CloudflareApiException {}
