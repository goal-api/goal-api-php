<?php

declare(strict_types=1);

namespace GoalApi;

/**
 * Quota from the last response.
 */
final class RateLimit
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        /** When the window rolls over, as unix seconds. */
        public readonly ?int $reset = null,
        /** "DAILY" or "MONTHLY". */
        public readonly ?string $type = null,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function fromHeaders(array $headers, self $previous): self
    {
        // /public/* sends RateLimit-* (draft standard) instead, so leave the snapshot alone.
        if (!isset($headers['x-ratelimit-limit']) && !isset($headers['x-ratelimit-type'])) {
            return $previous;
        }

        $num = static function (?string $raw): ?int {
            return $raw !== null && $raw !== '' && is_numeric($raw) ? (int) $raw : null;
        };

        return new self(
            $num($headers['x-ratelimit-limit'] ?? null),
            $num($headers['x-ratelimit-remaining'] ?? null),
            $num($headers['x-ratelimit-reset'] ?? null),
            $headers['x-ratelimit-type'] ?? null,
        );
    }

    /**
     * @return array{limit: int|null, remaining: int|null, reset: int|null, type: string|null}
     */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'reset' => $this->reset,
            'type' => $this->type,
        ];
    }
}
