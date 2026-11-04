<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

/** HTTP 403, the token lacks permission on this zone. */
final class PermissionDeniedException extends CloudflareApiException {}
