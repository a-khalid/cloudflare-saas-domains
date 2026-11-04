<?php

declare(strict_types=1);

namespace AKhalid\CloudflareSaasDomains\Support;

use AKhalid\CloudflareSaasDomains\Data\CustomHostname;
use AKhalid\CloudflareSaasDomains\Enums\HostnameStatus;
use AKhalid\CloudflareSaasDomains\Enums\SslStatus;
use AKhalid\CloudflareSaasDomains\Events\DomainFailed;
use AKhalid\CloudflareSaasDomains\Events\DomainVerified;
use AKhalid\CloudflareSaasDomains\Events\SslActivated;
use AKhalid\CloudflareSaasDomains\Models\TenantDomain;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;

/**
 * The only writer of remote state onto TenantDomain, so that every path (add, poll,
 * retry) fires events the same way: once, on a real transition, after the save.
 */
final class DomainStateTransitioner
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @param  list<object>  $precedingEvents  Fired after the save and before any transition event.
     */
    public function apply(TenantDomain $domain, CustomHostname $remote, array $precedingEvents = []): TenantDomain
    {
        return $this->transition($domain, static function (TenantDomain $domain) use ($remote): void {
            $domain->cloudflare_id = $remote->id;
            $domain->hostname_status = $remote->rawStatus;
            $domain->ssl_status = $remote->ssl?->rawStatus;
            $domain->verification_records = $remote->verificationRecords();
            // Kept when absent: a renewal must not forget the expiry of the certificate still being served.
            if ($remote->ssl?->expiresOn !== null) {
                $domain->ssl_expires_at = Carbon::parse($remote->ssl->expiresOn)->setTimezone(date_default_timezone_get());
            }

            $domain->failure_reason = $remote->errors() === [] ? null : implode('; ', $remote->errors());
        }, $precedingEvents);
    }

    /**
     * Cloudflare no longer has this hostname, for example after its validation
     * window ran out.
     */
    public function applyRemoteDeletion(TenantDomain $domain): TenantDomain
    {
        return $this->transition($domain, static function (TenantDomain $domain): void {
            $domain->hostname_status = HostnameStatus::Deleted->value;
            $domain->ssl_status = SslStatus::Deleted->value;
            $domain->failure_reason = 'The hostname no longer exists on Cloudflare.';
        });
    }

    /**
     * @param  Closure(TenantDomain): void  $mutate
     * @param  list<object>  $precedingEvents
     */
    private function transition(TenantDomain $domain, Closure $mutate, array $precedingEvents = []): TenantDomain
    {
        $events = $domain->getConnection()->transaction(function () use ($domain, $mutate): ?array {
            // Locking means two concurrent polls cannot both see the same transition.
            $locked = $domain->newQueryWithoutScopes()->lockForUpdate()->find($domain->getKey());

            if (! $locked instanceof TenantDomain || $locked->trashed()) {
                return null;
            }

            $domain->setRawAttributes($locked->getAttributes(), true);

            $wasActive = $domain->hostnameState()->isActive();
            $wasServing = $domain->sslState()->isServing();
            $wasFailed = $domain->isFailed();
            $hadCertificate = $domain->ssl_activated_at !== null;
            $previousSsl = $domain->ssl_status;

            $mutate($domain);

            $domain->last_checked_at = Carbon::now();

            if ($domain->isFailed()) {
                $domain->failure_reason ??= $this->describeFailure($domain);
            }

            $events = [];

            if (! $wasActive && $domain->hostnameState()->isActive()) {
                $domain->verified_at = Carbon::now();
                $events[] = new DomainVerified($domain);
            }

            if (! $wasServing && $domain->sslState()->isServing()) {
                $domain->ssl_activated_at ??= Carbon::now();
                $events[] = new SslActivated($domain, $previousSsl, $hadCertificate);
            }

            if (! $wasFailed && $domain->isFailed()) {
                $events[] = new DomainFailed($domain, (string) $domain->failure_reason);
            }

            $domain->save();

            return $events;
        });

        if ($events === null) {
            return $domain;
        }

        foreach ([...$precedingEvents, ...$events] as $event) {
            $this->events->dispatch($event);
        }

        return $domain;
    }

    private function describeFailure(TenantDomain $domain): string
    {
        return sprintf(
            'Cloudflare reports hostname status "%s" and SSL status "%s".',
            $domain->hostname_status ?? 'none',
            $domain->ssl_status ?? 'none',
        );
    }
}
