<?php

declare(strict_types=1);

namespace GoalApi\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for everything this SDK throws.
 *
 * `$errorCode` holds the API's code (e.g. VALIDATION_ERROR). It is not called `code`
 * because Exception::$code is an int and already carries the HTTP status.
 */
class GoalApiException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $category = null,
        public readonly mixed $details = null,
        public readonly ?string $correlationId = null,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function __toString(): string
    {
        $parts = [static::class . ': ' . $this->getMessage()];
        if ($this->errorCode !== null) {
            $parts[] = "code={$this->errorCode}";
        }
        if ($this->status !== null) {
            $parts[] = "status={$this->status}";
        }
        if ($this->correlationId !== null) {
            $parts[] = "correlationId={$this->correlationId}";
        }

        return implode(' ', $parts);
    }
}
