<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YourVendor\CloudflareSaasDomains\Cloudflare\ClientOptions;
use YourVendor\CloudflareSaasDomains\Cloudflare\CloudflareClient;
use YourVendor\CloudflareSaasDomains\Contracts\CustomHostnameClient;
use YourVendor\CloudflareSaasDomains\Data\ApiError;
use YourVendor\CloudflareSaasDomains\Data\SslOptions;
use YourVendor\CloudflareSaasDomains\Enums\FallbackOriginStatus;
use YourVendor\CloudflareSaasDomains\Exceptions\AuthenticationFailedException;
use YourVendor\CloudflareSaasDomains\Exceptions\ConnectionFailedException;
use YourVendor\CloudflareSaasDomains\Exceptions\DuplicateHostnameException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidRequestException;
use YourVendor\CloudflareSaasDomains\Exceptions\InvalidResponseException;
use YourVendor\CloudflareSaasDomains\Exceptions\MissingConfigurationException;
use YourVendor\CloudflareSaasDomains\Exceptions\PermissionDeniedException;
use YourVendor\CloudflareSaasDomains\Exceptions\RateLimitedException;
use YourVendor\CloudflareSaasDomains\Exceptions\ResourceNotFoundException;
use YourVendor\CloudflareSaasDomains\Exceptions\ServerErrorException;

beforeEach(function () {
    Sleep::fake();
    $this->client = app(CustomHostnameClient::class);
});

it('creates a hostname with the documented request', function () {
    Http::fake([HOSTNAMES_URL => Http::response(envelope(hostnamePayload()))]);

    $hostname = $this->client->createHostname('shop.acme.com', new SslOptions('txt', 'dv'), ['plan' => 'pro']);

    expect($hostname->id)->toBe('cf-id-1');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === HOSTNAMES_URL
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->data() === [
            'hostname' => 'shop.acme.com',
            'ssl' => ['method' => 'txt', 'type' => 'dv'],
            'custom_metadata' => ['plan' => 'pro'],
        ]);
});

it('gets a hostname by id', function () {
    Http::fake([HOSTNAMES_URL.'/cf-id-1' => Http::response(envelope(hostnamePayload()))]);

    expect($this->client->getHostname('cf-id-1')->hostname)->toBe('shop.acme.com');
});

it('url-encodes ids', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);

    $this->client->getHostname('../evil');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/custom_hostnames/..%2Fevil'));
});

it('lists hostnames with pagination details', function () {
    Http::fake(['*' => Http::response([
        ...envelope([hostnamePayload()]),
        'result_info' => ['page' => 2, 'per_page' => 5, 'total_count' => 6, 'total_pages' => 2],
    ])]);

    $page = $this->client->listHostnames(2, 5);

    expect($page->items)->toHaveCount(1)
        ->and($page->page)->toBe(2)
        ->and($page->perPage)->toBe(5)
        ->and($page->totalCount)->toBe(6)
        ->and($page->totalPages)->toBe(2);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page=2') && str_contains($request->url(), 'per_page=5'));
});

it('finds a hostname by exact match only', function () {
    Http::fake(['*' => Http::response(envelope([
        hostnamePayload(['id' => 'other', 'hostname' => 'xshop.acme.com']),
        hostnamePayload(),
    ]))]);

    expect($this->client->findByHostname('shop.acme.com')?->id)->toBe('cf-id-1');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'hostname.exact=shop.acme.com'));
});

it('returns null when no hostname matches', function () {
    Http::fake(['*' => Http::response(envelope([hostnamePayload(['hostname' => 'xshop.acme.com'])]))]);

    expect($this->client->findByHostname('shop.acme.com'))->toBeNull();
});

it('re-validates with a PATCH carrying the ssl object', function () {
    Http::fake(['*' => Http::response(envelope(hostnamePayload()))]);

    $this->client->revalidateHostname('cf-id-1', new SslOptions('http', 'dv'));

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === HOSTNAMES_URL.'/cf-id-1'
        && $request->data() === ['ssl' => ['method' => 'http', 'type' => 'dv']]);
});

it('deletes a hostname and accepts the bare id response', function () {
    Http::fake(['*' => Http::response(['id' => 'cf-id-1'])]);

    $this->client->deleteHostname('cf-id-1');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === HOSTNAMES_URL.'/cf-id-1');
});

it('reads, updates and deletes the fallback origin', function () {
    $payload = ['origin' => 'fallback.saas.io', 'status' => 'active', 'errors' => []];
    Http::fake([HOSTNAMES_URL.'/fallback_origin' => Http::response(envelope($payload))]);

    expect($this->client->getFallbackOrigin()->status)->toBe(FallbackOriginStatus::Active)
        ->and($this->client->updateFallbackOrigin('fallback.saas.io')->origin)->toBe('fallback.saas.io')
        ->and($this->client->deleteFallbackOrigin()->origin)->toBe('fallback.saas.io');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->data() === ['origin' => 'fallback.saas.io']);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    Http::assertSent(fn (Request $request) => $request->method() === 'GET');
});

it('sets an explicit timeout on every call', function () {
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options[] = $requestOptions;

        return Http::response(envelope(str_contains($request->url(), 'per_page') ? [hostnamePayload()] : hostnamePayload()));
    });

    $this->client->getHostname('cf-id-1');
    $this->client->createHostname('shop.acme.com', new SslOptions);
    $this->client->revalidateHostname('cf-id-1', new SslOptions);
    $this->client->listHostnames();
    $this->client->deleteHostname('cf-id-1');

    expect($options)->toHaveCount(5);

    foreach ($options as $requestOptions) {
        expect($requestOptions['timeout'])->toBe(15)
            ->and($requestOptions['connect_timeout'])->toBe(5);
    }
});

it('maps HTTP errors to package exceptions', function (int $status, string $exception) {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response(errorEnvelope(1000, 'Nope'), $status)]);

    try {
        app(CustomHostnameClient::class)->getHostname('cf-id-1');
        $this->fail('Expected an exception.');
    } catch (Throwable $thrown) {
        expect($thrown)->toBeInstanceOf($exception)
            ->and($thrown->statusCode)->toBe($status)
            ->and($thrown->errors[0])->toBeInstanceOf(ApiError::class)
            ->and($thrown->errors[0]->code)->toBe(1000)
            ->and($thrown->getMessage())->toContain('1000 Nope')
            ->and($thrown->getMessage())->not->toContain('test-token');
    }
})->with([
    '400' => [400, InvalidRequestException::class],
    '401' => [401, AuthenticationFailedException::class],
    '403' => [403, PermissionDeniedException::class],
    '404' => [404, ResourceNotFoundException::class],
    '409' => [409, DuplicateHostnameException::class],
    '422' => [422, InvalidRequestException::class],
    '429' => [429, RateLimitedException::class],
    '500' => [500, ServerErrorException::class],
    '502' => [502, ServerErrorException::class],
    '503' => [503, ServerErrorException::class],
]);

it('detects duplicates from the error message on a 400', function (string $message) {
    Http::fake(['*' => Http::response(errorEnvelope(1234, $message), 400)]);

    $this->client->createHostname('shop.acme.com', new SslOptions);
})->throws(DuplicateHostnameException::class)->with([
    ['Duplicate custom hostname found.'],
    ['Hostname already exists'],
]);

it('does not treat an auth message as a duplicate', function () {
    Http::fake(['*' => Http::response(errorEnvelope(1, 'duplicate token?'), 403)]);

    $this->client->getHostname('x');
})->throws(PermissionDeniedException::class);

it('treats HTTP 200 with success false as an error', function () {
    Http::fake(['*' => Http::response(errorEnvelope(1414, 'Bad hostname'))]);

    $this->client->getHostname('x');
})->throws(InvalidRequestException::class, 'Bad hostname');

it('rejects a non-JSON success body', function () {
    Http::fake(['*' => Http::response('<html>oops</html>')]);

    $this->client->getHostname('x');
})->throws(InvalidResponseException::class);

it('rejects a success response without a result', function () {
    Http::fake(['*' => Http::response(['success' => true, 'errors' => [], 'result' => null])]);

    $this->client->getHostname('x');
})->throws(InvalidResponseException::class);

it('handles an error without a JSON body', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response('Bad gateway', 502)]);

    app(CustomHostnameClient::class)->getHostname('x');
})->throws(ServerErrorException::class, 'HTTP 502');

it('retries 429 and 5xx with exponential backoff, then succeeds', function () {
    Http::fake(['*' => Http::sequence()
        ->push(errorEnvelope(1, 'busy'), 429)
        ->push(errorEnvelope(2, 'oops'), 500)
        ->push(errorEnvelope(2, 'oops'), 503)
        ->push(envelope(hostnamePayload()))]);

    expect($this->client->getHostname('cf-id-1')->id)->toBe('cf-id-1');

    Http::assertSentCount(4);
    Sleep::assertSequence([
        Sleep::for(500)->milliseconds(),
        Sleep::for(1000)->milliseconds(),
        Sleep::for(2000)->milliseconds(),
    ]);
});

it('gives up after the configured retries and throws', function () {
    Http::fake(['*' => Http::response(errorEnvelope(2, 'oops'), 500)]);

    try {
        $this->client->getHostname('cf-id-1');
        $this->fail('Expected an exception.');
    } catch (ServerErrorException $exception) {
        expect($exception->statusCode)->toBe(500);
    }

    Http::assertSentCount(4);
    Sleep::assertSleptTimes(3);
});

it('honors Retry-After on a 429', function () {
    Http::fake(['*' => Http::sequence()
        ->push(errorEnvelope(1, 'busy'), 429, ['Retry-After' => '2'])
        ->push(envelope(hostnamePayload()))]);

    $this->client->getHostname('cf-id-1');

    Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
});

it('stops retrying when Retry-After is longer than it will wait', function () {
    Http::fake(['*' => Http::response(errorEnvelope(1, 'busy'), 429, ['Retry-After' => '3600'])]);

    try {
        $this->client->getHostname('cf-id-1');
        $this->fail('Expected an exception.');
    } catch (RateLimitedException $exception) {
        expect($exception->retryAfterSeconds)->toBe(3600);
    }

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('exposes Retry-After once retries are exhausted', function () {
    config()->set('cloudflare-saas-domains.http.retries', 0);
    Http::fake(['*' => Http::response(errorEnvelope(1, 'busy'), 429, ['Retry-After' => '30'])]);

    try {
        app(CustomHostnameClient::class)->getHostname('x');
        $this->fail('Expected an exception.');
    } catch (RateLimitedException $exception) {
        expect($exception->retryAfterSeconds)->toBe(30);
    }
});

it('adds jitter to the backoff when configured', function () {
    $client = new CloudflareClient(httpFactory(), new ClientOptions('t', 'z', retries: 1, retryBaseMs: 100, retryJitterMs: 50));
    Http::fake(['*' => Http::sequence()->push(errorEnvelope(2, 'oops'), 500)->push(envelope(hostnamePayload()))]);

    $client->getHostname('x');

    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds >= 100 && $duration->totalMilliseconds <= 150, 1);
});

it('does not retry client errors', function (int $status) {
    Http::fake(['*' => Http::response(errorEnvelope(1, 'no'), $status)]);

    try {
        $this->client->getHostname('x');
    } catch (Throwable) {
    }

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([400, 401, 403, 404, 409]);

it('retries connection failures, then raises a package exception', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28: timed out');
    });

    try {
        $this->client->getHostname('cf-id-1');
        $this->fail('Expected an exception.');
    } catch (ConnectionFailedException $exception) {
        expect($exception->getMessage())->toContain('timed out')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }

    expect($calls)->toBe(4);
});

it('recovers when a connection failure is transient', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw new ConnectionException('reset');
        }

        return Http::response(envelope(hostnamePayload()));
    });

    expect($this->client->getHostname('cf-id-1')->id)->toBe('cf-id-1');
});

it('fails clearly without credentials', function (string $key, string $message) {
    config()->set("cloudflare-saas-domains.{$key}", null);
    Http::fake();

    expect(fn () => app(CustomHostnameClient::class)->getHostname('x'))
        ->toThrow(MissingConfigurationException::class, $message);

    Http::assertNothingSent();
})->with([
    ['api_token', 'api_token'],
    ['zone_id', 'zone_id'],
]);
