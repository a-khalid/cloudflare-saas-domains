<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Psr\Log\LoggerInterface;
use YourVendor\CloudflareSaasDomains\Actions\RefreshDomainStatus;
use YourVendor\CloudflareSaasDomains\Exceptions\AuthenticationFailedException;
use YourVendor\CloudflareSaasDomains\Exceptions\CloudflareSaasDomainsException;
use YourVendor\CloudflareSaasDomains\Exceptions\PermissionDeniedException;
use YourVendor\CloudflareSaasDomains\Exceptions\RateLimitedException;
use YourVendor\CloudflareSaasDomains\Models\TenantDomain;

final class DomainSynchronizer
{
    public function __construct(
        private readonly RefreshDomainStatus $refresh,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Refreshes the domains that are due, oldest check first.
     *
     * One bad domain does not stop the run. A rate limit ends it early, because the
     * next call would fail too, and the oldest-first order resumes from there.
     * Credential errors are thrown: every remaining domain would fail the same way.
     *
     * @param  bool  $all  Also check live domains, to catch a hostname that stopped pointing here or a certificate lapse.
     *
     * @throws AuthenticationFailedException
     * @throws PermissionDeniedException
     */
    public function sync(bool $all = false, ?int $limit = null): SyncResult
    {
        $query = TenantDomain::query()
            ->whereNotNull('cloudflare_id')
            ->dueForCheck($this->intConfig('polling_interval', 300))
            ->orderByRaw('last_checked_at IS NOT NULL')
            ->orderBy('last_checked_at')
            ->orderBy('id')
            ->limit($limit ?? $this->intConfig('sync.batch_size', 500));

        if ($all) {
            $query->where(static function ($query): void {
                $query->whereNull('hostname_status')->orWhere('hostname_status', '!=', 'deleted');
            });
        } else {
            $query->pending();
        }

        $checked = 0;
        $failures = [];

        foreach ($query->get() as $domain) {
            try {
                $this->refresh->handle($domain);
                $checked++;
            } catch (AuthenticationFailedException|PermissionDeniedException $exception) {
                throw $exception;
            } catch (RateLimitedException $exception) {
                $this->logger->warning('Cloudflare rate limit reached, ending domain sync early.', [
                    'checked' => $checked,
                    'retry_after' => $exception->retryAfterSeconds,
                ]);

                return new SyncResult($checked, $failures, rateLimited: true);
            } catch (CloudflareSaasDomainsException $exception) {
                $failures[$domain->hostname] = $exception->getMessage();

                $this->logger->error('Could not refresh custom domain.', [
                    'hostname' => $domain->hostname,
                    'exception' => $exception,
                ]);
            }
        }

        return new SyncResult($checked, $failures);
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get("cloudflare-saas-domains.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
