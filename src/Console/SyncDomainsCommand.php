<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use YourVendor\CloudflareSaasDomains\Exceptions\CloudflareSaasDomainsException;
use YourVendor\CloudflareSaasDomains\Jobs\SyncDomainsJob;
use YourVendor\CloudflareSaasDomains\Support\DomainSynchronizer;

final class SyncDomainsCommand extends Command
{
    protected $signature = 'cf-domains:sync
        {--all : Also check live domains, to catch a moved hostname or a lapsed certificate}
        {--queue : Dispatch a queued job instead of running here}
        {--limit= : Maximum number of domains to check}';

    protected $description = 'Refresh custom domain statuses from Cloudflare';

    public function handle(DomainSynchronizer $synchronizer, Config $config): int
    {
        $all = (bool) $this->option('all');
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;

        if ($this->option('queue')) {
            // SyncDomainsJob::dispatch() is what honors ShouldBeUnique. The bus alone would stack jobs.
            $pending = SyncDomainsJob::dispatch($all, $limit);
            $queue = $config->get('cloudflare-saas-domains.sync.queue');

            if (is_string($queue) && $queue !== '') {
                $pending->onQueue($queue);
            }

            unset($pending);

            $this->components->info('Domain sync queued, unless one is already waiting.');

            return self::SUCCESS;
        }

        try {
            $result = $synchronizer->sync($all, $limit);
        } catch (CloudflareSaasDomainsException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Checked {$result->checked} domain(s).");

        foreach ($result->failures as $hostname => $message) {
            $this->components->error("{$hostname}: {$message}");
        }

        if ($result->rateLimited) {
            $this->components->warn('Stopped early: Cloudflare rate limit reached. The next run continues from here.');
        }

        return $result->hasProblems() ? self::FAILURE : self::SUCCESS;
    }
}
