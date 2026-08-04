<?php

declare(strict_types=1);

namespace GoalApi\Live;

use GoalApi\Exceptions\ConnectionException;

/**
 * One RFC 6455 frame, and the encoding of it.
 *
 * PHP has no WebSocket in core and this SDK has no dependencies, so the wire format lives
 * here. It is client-side only: frames going out are always masked, which RFC 6455
 * section 5.3 requires of clients, and frames coming in from a server are never masked.
 *
 * @internal
 */
final class Frame
{
    public const CONTINUATION = 0x0;
    public const TEXT = 0x1;
    public const BINARY = 0x2;
    public const CLOSE = 0x8;
    public const PING = 0x9;
    public const PONG = 0xA;

    /**
     * Control frames carry at most 125 bytes and are never fragmented, per RFC 6455
     * section 5.5.
     */
    public const MAX_CONTROL_PAYLOAD = 125;

    /**
     * A reassembled message cap. Match updates are a few KB; without a limit a header
     * claiming a huge length would drive an unbounded allocation.
     */
    public const MAX_PAYLOAD = 8 * 1024 * 1024;

    public function __construct(
        public readonly bool $fin,
        public readonly int $opcode,
        public readonly string $payload,
    ) {
    }

    public function isControl(): bool
    {
        return ($this->opcode & 0x8) !== 0;
    }

    /**
     * Encode a frame for sending. Always masked, always FIN: this client never fragments
     * what it sends.
     */
    public static function encode(int $opcode, string $payload = ''): string
    {
        $length = \strlen($payload);

        // 0x80 sets FIN.
        $header = \chr(0x80 | $opcode);

        // 0x80 on the second byte marks the payload as masked.
        if ($length < 126) {
            $header .= \chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= \chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= \chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);

        return $header . $mask . ($payload === '' ? '' : ($payload ^ str_pad('', $length, $mask)));
    }

    /**
     * Encode a close frame. The code is big-endian in the first two payload bytes.
     */
    public static function encodeClose(int $code, string $reason = ''): string
    {
        // Two bytes go to the code, so the reason has to fit in what is left.
        $reason = substr($reason, 0, self::MAX_CONTROL_PAYLOAD - 2);

        return self::encode(self::CLOSE, pack('n', $code) . $reason);
    }

    /**
     * Read one frame using a callable that returns exactly the number of bytes asked for.
     *
     * @param callable(int): string $read
     *
     * @throws ConnectionException on a malformed frame
     */
    public static function readFrom(callable $read): self
    {
        $header = $read(2);
        $first = \ord($header[0]);
        $second = \ord($header[1]);

        $fin = ($first & 0x80) !== 0;

        if (($first & 0x70) !== 0) {
            // RSV1-3 must be zero; this client negotiates no extensions.
            throw new ConnectionException('WebSocket frame set reserved bits without a negotiated extension');
        }

        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;

        $isControl = ($opcode & 0x8) !== 0;
        if ($isControl) {
            if (!$fin) {
                throw new ConnectionException('WebSocket control frame was fragmented');
            }
            if ($length > self::MAX_CONTROL_PAYLOAD) {
                throw new ConnectionException('WebSocket control frame exceeded 125 bytes');
            }
        }

        if ($length === 126) {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('n', $read(2));
            $length = $unpacked[1];
        } elseif ($length === 127) {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', $read(8));
            $length = $unpacked[1];
            if ($length < 0) {
                // PHP ints are signed, so a length past 2^63 reads as negative.
                throw new ConnectionException('WebSocket frame length out of range');
            }
        }

        // Check before allocating, so a hostile header cannot make us reserve gigabytes.
        if ($length > self::MAX_PAYLOAD) {
            throw new ConnectionException('WebSocket frame exceeded the size limit');
        }

        $mask = $masked ? $read(4) : '';
        $payload = $length > 0 ? $read($length) : '';

        if ($masked && $payload !== '') {
            $payload ^= str_pad('', $length, $mask);
        }

        return new self($fin, $opcode, $payload);
    }

    /**
     * Split a close frame payload into its code and reason.
     *
     * @return array{0: int, 1: string}
     */
    public static function parseClose(string $payload): array
    {
        if (\strlen($payload) < 2) {
            return [1005, ''];
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', substr($payload, 0, 2));

        return [$unpacked[1], substr($payload, 2)];
    }
}
