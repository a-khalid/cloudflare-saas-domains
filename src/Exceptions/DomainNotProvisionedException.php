<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Exceptions;

/** The domain has no Cloudflare hostname to act on. */
final class DomainNotProvisionedException extends CloudflareSaasDomainsException {}
