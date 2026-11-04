<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Enums;

enum SslStatus: string
{
    case Initializing = 'initializing';
    case PendingValidation = 'pending_validation';
    case Deleted = 'deleted';
    case PendingIssuance = 'pending_issuance';
    case PendingDeployment = 'pending_deployment';
    case PendingDeletion = 'pending_deletion';
    case PendingExpiration = 'pending_expiration';
    case Expired = 'expired';
    case Active = 'active';
    case InitializingTimedOut = 'initializing_timed_out';
    case ValidationTimedOut = 'validation_timed_out';
    case IssuanceTimedOut = 'issuance_timed_out';
    case DeploymentTimedOut = 'deployment_timed_out';
    case DeletionTimedOut = 'deletion_timed_out';
    case PendingCleanup = 'pending_cleanup';
    case StagingDeployment = 'staging_deployment';
    case StagingActive = 'staging_active';
    case Deactivating = 'deactivating';
    case Inactive = 'inactive';
    case BackupIssued = 'backup_issued';
    case HoldingDeployment = 'holding_deployment';

    /** A value Cloudflare returned that this package does not know yet. */
    case Unknown = 'unknown';

    public static function parse(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }

    /**
     * The certificate is live and valid.
     */
    public function isServing(): bool
    {
        return in_array($this, self::servingCases(), true);
    }

    /**
     * States a certificate can pass through while being renewed. Only meaningful
     * together with a known expiry in the future. See PLAN.md, assumption 6.
     */
    public function isRenewing(): bool
    {
        return in_array($this, self::renewingCases(), true);
    }

    public function isFailed(): bool
    {
        return in_array($this, [
            self::Deleted,
            self::Expired,
            self::InitializingTimedOut,
            self::ValidationTimedOut,
            self::IssuanceTimedOut,
            self::DeploymentTimedOut,
            self::DeletionTimedOut,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function servingCases(): array
    {
        return [self::Active, self::PendingExpiration];
    }

    /**
     * @return list<self>
     */
    public static function renewingCases(): array
    {
        return [
            self::PendingValidation,
            self::PendingIssuance,
            self::PendingDeployment,
            self::HoldingDeployment,
            self::BackupIssued,
        ];
    }

    /**
     * @return list<string>
     */
    public static function servingValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::servingCases());
    }

    /**
     * @return list<string>
     */
    public static function renewingValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::renewingCases());
    }
}
