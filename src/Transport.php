<?php

declare(strict_types=1);

namespace GoalApi;

use GoalApi\Exceptions\AuthenticationException;
use GoalApi\Exceptions\ConflictException;
use GoalApi\Exceptions\ConnectionException;
use GoalApi\Exceptions\GoalApiException;
use GoalApi\Exceptions\NotFoundException;
use GoalApi\Exceptions\PermissionException;
use GoalApi\Exceptions\PlanUpgradeRequiredException;
use GoalApi\Exceptions\RateLimitException;
use GoalApi\Exceptions\ServerException;
use GoalApi\Exceptions\ServiceUnavailableException;
use GoalApi\Exceptions\TimeoutException;
use GoalApi\Exceptions\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Auth, query encoding, retries and rate-limit tracking.
 *
 * @internal
 */
final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.goal-api.com/v1';
    public const VERSION = '1.0.0';

    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    private readonly string $userAgent;

    private RateLimit $rateLimit;

    /** @var callable(string, string, list<string>, ?string): array{0: int, 1: array<string, string>, 2: string} */
    private readonly mixed $httpHandler;

    /**
     * @param array<string, string> $headers
     * @param null|callable(string, string, list<string>, ?string): array{0: int, 1: array<string, string>, 2: string} $httpHandler
     *   Replaces the curl call. Used by the tests, and by apps with a mandated HTTP stack
     *   (Guzzle, PSR-18, an instrumented client).
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 30.0,
        private readonly int $maxRetries = 2,
        private readonly array $headers = [],
        ?string $userAgent = null,
        ?callable $httpHandler = null,
    ) {
        if ($apiKey === '') {
            throw new InvalidArgumentException(
                'apiKey is required. Create one at https://goal-api.com/dashboard'
            );
        }
        if ($httpHandler === null && !\extension_loaded('curl')) {
            throw new \RuntimeException(
                'The GOAL API SDK requires the curl extension, or an $httpHandler to replace it.'
            );
        }

        $this->userAgent = $userAgent ?? 'goal-api-php/' . self::VERSION;
        $this->httpHandler = $httpHandler ?? $this->curlHandler();
        $this->rateLimit = new RateLimit();
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * The User-Agent sent on every request, and on the live WebSocket handshake.
     */
    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Caller-supplied headers, so the WebSocket handshake carries the same ones as REST.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function rateLimit(): RateLimit
    {
        return $this->rateLimit;
    }

    /**
     * Drops nulls and empty strings, and joins arrays with commas.
     *
     * Callers pass whole option arrays with keys unset, and `?season=` is a 400. Booleans
     * go out as `true`/`false`, which is what the validators check for.
     *
     * @param array<string, mixed>|null $params
     */
    public static function encodeParams(?array $params): string
    {
        if ($params === null || $params === []) {
            return '';
        }

        $encoded = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (\is_bool($value)) {
                $encoded[$key] = $value ? 'true' : 'false';
            } elseif (\is_array($value)) {
                $joined = implode(',', array_map(static fn ($v) => (string) $v, $value));
                if ($joined !== '') {
                    $encoded[$key] = $joined;
                }
            } else {
                $encoded[$key] = (string) $value;
            }
        }

        return $encoded === [] ? '' : '?' . http_build_query($encoded);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function get(string $path, ?array $params = null): array
    {
        $url = $this->baseUrl() . $path . self::encodeParams($params);

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $this->once('GET', $url);
            } catch (GoalApiException $error) {
                $lastError = $error;
                if ($attempt === $this->maxRetries || !$this->shouldRetry($error)) {
                    throw $error;
                }
                usleep((int) ($this->backoffSeconds($attempt, $error) * 1_000_000));
            }
        }

        throw $lastError; // @codeCoverageIgnore: the loop always returns or throws
    }

    /**
     * Not retried: /ws/token is single-use, so a retry would burn the token the first
     * attempt may already have minted.
     *
     * @return array<string, mixed>
     */
    public function post(string $path, mixed $body = null): array
    {
        return $this->once('POST', $this->baseUrl() . $path, $body ?? new \stdClass());
    }

    /**
     * @return array<string, mixed>
     */
    private function once(string $method, string $url, mixed $body = null): array
    {
        $requestHeaders = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $requestHeaders[] = 'Content-Type: application/json';
        }
        foreach ($this->headers as $name => $value) {
            $requestHeaders[] = "{$name}: {$value}";
        }

        $encodedBody = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);

        [$status, $responseHeaders, $raw] = ($this->httpHandler)($method, $url, $requestHeaders, $encodedBody);

        $this->rateLimit = RateLimit::fromHeaders($responseHeaders, $this->rateLimit);

        $parsed = self::parseBody($raw);
        if ($status >= 400) {
            throw self::errorFromResponse($status, $parsed, $responseHeaders);
        }

        return $parsed ?? [];
    }

    /**
     * curl, wrapped to the $httpHandler signature. No Guzzle, so the SDK installs into a
     * project with a pinned or conflicting HTTP client.
     *
     * @return callable(string, string, list<string>, ?string): array{0: int, 1: array<string, string>, 2: string}
     */
    private function curlHandler(): callable
    {
        $timeout = $this->timeout;
        $userAgent = $this->userAgent;

        return static function (string $method, string $url, array $requestHeaders, ?string $body) use ($timeout, $userAgent): array {
            $handle = curl_init();
            $responseHeaders = [];

            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 10.0) * 1000),
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_ENCODING => '',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($_handle, string $header) use (&$responseHeaders): int {
                    $length = \strlen($header);
                    $parts = explode(':', $header, 2);
                    if (\count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return $length;
                },
            ]);

            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }

            $raw = curl_exec($handle);
            $errno = curl_errno($handle);
            $errorMessage = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            if ($raw === false || $errno !== 0) {
                if (\in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT], true)) {
                    throw new TimeoutException("Request to {$url} timed out after {$timeout}s");
                }
                throw new ConnectionException("Could not reach {$url}: {$errorMessage}");
            }

            return [$status, $responseHeaders, (string) $raw];
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseBody(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // nginx returns HTML for 502s. Keep an excerpt so the message is still useful.
            return ['message' => trim(substr($raw, 0, 200))];
        }

        return \is_array($decoded) ? $decoded : ['data' => $decoded];
    }

    /**
     * Maps a non-2xx response to an exception.
     *
     * Two error shapes: the gateway sends `message`, football-service sends `error`.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    public static function errorFromResponse(int $status, ?array $body, array $headers): GoalApiException
    {
        $body ??= [];
        $message = (string) ($body['message'] ?? $body['error'] ?? "HTTP {$status}");
        $code = isset($body['code']) ? (string) $body['code'] : null;
        $category = isset($body['category']) ? (string) $body['category'] : null;
        $details = $body['details'] ?? null;
        $correlationId = isset($body['correlationId']) ? (string) $body['correlationId'] : null;

        if ($status === 429) {
            return new RateLimitException(
                $message,
                $status,
                $code,
                $category,
                $details,
                $correlationId,
                $headers,
                null,
                self::intHeader($headers, 'retry-after'),
                self::intHeader($headers, 'x-ratelimit-limit'),
                self::intHeader($headers, 'x-ratelimit-remaining'),
                self::intHeader($headers, 'x-ratelimit-reset'),
                $headers['x-ratelimit-type'] ?? null,
            );
        }

        $class = match (true) {
            $status === 400, $status === 422 => ValidationException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => PlanUpgradeRequiredException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 503 => ServiceUnavailableException::class,
            $status >= 500 => ServerException::class,
            default => GoalApiException::class,
        };

        return new $class($message, $status, $code, $category, $details, $correlationId, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function intHeader(array $headers, string $name): ?int
    {
        $raw = $headers[$name] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function shouldRetry(GoalApiException $error): bool
    {
        if ($error instanceof ConnectionException) {
            return true;
        }

        return \in_array($error->status, self::RETRYABLE_STATUS, true);
    }

    /**
     * Exponential backoff with jitter. A server-sent Retry-After takes priority.
     */
    private function backoffSeconds(int $attempt, GoalApiException $error): float
    {
        if ($error instanceof RateLimitException && $error->retryAfter !== null) {
            return (float) min($error->retryAfter, 60);
        }

        $ceilingMs = min(500 * (2 ** $attempt), 8000);

        return random_int(0, $ceilingMs) / 1000;
    }

    /**
     * Ids, dates and country names come from callers, so they can't be interpolated raw.
     */
    public static function segment(mixed $value): string
    {
        $string = \is_scalar($value) ? (string) $value : '';
        if ($string === '') {
            throw new InvalidArgumentException('Path segment is required');
        }

        return rawurlencode($string);
    }

    /**
     * An array or an already-joined string, out as comma-separated.
     */
    public static function csv(array|string $value): string
    {
        return \is_string($value) ? $value : implode(',', array_map(static fn ($v) => (string) $v, $value));
    }
}
