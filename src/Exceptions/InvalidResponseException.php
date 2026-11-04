<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Exceptions;

/** Cloudflare answered with something that is not the documented shape. */
final class InvalidResponseException extends CloudflareApiException {}
