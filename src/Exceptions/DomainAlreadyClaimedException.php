<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

/** Another tenant already owns this hostname. */
final class DomainAlreadyClaimedException extends CloudflareSaasDomainsException {}
