<?php

declare(strict_types=1);

namespace GoalApi;

use GoalApi\Exceptions\WebhookSignatureException;
use JsonException;

/**
 * Inbound webhook signature verification.
 *
 * Signature format is `t=<unix>,v1=<hex>`, where the HMAC-SHA256 covers
 * `"<timestamp>.<raw body>"` keyed with the endpoint secret.
 */
final class Webhook
{
    public const EVENT_MATCH_STARTED = 'match.started';
    public const EVENT_MATCH_FINISHED = 'match.finished';
    public const EVENT_GOAL_SCORED = 'goal.scored';
    public const EVENT_SCORE_CHANGED = 'score.changed';
    public const EVENT_MATCH_STATUS_CHANGED = 'match.status_changed';

    public const EVENTS = [
        self::EVENT_MATCH_STARTED,
        self::EVENT_MATCH_FINISHED,
        self::EVENT_GOAL_SCORED,
        self::EVENT_SCORE_CHANGED,
        self::EVENT_MATCH_STATUS_CHANGED,
    ];

    public const SIGNATURE_HEADER = 'HTTP_X_GOAL_SIGNATURE';
    public const EVENT_HEADER = 'HTTP_X_GOAL_EVENT';
    public const DELIVERY_HEADER = 'HTTP_X_GOAL_DELIVERY';

    /** Seconds of clock skew and latency accepted before treating a delivery as a replay. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verifies an inbound webhook and returns the decoded event.
     *
     * $payload must be the raw body: `file_get_contents('php://input')`, not `$_POST`.
     * Re-encoding a decoded array reorders keys and changes whitespace, which breaks the
     * HMAC.
     *
     * ```php
     * $event = Webhook::verify(
     *     file_get_contents('php://input'),
     *     $_SERVER[Webhook::SIGNATURE_HEADER] ?? null,
     *     getenv('GOAL_WEBHOOK_SECRET'),
     * );
     * ```
     *
     * $tolerance = 0 skips the timestamp check -- only sensible if you dedupe on
     * `X-Goal-Delivery` yourself.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookSignatureException
     */
    public static function verify(
        string $payload,
        ?string $signatureHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): array {
        if ($secret === '') {
            throw new WebhookSignatureException('Webhook secret is required');
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new WebhookSignatureException('Missing X-Goal-Signature header');
        }

        [$timestamp, $signature] = self::parseSignatureHeader($signatureHeader);

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            throw new WebhookSignatureException('Webhook signature does not match');
        }

        if ($tolerance > 0) {
            $age = abs(time() - (int) $timestamp);
            if ($age > $tolerance) {
                throw new WebhookSignatureException(
                    "Webhook timestamp is {$age}s old, outside the {$tolerance}s tolerance"
                );
            }
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new WebhookSignatureException('Webhook body is not valid JSON', previous: $error);
        }

        if (!\is_array($event)) {
            throw new WebhookSignatureException('Webhook body is not a JSON object');
        }

        return $event;
    }

    /**
     * @return array{0: string, 1: string} [timestamp, signature]
     */
    private static function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pieces = explode('=', $part, 2);
            if (\count($pieces) !== 2) {
                continue;
            }
            $key = trim($pieces[0]);
            $value = trim($pieces[1]);
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null || !ctype_xdigit($signature)) {
            throw new WebhookSignatureException("Malformed X-Goal-Signature header: {$header}");
        }

        return [$timestamp, $signature];
    }
}
