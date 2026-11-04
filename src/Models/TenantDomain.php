<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Models;

use AKhalid\CloudflareSaasDomains\Enums\HostnameStatus;
use AKhalid\CloudflareSaasDomains\Enums\SslStatus;
use AKhalid\CloudflareSaasDomains\Validation\HostnameNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_type
 * @property int|string $tenant_id
 * @property string $hostname
 * @property string|null $cloudflare_id
 * @property string|null $hostname_status
 * @property string|null $ssl_status
 * @property array<string, mixed>|null $verification_records
 * @property Carbon|null $ssl_expires_at
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $verified_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $tenant
 */
class TenantDomain extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * A property, not the casts() method, because Laravel 10 only reads the property.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'verification_records' => 'array',
        'ssl_expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function getTable(): string
    {
        $table = config('cloudflare-saas-domains.table', 'tenant_domains');

        return is_string($table) ? $table : 'tenant_domains';
    }

    public function getConnectionName(): ?string
    {
        $own = parent::getConnectionName();

        if ($own !== null) {
            return $own;
        }

        $connection = config('cloudflare-saas-domains.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * @return Attribute<string, string>
     */
    protected function hostname(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => HostnameNormalizer::normalize($value));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    public function hostnameState(): HostnameStatus
    {
        return HostnameStatus::parse($this->hostname_status);
    }

    public function sslState(): SslStatus
    {
        return SslStatus::parse($this->ssl_status);
    }

    /**
     * Fully live: the hostname is active and its certificate is serving.
     */
    public function isLive(): bool
    {
        return $this->hostnameState()->isActive() && $this->sslState()->isServing();
    }

    public function isFailed(): bool
    {
        return $this->hostnameState()->isFailed() || $this->sslState()->isFailed();
    }

    /**
     * Whether the middleware may resolve a tenant from this domain. Same rule as scopeResolvable().
     */
    public function isResolvable(?CarbonInterface $now = null): bool
    {
        if ($this->trashed() || ! $this->hostnameState()->isActive()) {
            return false;
        }

        return $this->sslState()->isServing()
            || ($this->sslState()->isRenewing() && $this->ssl_expires_at?->isAfter($now ?? Carbon::now()) === true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeResolvable(Builder $query): Builder
    {
        return $query
            ->whereIn('hostname_status', HostnameStatus::activeValues())
            ->where(static function (Builder $query): void {
                $query->whereIn('ssl_status', SslStatus::servingValues())
                    ->orWhere(static function (Builder $query): void {
                        $query->whereIn('ssl_status', SslStatus::renewingValues())
                            ->where('ssl_expires_at', '>', Carbon::now());
                    });
            });
    }

    /**
     * Domains that are not live yet and that Cloudflare can still change.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNotNull('cloudflare_id')
            ->where(static function (Builder $query): void {
                $query->whereNull('hostname_status')
                    ->orWhere('hostname_status', '!=', HostnameStatus::Deleted->value);
            })
            ->where(static function (Builder $query): void {
                $query->whereNull('hostname_status')
                    ->orWhereNotIn('hostname_status', HostnameStatus::activeValues())
                    ->orWhereNull('ssl_status')
                    ->orWhereNotIn('ssl_status', SslStatus::servingValues());
            });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueForCheck(Builder $query, int $intervalSeconds): Builder
    {
        return $query->where(static function (Builder $query) use ($intervalSeconds): void {
            $query->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', Carbon::now()->subSeconds($intervalSeconds));
        });
    }
}
