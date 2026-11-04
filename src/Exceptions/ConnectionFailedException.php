<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

/** Cloudflare could not be reached, including timeouts. */
final class ConnectionFailedException extends CloudflareSaasDomainsException {}
