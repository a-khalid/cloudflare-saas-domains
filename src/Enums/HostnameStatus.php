<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Enums;

enum HostnameStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case ActiveRedeploying = 'active_redeploying';
    case Moved = 'moved';
    case PendingDeletion = 'pending_deletion';
    case Deleted = 'deleted';
    case PendingBlocked = 'pending_blocked';
    case PendingMigration = 'pending_migration';
    case PendingProvisioned = 'pending_provisioned';
    case TestPending = 'test_pending';
    case TestActive = 'test_active';
    case TestActiveApex = 'test_active_apex';
    case TestBlocked = 'test_blocked';
    case TestFailed = 'test_failed';
    case Provisioned = 'provisioned';
    case Blocked = 'blocked';

    /** A value Cloudflare returned that this package does not know yet. */
    case Unknown = 'unknown';

    public static function parse(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }

    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    public function isFailed(): bool
    {
        return in_array($this, [
            self::Moved,
            self::Deleted,
            self::PendingBlocked,
            self::TestBlocked,
            self::TestFailed,
            self::Blocked,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function activeCases(): array
    {
        return [self::Active, self::ActiveRedeploying];
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::activeCases());
    }
}
