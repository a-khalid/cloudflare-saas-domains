<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Exceptions;

/** Cloudflare could not be reached, including timeouts. */
final class ConnectionFailedException extends CloudflareSaasDomainsException {}
