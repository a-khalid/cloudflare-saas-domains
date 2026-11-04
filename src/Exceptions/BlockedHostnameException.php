<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

/** The hostname is a platform domain or one of its subdomains. */
final class BlockedHostnameException extends CloudflareSaasDomainsException {}
