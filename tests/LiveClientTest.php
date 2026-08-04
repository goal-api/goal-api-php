<?php

declare(strict_types=1);

namespace GoalApi\Tests;

use GoalApi\Exceptions\AuthenticationException;
use GoalApi\Exceptions\ConnectionException;
use GoalApi\GoalApi;
use GoalApi\Live\Frame;
use GoalApi\Live\LiveClient;
use PHPUnit\Framework\TestCase;

/**
 * LiveClient against a real WebSocket server.
 *
 * The server runs in a forked child speaking the actual protocol, rather than a mock
 * returning canned bytes: the client is hand-rolled, so a mock would only prove that the
 * mock and the client share whatever assumptions they share. The child is killed with
 * SIGKILL instead of exit() so PHPUnit's shutdown handlers do not run twice and print a
 * second test summary.
 */
final class LiveClientTest extends TestCase
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var list<int> */
    private array $children = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run a WebSocket server alongside the client');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            @posix_kill($pid, \SIGKILL);
            @pcntl_waitpid($pid, $status);
        }
        $this->children = [];
    }

    /**
     * Start a server in a forked child and return the ws:// URL to reach it.
     *
     * @param callable(resource): void $handler runs in the child once the handshake is done
     * @param int|null                 $rejectWith HTTP status to fail the handshake with
     */
    private function startServer(callable $handler, ?int $rejectWith = null, string $rejectBody = ''): string
    {
        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->fail("Could not open a test server: {$errstr}");
        }
        $address = stream_socket_get_name($server, false);
        [, $port] = explode(':', $address);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork a test server');
        }

        if ($pid === 0) {
            // Child.
            $conn = @stream_socket_accept($server, 10);
            if ($conn === false) {
                posix_kill(posix_getpid(), \SIGKILL);
            }

            $request = '';
            while (!str_contains($request, "\r\n\r\n")) {
                $chunk = @fread($conn, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $request .= $chunk;
            }

            if ($rejectWith !== null) {
                @fwrite($conn, sprintf(
                    "HTTP/1.1 %d Rejected\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
                    $rejectWith,
                    \strlen($rejectBody),
                    $rejectBody
                ));
                @fclose($conn);
                posix_kill(posix_getpid(), \SIGKILL);
            }

            if (preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $request, $matches) === 1) {
                $accept = base64_encode(sha1(trim($matches[1]) . self::GUID, true));
                @fwrite(
                    $conn,
                    "HTTP/1.1 101 Switching Protocols\r\n"
                    . "Upgrade: websocket\r\n"
                    . "Connection: Upgrade\r\n"
                    . "Sec-WebSocket-Accept: {$accept}\r\n\r\n"
                );
                $handler($conn);
            }

            @fclose($conn);
            // SIGKILL, not exit(): the child must not run PHPUnit's shutdown handlers.
            posix_kill(posix_getpid(), \SIGKILL);
        }

        // Parent. Close its copy of the listening socket.
        fclose($server);
        $this->children[] = $pid;

        return "ws://127.0.0.1:{$port}/ws";
    }

    /**
     * Read one frame from the client, unmasking it. Client frames are always masked.
     *
     * @param resource $conn
     *
     * @return array{opcode: int, payload: string}|null
     */
    private static function serverRead($conn): ?array
    {
        $header = self::readBytes($conn, 2);
        if ($header === null) {
            return null;
        }

        $opcode = \ord($header[0]) & 0x0F;
        $length = \ord($header[1]) & 0x7F;

        if ($length === 126) {
            $ext = self::readBytes($conn, 2);
            if ($ext === null) {
                return null;
            }
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('n', $ext);
            $length = $unpacked[1];
        } elseif ($length === 127) {
            $ext = self::readBytes($conn, 8);
            if ($ext === null) {
                return null;
            }
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', $ext);
            $length = $unpacked[1];
        }

        $mask = self::readBytes($conn, 4);
        if ($mask === null) {
            return null;
        }
        $payload = $length > 0 ? self::readBytes($conn, $length) : '';
        if ($payload === null) {
            return null;
        }

        return [
            'opcode' => $opcode,
            'payload' => $length > 0 ? $payload ^ str_pad('', $length, $mask) : '',
        ];
    }

    /**
     * @param resource $conn
     */
    private static function readBytes($conn, int $length): ?string
    {
        $out = '';
        while (\strlen($out) < $length) {
            $chunk = @fread($conn, $length - \strlen($out));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * Write an unmasked server frame, which is what servers must send.
     *
     * @param resource $conn
     */
    private static function serverWrite($conn, int $opcode, string $payload, bool $fin = true): void
    {
        $first = $fin ? (0x80 | $opcode) : $opcode;
        $header = \chr($first);
        $length = \strlen($payload);

        if ($length < 126) {
            $header .= \chr($length);
        } elseif ($length <= 0xFFFF) {
            $header .= \chr(126) . pack('n', $length);
        } else {
            $header .= \chr(127) . pack('J', $length);
        }

        @fwrite($conn, $header . $payload);
    }

    /**
     * @param resource            $conn
     * @param array<string,mixed> $message
     */
    private static function serverWriteJson($conn, array $message): void
    {
        self::serverWrite($conn, Frame::TEXT, json_encode($message, \JSON_THROW_ON_ERROR));
    }

    private function client(string $url, bool $autoReconnect = false): LiveClient
    {
        $goal = new GoalApi('test-key', 'http://127.0.0.1:1/v1');

        return $goal->live(autoReconnect: $autoReconnect, url: $url, connectTimeout: 5.0, authTimeout: 5.0);
    }

    public function testConnectSendsAuthFrameFirst(): void
    {
        $url = $this->startServer(function ($conn): void {
            $frame = self::serverRead($conn);
            // Echo what arrived back to the client so the parent can assert on it.
            self::serverWriteJson($conn, ['type' => 'auth_success']);
            self::serverWriteJson($conn, [
                'type' => 'match_update',
                'data' => ['sawFirstFrame' => $frame['payload'] ?? ''],
            ]);
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();

        $this->assertTrue($live->isConnected());

        $message = $live->receive(5.0);
        $this->assertIsArray($message);
        $this->assertSame('match_update', $message['type']);

        $firstFrame = json_decode($message['data']['sawFirstFrame'], true);
        $this->assertSame('auth', $firstFrame['type'], 'the server closes with 4001 unless auth is the first frame');
        $this->assertSame('test-key', $firstFrame['apiKey']);

        $live->close();
    }

    public function testReceivesMatchUpdate(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);
            self::serverWriteJson($conn, [
                'type' => 'match_update',
                'data' => ['id' => 'match-1', 'homeScore' => 2],
            ]);
            sleep(10);
        });

        $live = $this->client($url);

        $received = [];
        $live->on('match_update', function (array $message) use (&$received): void {
            $received[] = $message;
        });

        $live->connect();
        $message = $live->receive(5.0);

        $this->assertIsArray($message);
        $this->assertSame('match-1', $message['data']['id']);
        $this->assertSame(2, $message['data']['homeScore']);
        $this->assertCount(1, $received, 'handlers registered with on() must fire from receive()');

        $live->close();
    }

    public function testWildcardHandlerSeesEverything(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);
            self::serverWriteJson($conn, ['type' => 'status', 'data' => ['plan' => 'PRO']]);
            sleep(10);
        });

        $live = $this->client($url);

        $types = [];
        $live->on(LiveClient::EVENT_ANY, function (array $message) use (&$types): void {
            $types[] = $message['type'] ?? '';
        });

        $live->connect();
        $live->receive(5.0);

        // auth_success and open both land before the status message.
        $this->assertContains('auth_success', $types);
        $this->assertContains(LiveClient::EVENT_OPEN, $types);
        $this->assertContains('status', $types);

        $live->close();
    }

    public function testSubscribeSendsFrameAndTracksSubscription(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);

            $frame = self::serverRead($conn);
            self::serverWriteJson($conn, [
                'type' => 'subscribe_response',
                'data' => ['echo' => $frame['payload'] ?? ''],
            ]);
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();
        $live->subscribe('match-42');

        $this->assertSame(['match-42'], $live->subscriptions());

        $message = $live->receive(5.0);
        $this->assertIsArray($message);

        $echo = json_decode($message['data']['echo'], true);
        $this->assertSame('subscribe', $echo['type']);
        $this->assertSame('match', $echo['resource']);
        $this->assertSame('match-42', $echo['matchId']);

        $live->close();
    }

    public function testUnsubscribeDropsSubscription(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);
            while (self::serverRead($conn) !== null) {
                // Drain whatever the client sends.
            }
        });

        $live = $this->client($url);
        $live->connect();
        $live->subscribe('match-1');
        $live->subscribe('match-2');
        $this->assertSame(['match-1', 'match-2'], $live->subscriptions());

        $live->unsubscribe('match-1');
        $this->assertSame(['match-2'], $live->subscriptions());

        $live->close();
    }

    public function testAnswersPingWithPong(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);

            self::serverWrite($conn, Frame::PING, 'keepalive');

            // Find the pong the client owes us and report what it carried.
            while (($frame = self::serverRead($conn)) !== null) {
                if ($frame['opcode'] === Frame::PONG) {
                    self::serverWriteJson($conn, [
                        'type' => 'match_update',
                        'data' => ['pong' => $frame['payload']],
                    ]);
                    break;
                }
            }
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();

        $message = $live->receive(5.0);
        $this->assertIsArray($message);
        // RFC 6455 section 5.5.3: the pong echoes the ping payload verbatim.
        $this->assertSame('keepalive', $message['data']['pong']);

        $live->close();
    }

    public function testReassemblesFragmentedMessage(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);

            $full = '{"type":"match_update","data":{"id":"frag-1"}}';
            self::serverWrite($conn, Frame::TEXT, substr($full, 0, 15), fin: false);
            // A control frame may interleave with the fragments of a message.
            self::serverWrite($conn, Frame::PING, 'mid');
            self::serverWrite($conn, Frame::CONTINUATION, substr($full, 15, 15), fin: false);
            self::serverWrite($conn, Frame::CONTINUATION, substr($full, 30), fin: true);
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();

        $message = $live->receive(5.0);
        $this->assertIsArray($message);
        $this->assertSame('match_update', $message['type']);
        $this->assertSame('frag-1', $message['data']['id']);

        $live->close();
    }

    public function testLargeMessageSurvivesExtendedLength(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);
            self::serverWriteJson($conn, [
                'type' => 'match_update',
                'data' => ['blob' => str_repeat('x', 70000)],
            ]);
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();

        $message = $live->receive(10.0);
        $this->assertIsArray($message);
        $this->assertSame(70000, \strlen($message['data']['blob']));

        $live->close();
    }

    public function testAuthRejectionThrowsAuthenticationException(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            // 4001: websocket-service rejects the credentials or the frame ordering.
            self::serverWrite($conn, Frame::CLOSE, pack('n', 4001) . 'invalid api key');
            sleep(2);
        });

        $live = $this->client($url);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/invalid api key/');
        $live->connect();
    }

    public function testServerErrorFrameDuringAuthThrows(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, [
                'type' => 'error',
                'message' => 'key revoked',
                'code' => 'AUTHENTICATION_ERROR',
            ]);
            sleep(2);
        });

        $live = $this->client($url);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/key revoked/');
        $live->connect();
    }

    public function testRejectedHandshakeSurfacesApiError(): void
    {
        $url = $this->startServer(
            static function ($conn): void {
            },
            rejectWith: 401,
            rejectBody: '{"success":false,"error":{"message":"bad key","code":"AUTHENTICATION_ERROR"}}'
        );

        $live = $this->client($url);

        try {
            $live->connect();
            $this->fail('connect() succeeded against a 401 handshake');
        } catch (AuthenticationException $error) {
            // A non-101 carries a normal API error body and should read like any other
            // API failure, not a socket complaint.
            $this->assertSame(401, $error->status);
            $this->assertSame('bad key', $error->getMessage());
            $this->assertSame('AUTHENTICATION_ERROR', $error->errorCode);
        }
    }

    public function testDeliversFramesThatArriveInOneSegment(): void
    {
        // The server answers a burst of subscribes together, so several frames land in a
        // single TCP segment and a single read pulls them all into the client's buffer.
        // stream_select watches the socket, not that buffer, so a client that selects
        // before draining reports only the first frame and then blocks until more traffic
        // arrives. Production caught this; one-frame-per-write tests did not.
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);

            $batch = '';
            $batch .= self::frameBytes(Frame::TEXT, json_encode(['type' => 'auth_success']));
            for ($i = 1; $i <= 5; $i++) {
                $batch .= self::frameBytes(Frame::TEXT, json_encode([
                    'type' => 'subscribe_response',
                    'data' => ['n' => $i],
                ]));
            }
            // One write, so the frames coalesce on the wire.
            @fwrite($conn, $batch);
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();

        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $message = $live->receive(2.0);
            $this->assertIsArray($message, 'frame ' . ($i + 1) . ' of a coalesced batch was not delivered');
            $seen[] = $message['data']['n'];
        }

        $this->assertSame([1, 2, 3, 4, 5], $seen);

        $live->close();
    }

    /**
     * Build an unmasked server frame as a string, for writing several at once.
     */
    private static function frameBytes(int $opcode, string $payload): string
    {
        $header = \chr(0x80 | $opcode);
        $length = \strlen($payload);

        if ($length < 126) {
            $header .= \chr($length);
        } elseif ($length <= 0xFFFF) {
            $header .= \chr(126) . pack('n', $length);
        } else {
            $header .= \chr(127) . pack('J', $length);
        }

        return $header . $payload;
    }

    public function testCloseIsIdempotent(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();
        $this->assertTrue($live->isConnected());

        $live->close();
        $this->assertFalse($live->isConnected());
        // Calling close() twice is the common shape when a finally block also closes.
        $live->close();
        $this->assertFalse($live->isConnected());
    }

    public function testReceiveReturnsNullOnTimeout(): void
    {
        $url = $this->startServer(function ($conn): void {
            self::serverRead($conn);
            self::serverWriteJson($conn, ['type' => 'auth_success']);
            sleep(10);
        });

        $live = $this->client($url);
        $live->connect();

        $started = microtime(true);
        $this->assertNull($live->receive(0.3), 'a quiet feed must time out rather than block');
        $this->assertLessThan(3.0, microtime(true) - $started);

        $live->close();
    }

    public function testConnectingToADeadPortThrowsConnectionException(): void
    {
        $goal = new GoalApi('test-key', 'http://127.0.0.1:1/v1');
        // Port 1 has nothing listening.
        $live = $goal->live(autoReconnect: false, connectTimeout: 2.0, url: 'ws://127.0.0.1:1/ws');

        $this->expectException(ConnectionException::class);
        $live->connect();
    }
}
