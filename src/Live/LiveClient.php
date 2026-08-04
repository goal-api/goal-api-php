<?php

declare(strict_types=1);

namespace GoalApi\Live;

use GoalApi\Exceptions\AuthenticationException;
use GoalApi\Exceptions\ConnectionException;
use GoalApi\Exceptions\GoalApiException;
use GoalApi\Exceptions\PermissionException;
use GoalApi\Exceptions\PlanUpgradeRequiredException;
use GoalApi\Exceptions\RateLimitException;
use GoalApi\Transport;

/**
 * A blocking client for the live match feed.
 *
 * This is a php-cli tool. It holds a socket open for as long as you want updates, which is
 * exactly what PHP-FPM cannot do: a worker is tied to a request and will be recycled out
 * from under the connection. Run it in a CLI worker, a daemon, or a queue consumer, and
 * let a browser or a queue carry the updates the rest of the way. Constructing it under
 * FPM throws unless you pass `allowAnySapi: true`, which you should only do if you know
 * your SAPI can hold a long-lived socket (Swoole, RoadRunner, a custom embed).
 *
 * ```php
 * $live = $goal->live();
 * $live->on('match_update', function (array $msg) {
 *     echo $msg['data']['id'], PHP_EOL;
 * });
 * $live->connect();
 * $live->subscribe($fixtureId);
 * $live->run();
 * ```
 *
 * Or drive it yourself, which is what you want inside an existing loop:
 *
 * ```php
 * while ($msg = $live->receive(timeout: 1.0)) {
 *     // ... your own work between messages
 * }
 * ```
 *
 * Server message types: auth_success, match_update, pong, status, server_shutdown, error,
 * subscribe_response, unsubscribe_response, get_subscriptions_response. The SDK adds
 * `open` and `close` for the transport itself, and `*` receives everything.
 */
final class LiveClient
{
    public const EVENT_OPEN = 'open';
    public const EVENT_CLOSE = 'close';
    public const EVENT_ANY = '*';

    /** @var resource|null */
    private $socket = null;

    /** @var array<string, array<int, callable(array<string, mixed>): void>> */
    private array $handlers = [];

    /** @var array<string, true> */
    private array $subscriptions = [];

    private bool $authenticated = false;
    private bool $userClosed = false;
    private float $lastPingAt = 0.0;
    private string $buffer = '';
    private int $handlerId = 0;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $url,
        private readonly ?string $connectToken = null,
        private readonly bool $autoReconnect = true,
        private readonly ?int $maxReconnectAttempts = null,
        private readonly float $pingInterval = 30.0,
        private readonly float $authTimeout = 10.0,
        private readonly float $readTimeout = 90.0,
        private readonly float $connectTimeout = 30.0,
        /** @var array<string, mixed> */
        private readonly array $streamContext = [],
        bool $allowAnySapi = false,
    ) {
        if (!$allowAnySapi && !\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            throw new ConnectionException(
                'The live WebSocket client needs a long-lived process and PHP_SAPI is "' . \PHP_SAPI . '". '
                . 'Run it under php-cli, or mint a token with mintConnectToken() and connect from the browser. '
                . 'Pass allowAnySapi: true if your SAPI can hold a socket open.'
            );
        }
    }

    /**
     * Register a handler. Returns a closure that removes it again.
     *
     * Handlers fire from receive() and run(), in registration order, one message at a
     * time. A handler that blocks stops the feed.
     *
     * @param callable(array<string, mixed>): void $handler
     *
     * @return callable(): void
     */
    public function on(string $event, callable $handler): callable
    {
        $id = ++$this->handlerId;
        $this->handlers[$event][$id] = $handler;

        return function () use ($event, $id): void {
            unset($this->handlers[$event][$id]);
        };
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && $this->authenticated;
    }

    /**
     * The matches this connection is subscribed to. Replayed automatically on reconnect.
     *
     * @return list<string>
     */
    public function subscriptions(): array
    {
        $ids = array_keys($this->subscriptions);
        sort($ids);

        return $ids;
    }

    /**
     * Open the socket and complete the auth handshake.
     *
     * Returns once the server has accepted the auth frame, so it is safe to subscribe
     * immediately afterwards.
     */
    public function connect(): self
    {
        if ($this->userClosed) {
            throw new ConnectionException('This live client has been closed');
        }
        if ($this->socket !== null) {
            return $this;
        }

        $this->openSocket();
        $this->authenticate();
        $this->dispatch(['type' => self::EVENT_OPEN]);

        return $this;
    }

    /**
     * Dial and perform the HTTP upgrade.
     */
    private function openSocket(): void
    {
        $parts = parse_url($this->url);
        if ($parts === false || !isset($parts['host'])) {
            throw new ConnectionException("Invalid WebSocket URL: {$this->url}");
        }

        $scheme = $parts['scheme'] ?? 'wss';
        $secure = $scheme === 'wss' || $scheme === 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $context = stream_context_create($this->streamContext + [
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $transportScheme = $secure ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "{$transportScheme}://{$host}:{$port}",
            $errno,
            $errstr,
            $this->connectTimeout,
            \STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new ConnectionException(
                "Could not connect to {$host}:{$port}: " . ($errstr !== '' ? $errstr : "error {$errno}")
            );
        }

        $this->socket = $socket;
        $this->buffer = '';
        stream_set_timeout($socket, (int) $this->connectTimeout);

        $key = base64_encode(random_bytes(16));

        $headers = [
            "GET {$path} HTTP/1.1",
            "Host: {$host}" . (($secure && $port === 443) || (!$secure && $port === 80) ? '' : ":{$port}"),
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            'User-Agent: ' . $this->transport->userAgent(),
        ];

        // A token connection is browser-shaped: the token travels in the auth frame, so no
        // Authorization header goes on the handshake.
        if ($this->connectToken === null) {
            $headers[] = 'Authorization: Bearer ' . $this->transport->apiKey();
        }

        foreach ($this->transport->headers() as $name => $value) {
            // The handshake headers above are ours; a caller override would break it.
            if (\in_array(strtolower($name), ['upgrade', 'connection', 'sec-websocket-key', 'sec-websocket-version', 'host'], true)) {
                continue;
            }
            $headers[] = "{$name}: {$value}";
        }

        $this->writeRaw(implode("\r\n", $headers) . "\r\n\r\n");
        $this->readHandshakeResponse($key);
    }

    /**
     * Read and validate the 101 response.
     */
    private function readHandshakeResponse(string $key): void
    {
        $response = '';
        $deadline = microtime(true) + $this->connectTimeout;

        while (!str_contains($response, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                $this->dropSocket();
                throw new ConnectionException('Timed out waiting for the WebSocket handshake response');
            }
            $chunk = fgets($this->socket(), 8192);
            if ($chunk === false) {
                $this->dropSocket();
                throw new ConnectionException('Connection closed during the WebSocket handshake');
            }
            $response .= $chunk;
        }

        [$headerBlock] = explode("\r\n\r\n", $response, 2);
        $lines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($lines) ?? '';

        if (!preg_match('#^HTTP/1\.[01] (\d{3})#', $statusLine, $matches)) {
            $this->dropSocket();
            throw new ConnectionException("Malformed WebSocket handshake response: {$statusLine}");
        }

        $status = (int) $matches[1];
        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (\count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        if ($status !== 101) {
            // A non-101 carries a normal API error body, so surface it the way a REST
            // failure would be rather than as a bare socket complaint.
            $body = stream_get_contents($this->socket()) ?: '';
            $this->dropSocket();
            throw $this->handshakeError($status, $body);
        }

        $accept = $headers['sec-websocket-accept'] ?? '';
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!hash_equals($expected, $accept)) {
            $this->dropSocket();
            throw new ConnectionException('WebSocket accept key mismatch: the server did not complete the handshake');
        }

        if (strtolower($headers['upgrade'] ?? '') !== 'websocket') {
            $this->dropSocket();
            throw new ConnectionException('Server did not upgrade the connection');
        }
    }

    /**
     * Map a rejected handshake onto the SDK's exception hierarchy.
     */
    private function handshakeError(int $status, string $body): GoalApiException
    {
        $message = "WebSocket handshake rejected with HTTP {$status}";
        $code = null;

        $decoded = json_decode($body, true);
        if (\is_array($decoded)) {
            $error = $decoded['error'] ?? $decoded;
            if (\is_array($error)) {
                $message = \is_string($error['message'] ?? null) ? $error['message'] : $message;
                $code = \is_string($error['code'] ?? null) ? $error['code'] : null;
            }
        }

        return match ($status) {
            401 => new AuthenticationException($message, $status, $code),
            403 => new PermissionException($message, $status, $code),
            402 => new PlanUpgradeRequiredException($message, $status, $code),
            429 => new RateLimitException($message, $status, $code),
            default => new ConnectionException($message, $status, $code),
        };
    }

    /**
     * Send the auth frame and wait for auth_success.
     *
     * The server requires this to be the very first message and closes with 4001
     * otherwise, so nothing else may be written before it.
     */
    private function authenticate(): void
    {
        $auth = $this->connectToken !== null
            ? ['type' => 'auth', 'token' => $this->connectToken]
            : ['type' => 'auth', 'apiKey' => $this->transport->apiKey()];

        $this->writeRaw(Frame::encode(Frame::TEXT, json_encode($auth, \JSON_THROW_ON_ERROR)));

        $deadline = microtime(true) + $this->authTimeout;

        while (microtime(true) < $deadline) {
            $remaining = max(0.0, $deadline - microtime(true));
            // Same reason as in receive(): frames already buffered are invisible to
            // stream_select, so check the buffer before waiting on the socket.
            if ($this->buffer === '' && !$this->waitReadable($remaining)) {
                continue;
            }

            try {
                $message = $this->readMessage();
            } catch (WebSocketClosed $closed) {
                $this->dropSocket();
                if ($closed->closeCode === 4001) {
                    throw new AuthenticationException(
                        'Live authentication rejected: ' . ($closed->reason !== '' ? $closed->reason : 'invalid credentials'),
                        401
                    );
                }
                throw new ConnectionException(
                    "Connection closed during authentication with code {$closed->closeCode}: {$closed->reason}"
                );
            }

            if ($message === null) {
                continue;
            }

            $type = $message['type'] ?? null;
            if ($type === 'auth_success') {
                $this->authenticated = true;
                $this->lastPingAt = microtime(true);
                $this->dispatch($message);

                return;
            }
            if ($type === 'error') {
                $this->dropSocket();
                throw new AuthenticationException(
                    'Live authentication rejected: ' . (\is_string($message['message'] ?? null) ? $message['message'] : 'unknown error'),
                    401,
                    \is_string($message['code'] ?? null) ? $message['code'] : null
                );
            }
            // Anything else before auth_success is noise; keep reading until the deadline.
        }

        $this->dropSocket();
        throw new ConnectionException('Timed out waiting for auth_success');
    }

    /**
     * Wait for the next message, dispatching it to handlers before returning it.
     *
     * Returns null when the timeout expires with nothing to read, which is what makes it
     * usable as the body of your own loop. Blocks indefinitely when $timeout is null.
     *
     * @return array<string, mixed>|null
     */
    public function receive(?float $timeout = null): ?array
    {
        if ($this->userClosed) {
            return null;
        }
        if ($this->socket === null) {
            throw new ConnectionException('Not connected: call connect() first');
        }

        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            $this->maybePing();

            $wait = $deadline === null ? $this->pingInterval : max(0.0, $deadline - microtime(true));
            // Never block past the next keepalive, or a quiet feed would look dead.
            $wait = min($wait, $this->pingInterval);

            // Drain our own buffer before waiting on the socket. A single read can pull
            // several frames in one go -- the server answers five subscribes at once -- and
            // stream_select watches the socket, not this buffer, so selecting first would
            // block the full timeout with complete frames already in hand.
            if ($this->buffer !== '') {
                try {
                    $message = $this->readMessage();
                } catch (WebSocketClosed $closed) {
                    if (!$this->recover(new ConnectionException("Server closed the connection: {$closed->closeCode} {$closed->reason}"))) {
                        return null;
                    }
                    continue;
                } catch (ConnectionException $error) {
                    if (!$this->recover($error)) {
                        return null;
                    }
                    continue;
                }

                if ($message !== null) {
                    $this->dispatch($message);

                    return $message;
                }

                continue;
            }

            if (!$this->waitReadable($wait)) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return null;
                }
                if ($this->silentTooLong()) {
                    if (!$this->recover(new ConnectionException('No data for ' . $this->readTimeout . 's'))) {
                        return null;
                    }
                }
                continue;
            }

            try {
                $message = $this->readMessage();
            } catch (WebSocketClosed $closed) {
                if (!$this->recover(new ConnectionException("Server closed the connection: {$closed->closeCode} {$closed->reason}"))) {
                    return null;
                }
                continue;
            } catch (ConnectionException $error) {
                if (!$this->recover($error)) {
                    return null;
                }
                continue;
            }

            if ($message === null) {
                continue;
            }

            $this->dispatch($message);

            return $message;
        }
    }

    /**
     * Block, dispatching messages to handlers, until close() is called or $until returns
     * true.
     *
     * @param callable(LiveClient): bool|null $until checked between messages
     */
    public function run(?callable $until = null): void
    {
        while (!$this->userClosed) {
            if ($until !== null && $until($this)) {
                return;
            }
            $this->receive(1.0);
        }
    }

    /**
     * Read one complete application message, answering control frames as they arrive.
     *
     * Returns null for a frame that carried no application message, such as a ping.
     *
     * @return array<string, mixed>|null
     *
     * @throws WebSocketClosed when the peer sends a close frame
     */
    private function readMessage(): ?array
    {
        $payload = '';
        $assembling = false;

        while (true) {
            $frame = Frame::readFrom(fn (int $n): string => $this->readExactly($n));

            switch ($frame->opcode) {
                case Frame::PING:
                    // RFC 6455 section 5.5.3: answer with the same payload.
                    $this->writeRaw(Frame::encode(Frame::PONG, $frame->payload));

                    // A control frame is allowed to arrive between the fragments of a
                    // message. Returning here would drop the half-assembled payload, so
                    // mid-message we keep reading; otherwise we hand control back so the
                    // caller can select again rather than block on the next frame.
                    if ($assembling) {
                        break;
                    }

                    return null;

                case Frame::PONG:
                    if ($assembling) {
                        break;
                    }

                    return null;

                case Frame::CLOSE:
                    [$code, $reason] = Frame::parseClose($frame->payload);
                    // Echo the close before dropping the socket, best effort.
                    try {
                        $this->writeRaw(Frame::encodeClose(1000));
                    } catch (ConnectionException) {
                        // The peer is already gone; nothing to do.
                    }
                    $this->dropSocket();

                    throw new WebSocketClosed($code, $reason);

                case Frame::TEXT:
                case Frame::BINARY:
                    if ($assembling) {
                        throw new ConnectionException('WebSocket data frame interrupted a fragmented message');
                    }
                    $payload = $frame->payload;
                    if ($frame->fin) {
                        return $this->decode($payload);
                    }
                    $assembling = true;
                    break;

                case Frame::CONTINUATION:
                    if (!$assembling) {
                        throw new ConnectionException('WebSocket continuation frame without a start frame');
                    }
                    $payload .= $frame->payload;
                    if (\strlen($payload) > Frame::MAX_PAYLOAD) {
                        throw new ConnectionException('WebSocket message exceeded the size limit');
                    }
                    if ($frame->fin) {
                        return $this->decode($payload);
                    }
                    break;

                default:
                    throw new ConnectionException(sprintf('Unknown WebSocket opcode 0x%X', $frame->opcode));
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $payload): ?array
    {
        $decoded = json_decode($payload, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Send a JSON frame.
     *
     * @param array<string, mixed> $message
     */
    private function send(array $message): void
    {
        if ($this->socket === null) {
            throw new ConnectionException('Not connected: call connect() first');
        }
        $this->writeRaw(Frame::encode(Frame::TEXT, json_encode($message, \JSON_THROW_ON_ERROR)));
    }

    /**
     * Ask for updates on a match.
     *
     * The id is recorded before the frame goes out, so it survives a reconnect even if the
     * write fails. The server caps concurrent subscriptions by plan and client messages at
     * 60/minute.
     */
    public function subscribe(string $matchId): void
    {
        $this->subscriptions[$matchId] = true;
        $this->send(['type' => 'subscribe', 'resource' => 'match', 'matchId' => $matchId]);
    }

    public function unsubscribe(string $matchId): void
    {
        unset($this->subscriptions[$matchId]);
        $this->send(['type' => 'unsubscribe', 'resource' => 'match', 'matchId' => $matchId]);
    }

    /**
     * Ask the server what this connection is subscribed to. The reply arrives as a
     * get_subscriptions_response message.
     */
    public function listSubscriptions(): void
    {
        $this->send(['type' => 'get_subscriptions']);
    }

    /**
     * Ask for the connection's plan, subscriptions and feature flags. The reply arrives as
     * a status message.
     */
    public function requestStatus(): void
    {
        $this->send(['type' => 'status']);
    }

    /**
     * Send a keepalive. receive() and run() already do this on a timer.
     */
    public function ping(): void
    {
        $this->send(['type' => 'ping']);
        $this->lastPingAt = microtime(true);
    }

    /**
     * Close the connection and stop reconnecting. Safe to call more than once.
     */
    public function close(): void
    {
        if ($this->userClosed) {
            return;
        }
        $this->userClosed = true;

        if ($this->socket !== null) {
            try {
                $this->writeRaw(Frame::encodeClose(1001, 'client closing'));
            } catch (ConnectionException) {
                // Already gone.
            }
            $this->dropSocket();
        }

        $this->dispatch(['type' => self::EVENT_CLOSE]);
    }

    /**
     * Send the application-level keepalive if one is due.
     */
    private function maybePing(): void
    {
        if ($this->pingInterval > 0 && microtime(true) - $this->lastPingAt >= $this->pingInterval) {
            try {
                $this->ping();
            } catch (ConnectionException) {
                // The read side will notice and reconnect.
            }
        }
    }

    private function silentTooLong(): bool
    {
        return $this->readTimeout > 0 && microtime(true) - $this->lastPingAt > $this->readTimeout;
    }

    /**
     * Handle a dead connection: reconnect and replay subscriptions, or give up.
     *
     * Returns false when the caller should stop, which happens on an explicit close, with
     * reconnection disabled, or once the attempt cap is reached.
     */
    private function recover(ConnectionException $cause): bool
    {
        $this->dropSocket();
        $this->authenticated = false;
        $this->dispatch(['type' => self::EVENT_CLOSE, 'message' => $cause->getMessage()]);

        if ($this->userClosed || !$this->autoReconnect) {
            $this->userClosed = true;

            throw $cause;
        }

        for ($attempt = 1; $this->maxReconnectAttempts === null || $attempt <= $this->maxReconnectAttempts; $attempt++) {
            // Exponential backoff from 1s, capped at 30s.
            usleep((int) (min(30.0, 2 ** ($attempt - 1)) * 1_000_000));

            if ($this->userClosed) {
                return false;
            }

            try {
                $this->openSocket();
                $this->authenticate();
            } catch (GoalApiException) {
                continue;
            }

            $this->dispatch(['type' => self::EVENT_OPEN]);

            // The server forgets a dropped connection's subscriptions, so without this a
            // reconnect would silently deliver nothing.
            foreach ($this->subscriptions() as $matchId) {
                try {
                    $this->send(['type' => 'subscribe', 'resource' => 'match', 'matchId' => $matchId]);
                } catch (ConnectionException) {
                    break;
                }
            }

            return true;
        }

        $this->userClosed = true;

        throw new ConnectionException(
            "Gave up reconnecting to the live feed after {$this->maxReconnectAttempts} attempts",
            null,
            null,
            null,
            null,
            null,
            [],
            $cause
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function dispatch(array $message): void
    {
        $type = \is_string($message['type'] ?? null) ? $message['type'] : '';

        foreach ($this->handlers[$type] ?? [] as $handler) {
            $handler($message);
        }
        foreach ($this->handlers[self::EVENT_ANY] ?? [] as $handler) {
            $handler($message);
        }
    }

    /**
     * Block until the socket has something to read, or the timeout expires.
     */
    private function waitReadable(float $timeout): bool
    {
        $read = [$this->socket()];
        $write = null;
        $except = null;

        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        return $ready === 1;
    }

    /**
     * Read exactly $length bytes, or fail.
     */
    private function readExactly(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        while (\strlen($this->buffer) < $length) {
            $chunk = @fread($this->socket(), max(8192, $length - \strlen($this->buffer)));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket());
                if ($meta['timed_out'] ?? false) {
                    throw new ConnectionException('Timed out reading from the live WebSocket');
                }
                if (feof($this->socket())) {
                    throw new ConnectionException('Live WebSocket closed by the peer');
                }
                // A non-blocking stream with nothing buffered yet; wait for more.
                if (!$this->waitReadable(1.0)) {
                    continue;
                }
                continue;
            }

            $this->buffer .= $chunk;
        }

        $out = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $out;
    }

    private function writeRaw(string $bytes): void
    {
        $socket = $this->socket();
        $total = \strlen($bytes);
        $written = 0;

        while ($written < $total) {
            $result = @fwrite($socket, substr($bytes, $written));
            if ($result === false || $result === 0) {
                throw new ConnectionException('Could not write to the live WebSocket');
            }
            $written += $result;
        }
    }

    /**
     * @return resource
     */
    private function socket()
    {
        if ($this->socket === null) {
            throw new ConnectionException('Not connected: call connect() first');
        }

        return $this->socket;
    }

    private function dropSocket(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->authenticated = false;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->dropSocket();
    }
}
