<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Exceptions;

/** Apex domains need Apex Proxying or BYOIP, which is off by default. */
final class ApexDomainNotAllowedException extends CloudflareSaasDomainsException {}
