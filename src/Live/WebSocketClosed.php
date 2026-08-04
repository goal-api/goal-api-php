<?php

declare(strict_types=1);

namespace GoalApi\Live;

use RuntimeException;

/**
 * The peer sent a close frame.
 *
 * Internal control flow rather than an error for callers to catch: LiveClient turns it
 * into either a reconnect or a ConnectionException, depending on how it is configured.
 * Code 4001 is the one worth knowing, since websocket-service sends it when the auth frame
 * was not the first message on the connection.
 *
 * It is `$closeCode`, not `$code`, for the same reason GoalApiException uses `$errorCode`:
 * Exception::$code already exists and is not readonly, so redeclaring it is a fatal error.
 *
 * @internal
 */
final class WebSocketClosed extends RuntimeException
{
    public function __construct(
        public readonly int $closeCode,
        public readonly string $reason = '',
    ) {
        parent::__construct(
            $reason === ''
                ? "WebSocket closed with code {$closeCode}"
                : "WebSocket closed with code {$closeCode}: {$reason}",
            $closeCode
        );
    }
}
