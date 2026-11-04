<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Exceptions;

/** The hostname already exists on this zone. */
final class DuplicateHostnameException extends CloudflareApiException {}
