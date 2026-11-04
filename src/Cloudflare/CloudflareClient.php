<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Data\ApiError;
use YourVendor\CloudflareSaasDomains\Data\CustomHostname;
use YourVendor\CloudflareSaasDomains\Data\CustomHostnamePage;
use YourVendor\CloudflareSaasDomains\Data\FallbackOrigin;
use YourVendor\CloudflareSaasDomains\Data\SslOptions;
use YourVendor\CloudflareSaasDomains\Exceptions\AuthenticationFailedException;
use YourVendor\CloudflareSaasDomains\Exceptions\CloudflareApiException;
use YourVendor\CloudflareSaasDomains\Exceptions\ConnectionFailedException;
use YourVendor\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidRequestException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidResponseException;
use YourVendor\CloudflareSaasDomains\Exceptions\MissingConfigurationException;
use YourVendor\CloudflareSaasDomains\Exceptions\PermissionDeniedException;
use YourVendor\CloudflareSaasDomains\Exceptions\RateLimitedException;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use YourVendor\CloudflareSaasDomains\Exceptions\ServerErrorException;
use YourVendor\CloudflareSaasDomains\Support\Payload;

final class CloudflareClient implements CustomHostnameClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ClientOptions $options,
    ) {}

    public function createHostname(string $hostname, SslOptions $ssl, array $customMetadata = []): CustomHostname
    {
        $body = ['hostname' => $hostname, 'ssl' => $ssl->toArray()];

        if ($customMetadata !== []) {
            $body['custom_metadata'] = $customMetadata;
        }

        return CustomHostname::fromArray($this->result('POST', $this->hostnamesPath(), ['json' => $body]));
    }

    public function getHostname(string $id): CustomHostname
    {
        return CustomHostname::fromArray($this->result('GET', $this->hostnamePath($id)));
    }

    public function listHostnames(int $page = 1, int $perPage = 50, ?string $hostname = null): CustomHostnamePage
    {
        $query = ['page' => $page, 'per_page' => $perPage];

        if ($hostname !== null) {
            $query['hostname.exact'] = $hostname;
        }

        $body = $this->send('GET', $this->hostnamesPath(), ['query' => $query]);
        $info = Payload::array($body, 'result_info') ?? [];

        return new CustomHostnamePage(
            items: array_map(CustomHostname::fromArray(...), Payload::arrays($body, 'result')),
            page: Payload::int($info, 'page', $page),
            perPage: Payload::int($info, 'per_page', $perPage),
            totalCount: Payload::int($info, 'total_count'),
            totalPages: Payload::int($info, 'total_pages'),
        );
    }

    public function findByHostname(string $hostname): ?CustomHostname
    {
        foreach ($this->listHostnames(hostname: $hostname)->items as $item) {
            if ($item->hostname === $hostname) {
                return $item;
            }
        }

        return null;
    }

    public function revalidateHostname(string $id, SslOptions $ssl): CustomHostname
    {
        return CustomHostname::fromArray(
            $this->result('PATCH', $this->hostnamePath($id), ['json' => ['ssl' => $ssl->toArray()]]),
        );
    }

    public function deleteHostname(string $id): void
    {
        $this->send('DELETE', $this->hostnamePath($id));
    }

    public function getFallbackOrigin(): FallbackOrigin
    {
        return FallbackOrigin::fromArray($this->result('GET', $this->fallbackOriginPath()));
    }

    public function updateFallbackOrigin(string $origin): FallbackOrigin
    {
        return FallbackOrigin::fromArray(
            $this->result('PUT', $this->fallbackOriginPath(), ['json' => ['origin' => $origin]]),
        );
    }

    public function deleteFallbackOrigin(): FallbackOrigin
    {
        return FallbackOrigin::fromArray($this->result('DELETE', $this->fallbackOriginPath()));
    }

    private function hostnamesPath(): string
    {
        return 'zones/'.rawurlencode($this->requireConfig($this->options->zoneId, 'zone_id')).'/custom_hostnames';
    }

    private function hostnamePath(string $id): string
    {
        return $this->hostnamesPath().'/'.rawurlencode($id);
    }

    private function fallbackOriginPath(): string
    {
        return $this->hostnamesPath().'/fallback_origin';
    }

    private function requireConfig(?string $value, string $key): string
    {
        if ($value === null || $value === '') {
            throw new MissingConfigurationException("Set cloudflare-saas-domains.{$key} before calling Cloudflare.");
        }

        return $value;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->options->baseUrl)
            ->withToken($this->requireConfig($this->options->apiToken, 'api_token'))
            ->acceptJson()
            ->timeout($this->options->timeout)
            ->connectTimeout($this->options->connectTimeout);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<array-key, mixed>
     */
    private function result(string $method, string $path, array $options = []): array
    {
        $result = Payload::array($this->send($method, $path, $options), 'result');

        if ($result === null) {
            throw new InvalidResponseException("Cloudflare returned no result for {$method} {$path}.");
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<array-key, mixed>
     */
    private function send(string $method, string $path, array $options = []): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $canRetry = $attempt <= $this->options->retries;

            try {
                $response = $this->request()->send($method, $path, $options);
            } catch (ConnectionException $exception) {
                if (! $canRetry) {
                    throw new ConnectionFailedException(
                        "Could not reach Cloudflare for {$method} {$path}: {$exception->getMessage()}",
                        previous: $exception,
                    );
                }

                $this->backoff($attempt);

                continue;
            }

            if ($canRetry && ($response->status() === 429 || $response->serverError())) {
                $this->backoff($attempt, $this->retryAfter($response));

                continue;
            }

            return $this->decode($response, $method, $path);
        }
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds = null): void
    {
        $exponential = $this->options->retryBaseMs * (2 ** ($attempt - 1));
        $jitter = $this->options->retryJitterMs > 0 ? random_int(0, $this->options->retryJitterMs) : 0;
        $delay = $retryAfterSeconds !== null ? $retryAfterSeconds * 1000 : $exponential + $jitter;

        Sleep::for(min($delay, $this->options->retryMaxMs))->milliseconds();
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(Response $response, string $method, string $path): array
    {
        $body = $response->json();

        if ($response->successful()) {
            if (! is_array($body)) {
                throw new InvalidResponseException(
                    "Cloudflare returned a non-JSON body for {$method} {$path}.",
                    $response->status(),
                );
            }

            if (($body['success'] ?? true) === false) {
                throw $this->mapError($response, $body, $method, $path);
            }

            return $body;
        }

        throw $this->mapError($response, is_array($body) ? $body : [], $method, $path);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function mapError(Response $response, array $body, string $method, string $path): CloudflareApiException
    {
        $status = $response->status();
        $errors = array_map(ApiError::fromArray(...), Payload::arrays($body, 'errors'));
        $details = implode('; ', array_map(
            static fn (ApiError $error): string => trim("{$error->code} {$error->message}"),
            $errors,
        ));
        $message = "Cloudflare API error on {$method} {$path} (HTTP {$status})".($details === '' ? '.' : ": {$details}");

        return match (true) {
            $this->isDuplicate($status, $errors) => new DuplicateHostnameException($message, $status, $errors),
            $status === 401 => new AuthenticationFailedException($message, $status, $errors),
            $status === 403 => new PermissionDeniedException($message, $status, $errors),
            $status === 404 => new ResourceNotFoundException($message, $status, $errors),
            $status === 429 => new RateLimitedException($message, $status, $errors, $this->retryAfter($response)),
            $status >= 500 => new ServerErrorException($message, $status, $errors),
            default => new InvalidRequestException($message, $status, $errors),
        };
    }

    /**
     * Cloudflare documents no numeric code for this. See PLAN.md, assumption 1.
     *
     * @param  list<ApiError>  $errors
     */
    private function isDuplicate(int $status, array $errors): bool
    {
        if ($status === 409) {
            return true;
        }

        if ($status >= 500 || in_array($status, [401, 403, 404, 429], true)) {
            return false;
        }

        foreach ($errors as $error) {
            $message = strtolower($error->message);

            if (str_contains($message, 'duplicate') || str_contains($message, 'already exists')) {
                return true;
            }
        }

        return false;
    }
}
