<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Exceptions;

/** Another tenant already owns this hostname. */
final class DomainAlreadyClaimedException extends CloudflareSaasDomainsException {}
