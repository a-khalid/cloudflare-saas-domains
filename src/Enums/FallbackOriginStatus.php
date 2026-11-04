<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Enums;

enum FallbackOriginStatus: string
{
    case Initializing = 'initializing';
    case PendingDeployment = 'pending_deployment';
    case PendingDeletion = 'pending_deletion';
    case Active = 'active';
    case DeploymentTimedOut = 'deployment_timed_out';
    case DeletionTimedOut = 'deletion_timed_out';

    /** A value Cloudflare returned that this package does not know yet. */
    case Unknown = 'unknown';

    public static function parse(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }
}
