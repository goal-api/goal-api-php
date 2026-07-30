<?php

declare(strict_types=1);

namespace GoalApi\Exceptions;

use Throwable;

/**
 * 429.
 */
final class RateLimitException extends GoalApiException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        ?string $category = null,
        mixed $details = null,
        ?string $correlationId = null,
        array $headers = [],
        ?Throwable $previous = null,
        /** Seconds to wait, from Retry-After. */
        public readonly ?int $retryAfter = null,
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        /** When the window rolls over, as unix seconds. */
        public readonly ?int $reset = null,
        /** "DAILY" or "MONTHLY". */
        public readonly ?string $rateLimitType = null,
    ) {
        parent::__construct($message, $status, $errorCode, $category, $details, $correlationId, $headers, $previous);
    }
}
