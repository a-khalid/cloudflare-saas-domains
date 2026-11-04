<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Testing;

use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Throwable;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Data\CustomHostname;
use YourVendor\CloudflareSaasDomains\Data\CustomHostnamePage;
use YourVendor\CloudflareSaasDomains\Data\FallbackOrigin;
use YourVendor\CloudflareSaasDomains\Data\SslOptions;
use YourVendor\CloudflareSaasDomains\Enums\FallbackOriginStatus;
use YourVendor\CloudflareSaasDomains\Enums\HostnameStatus;
use YourVendor\CloudflareSaasDomains\Enums\SslStatus;
use YourVendor\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;

/**
 * An in-memory Cloudflare zone. It speaks the same payload shape as the API and
 * lets a test move hostnames through their lifecycle. Installed by
 * CloudflareSaasDomains::fake().
 */
final class FakeCustomHostnameClient implements CustomHostnameClient
{
    /** @var array<string, array<string, mixed>> Cloudflare payloads by id. */
    private array $hostnames = [];

    /** @var list<string> */
    private array $created = [];

    /** @var list<string> */
    private array $deleted = [];

    /** @var list<string> */
    private array $revalidated = [];

    private ?FallbackOrigin $fallbackOrigin = null;

    private ?Throwable $nextFailure = null;

    public function createHostname(string $hostname, SslOptions $ssl, array $customMetadata = []): CustomHostname
    {
        $this->throwPendingFailure();

        if ($this->idFor($hostname) !== null) {
            throw new DuplicateHostnameException("Duplicate custom hostname found: {$hostname}", 409);
        }

        $this->created[] = $hostname;

        return $this->seed($hostname, $ssl, $customMetadata);
    }

    public function getHostname(string $id): CustomHostname
    {
        $this->throwPendingFailure();

        return CustomHostname::fromArray($this->payload($id));
    }

    public function listHostnames(int $page = 1, int $perPage = 50, ?string $hostname = null): CustomHostnamePage
    {
        $this->throwPendingFailure();

        $all = array_values(array_filter(
            array_map(CustomHostname::fromArray(...), array_values($this->hostnames)),
            static fn (CustomHostname $item): bool => $hostname === null || $item->hostname === $hostname,
        ));

        return new CustomHostnamePage(
            items: array_slice($all, ($page - 1) * $perPage, $perPage),
            page: $page,
            perPage: $perPage,
            totalCount: count($all),
            totalPages: (int) ceil(count($all) / max(1, $perPage)),
        );
    }

    public function findByHostname(string $hostname): ?CustomHostname
    {
        return $this->listHostnames(hostname: $hostname)->items[0] ?? null;
    }

    public function revalidateHostname(string $id, SslOptions $ssl): CustomHostname
    {
        $this->throwPendingFailure();

        $payload = $this->payload($id);
        $this->revalidated[] = (string) $payload['hostname'];

        return CustomHostname::fromArray($payload);
    }

    public function deleteHostname(string $id): void
    {
        $this->throwPendingFailure();

        $this->deleted[] = (string) $this->payload($id)['hostname'];

        unset($this->hostnames[$id]);
    }

    public function getFallbackOrigin(): FallbackOrigin
    {
        $this->throwPendingFailure();

        return $this->fallbackOrigin ?? throw new ResourceNotFoundException('No fallback origin is set.', 404);
    }

    public function updateFallbackOrigin(string $origin): FallbackOrigin
    {
        $this->throwPendingFailure();

        return $this->fallbackOrigin = new FallbackOrigin($origin, FallbackOriginStatus::Active);
    }

    public function deleteFallbackOrigin(): FallbackOrigin
    {
        $current = $this->getFallbackOrigin();
        $this->fallbackOrigin = null;

        return $current;
    }

    /**
     * Puts a hostname on the fake zone, as if it had been created earlier. Pass the
     * id to line it up with an existing TenantDomain.
     *
     * @param  array<string, string>  $customMetadata
     */
    public function seed(string $hostname, ?SslOptions $ssl = null, array $customMetadata = [], ?string $id = null): CustomHostname
    {
        $ssl ??= new SslOptions;
        $id ??= (string) Str::uuid();

        $this->hostnames[$id] = [
            'id' => $id,
            'hostname' => $hostname,
            'status' => HostnameStatus::Pending->value,
            'created_at' => now()->toIso8601ZuluString(),
            'custom_metadata' => $customMetadata,
            'verification_errors' => ['custom hostname does not CNAME to this zone.'],
            'ownership_verification' => [
                'type' => 'txt',
                'name' => "_cf-custom-hostname.{$hostname}",
                'value' => (string) Str::uuid(),
            ],
            'ownership_verification_http' => [
                'http_url' => "http://{$hostname}/.well-known/cf-custom-hostname-challenge/{$id}",
                'http_body' => (string) Str::uuid(),
            ],
            'ssl' => [
                'id' => (string) Str::uuid(),
                'status' => SslStatus::PendingValidation->value,
                'method' => $ssl->method,
                'type' => $ssl->type,
                'wildcard' => false,
                'validation_errors' => [],
                'validation_records' => [$ssl->method === 'http'
                    ? [
                        'http_url' => "http://{$hostname}/.well-known/pki-validation/".Str::random(16).'.txt',
                        'http_body' => Str::random(32),
                        'status' => 'pending',
                    ]
                    : [
                        'txt_name' => "_acme-challenge.{$hostname}",
                        'txt_value' => Str::random(32),
                        'status' => 'pending',
                    ],
                ],
                'dcv_delegation_records' => [],
            ],
        ];

        return CustomHostname::fromArray($this->hostnames[$id]);
    }

    /**
     * Ownership passes: the hostname becomes active. The certificate is unchanged.
     */
    public function verifyHostname(string $hostname): self
    {
        return $this->patch($hostname, [
            'status' => HostnameStatus::Active->value,
            'verification_errors' => [],
            'ownership_verification' => null,
            'ownership_verification_http' => null,
        ]);
    }

    /**
     * The certificate is issued and deployed.
     */
    public function activateSsl(string $hostname, ?string $expiresOn = null): self
    {
        $id = $this->requireId($hostname);
        /** @var array<string, mixed> $ssl */
        $ssl = $this->hostnames[$id]['ssl'];

        return $this->patch($hostname, ['ssl' => [
            ...$ssl,
            'status' => SslStatus::Active->value,
            'validation_records' => [],
            'validation_errors' => [],
            'expires_on' => $expiresOn ?? now()->addDays(90)->toIso8601ZuluString(),
        ]]);
    }

    /**
     * Both of the above.
     */
    public function activate(string $hostname): self
    {
        return $this->verifyHostname($hostname)->activateSsl($hostname);
    }

    /**
     * Sets any status pair, including values this package does not know about.
     *
     * @param  list<string>  $errors  Reported as verification_errors.
     */
    public function setStatus(string $hostname, HostnameStatus|string $status, SslStatus|string|null $sslStatus = null, array $errors = []): self
    {
        $id = $this->requireId($hostname);
        /** @var array<string, mixed> $ssl */
        $ssl = $this->hostnames[$id]['ssl'];

        return $this->patch($hostname, [
            'status' => $status instanceof HostnameStatus ? $status->value : $status,
            'verification_errors' => $errors,
            'ssl' => [
                ...$ssl,
                'status' => match (true) {
                    $sslStatus instanceof SslStatus => $sslStatus->value,
                    is_string($sslStatus) => $sslStatus,
                    default => $ssl['status'],
                },
            ],
        ]);
    }

    /**
     * Empties ssl.validation_records, as Cloudflare does just after create.
     */
    public function withoutValidationRecords(string $hostname): self
    {
        $id = $this->requireId($hostname);
        /** @var array<string, mixed> $ssl */
        $ssl = $this->hostnames[$id]['ssl'];

        return $this->patch($hostname, ['ssl' => [...$ssl, 'validation_records' => []]]);
    }

    /**
     * Removes a hostname behind the package's back, as when validation expires.
     */
    public function forget(string $hostname): self
    {
        unset($this->hostnames[$this->requireId($hostname)]);

        return $this;
    }

    /**
     * The next client call throws this.
     */
    public function failNextWith(Throwable $exception): self
    {
        $this->nextFailure = $exception;

        return $this;
    }

    public function assertCreated(string $hostname): void
    {
        Assert::assertContains($hostname, $this->created, "Expected \"{$hostname}\" to be created on Cloudflare.");
    }

    public function assertNotCreated(string $hostname): void
    {
        Assert::assertNotContains($hostname, $this->created, "Expected \"{$hostname}\" not to be created on Cloudflare.");
    }

    public function assertCreatedCount(int $count): void
    {
        Assert::assertCount($count, $this->created, 'Unexpected number of hostnames created on Cloudflare.');
    }

    public function assertNothingCreated(): void
    {
        $this->assertCreatedCount(0);
    }

    public function assertDeleted(string $hostname): void
    {
        Assert::assertContains($hostname, $this->deleted, "Expected \"{$hostname}\" to be deleted from Cloudflare.");
    }

    public function assertNothingDeleted(): void
    {
        Assert::assertSame([], $this->deleted, 'Expected no hostnames to be deleted from Cloudflare.');
    }

    public function assertRevalidated(string $hostname): void
    {
        Assert::assertContains($hostname, $this->revalidated, "Expected validation of \"{$hostname}\" to be retried.");
    }

    public function assertNothingRevalidated(): void
    {
        Assert::assertSame([], $this->revalidated, 'Expected no validation retries.');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function patch(string $hostname, array $changes): self
    {
        $id = $this->requireId($hostname);
        $this->hostnames[$id] = [...$this->hostnames[$id], ...$changes];

        return $this;
    }

    private function idFor(string $hostname): ?string
    {
        foreach ($this->hostnames as $id => $payload) {
            if ($payload['hostname'] === $hostname) {
                return $id;
            }
        }

        return null;
    }

    private function requireId(string $hostname): string
    {
        return $this->idFor($hostname)
            ?? throw new ResourceNotFoundException("The fake zone has no hostname \"{$hostname}\".", 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $id): array
    {
        return $this->hostnames[$id]
            ?? throw new ResourceNotFoundException("The fake zone has no hostname with id \"{$id}\".", 404);
    }

    private function throwPendingFailure(): void
    {
        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }
    }
}
