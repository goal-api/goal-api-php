<?php

declare(strict_types=1);

namespace GoalApi\Tests;

use GoalApi\Exceptions\ConnectionException;
use GoalApi\Live\Frame;
use PHPUnit\Framework\TestCase;

/**
 * The frame codec, tested on its own.
 *
 * This is hand-rolled bit twiddling against RFC 6455, so the header layouts are asserted
 * byte by byte rather than only through a round trip: a round trip would pass just as
 * happily if the encoder and decoder shared the same mistake.
 */
final class LiveFrameTest extends TestCase
{
    /**
     * Read bytes out of a string, the way LiveClient reads them off a socket.
     *
     * @return callable(int): string
     */
    private function reader(string $data): callable
    {
        $offset = 0;

        return function (int $length) use ($data, &$offset): string {
            $chunk = substr($data, $offset, $length);
            if (\strlen($chunk) < $length) {
                throw new ConnectionException('Unexpected end of frame data');
            }
            $offset += $length;

            return $chunk;
        };
    }

    /**
     * Strip the client mask off an encoded frame, which is what the server does.
     *
     * @return array{opcode: int, fin: bool, payload: string, headerLength: int}
     */
    private function unmask(string $frame): array
    {
        $first = \ord($frame[0]);
        $second = \ord($frame[1]);
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('n', substr($frame, 2, 2));
            $length = $unpacked[1];
            $offset = 4;
        } elseif ($length === 127) {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', substr($frame, 2, 8));
            $length = $unpacked[1];
            $offset = 10;
        }

        $mask = substr($frame, $offset, 4);
        $payload = substr($frame, $offset + 4, $length);

        return [
            'opcode' => $first & 0x0F,
            'fin' => ($first & 0x80) !== 0,
            'payload' => $length > 0 ? $payload ^ str_pad('', $length, $mask) : '',
            'headerLength' => $offset + 4,
        ];
    }

    public function testEncodeSetsFinAndMaskBits(): void
    {
        $frame = Frame::encode(Frame::TEXT, 'hello');

        $this->assertSame(0x81, \ord($frame[0]), 'FIN must be set with a text opcode');
        $this->assertSame(0x80 | 5, \ord($frame[1]), 'the mask bit must be set, with the payload length beside it');
        // 2 header bytes + 4 mask bytes + 5 payload bytes.
        $this->assertSame(11, \strlen($frame));
    }

    public function testClientFramesAreAlwaysMasked(): void
    {
        // RFC 6455 section 5.3: a server closes on an unmasked client frame, so this is not
        // cosmetic.
        $frame = Frame::encode(Frame::TEXT, 'payload');
        $this->assertSame(0x80, \ord($frame[1]) & 0x80);

        $decoded = $this->unmask($frame);
        $this->assertSame('payload', $decoded['payload']);
    }

    public function testMaskIsRandomPerFrame(): void
    {
        // A fixed mask would defeat the point of masking entirely.
        $a = Frame::encode(Frame::TEXT, 'same payload');
        $b = Frame::encode(Frame::TEXT, 'same payload');

        $this->assertNotSame($a, $b, 'two encodings of the same payload must differ');
        $this->assertSame($this->unmask($a)['payload'], $this->unmask($b)['payload']);
    }

    public function testMediumPayloadUsesSixteenBitLength(): void
    {
        $payload = str_repeat('x', 300);
        $frame = Frame::encode(Frame::TEXT, $payload);

        $this->assertSame(0x80 | 126, \ord($frame[1]), '126 signals a 16-bit extended length');
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', substr($frame, 2, 2));
        $this->assertSame(300, $unpacked[1]);
        $this->assertSame($payload, $this->unmask($frame)['payload']);
    }

    public function testLargePayloadUsesSixtyFourBitLength(): void
    {
        $payload = str_repeat('y', 70000);
        $frame = Frame::encode(Frame::TEXT, $payload);

        $this->assertSame(0x80 | 127, \ord($frame[1]), '127 signals a 64-bit extended length');
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr($frame, 2, 8));
        $this->assertSame(70000, $unpacked[1]);
        $this->assertSame($payload, $this->unmask($frame)['payload']);
    }

    public function testBoundaryLengthsPickTheRightHeader(): void
    {
        // 125 is the last inline length; 126 is the first extended one.
        $this->assertSame(0x80 | 125, \ord(Frame::encode(Frame::TEXT, str_repeat('a', 125))[1]));
        $this->assertSame(0x80 | 126, \ord(Frame::encode(Frame::TEXT, str_repeat('a', 126))[1]));
        // 65535 still fits 16 bits; 65536 does not.
        $this->assertSame(0x80 | 126, \ord(Frame::encode(Frame::TEXT, str_repeat('a', 65535))[1]));
        $this->assertSame(0x80 | 127, \ord(Frame::encode(Frame::TEXT, str_repeat('a', 65536))[1]));
    }

    public function testEmptyPayloadEncodes(): void
    {
        $frame = Frame::encode(Frame::PING);

        $this->assertSame(0x89, \ord($frame[0]));
        $this->assertSame(0x80, \ord($frame[1]));
        $this->assertSame('', $this->unmask($frame)['payload']);
    }

    public function testReadsUnmaskedServerFrame(): void
    {
        // Servers must not mask, so the decoder has to accept an unmasked frame.
        $payload = '{"type":"match_update"}';
        $bytes = \chr(0x81) . \chr(\strlen($payload)) . $payload;

        $frame = Frame::readFrom($this->reader($bytes));

        $this->assertTrue($frame->fin);
        $this->assertSame(Frame::TEXT, $frame->opcode);
        $this->assertSame($payload, $frame->payload);
        $this->assertFalse($frame->isControl());
    }

    public function testReadsMaskedFrame(): void
    {
        // Not what a server sends, but the decoder must unmask if the bit is set.
        $encoded = Frame::encode(Frame::TEXT, 'masked input');
        $frame = Frame::readFrom($this->reader($encoded));

        $this->assertSame('masked input', $frame->payload);
    }

    public function testReadsExtendedLengthFrames(): void
    {
        foreach ([300, 70000] as $length) {
            $payload = str_repeat('z', $length);
            $frame = Frame::readFrom($this->reader(Frame::encode(Frame::BINARY, $payload)));

            $this->assertSame($length, \strlen($frame->payload));
            $this->assertSame(Frame::BINARY, $frame->opcode);
        }
    }

    public function testReadsFragmentedFrameFlags(): void
    {
        $bytes = \chr(0x01) . \chr(3) . 'abc'; // FIN clear, text opcode
        $frame = Frame::readFrom($this->reader($bytes));

        $this->assertFalse($frame->fin, 'a non-final fragment must report fin=false');
        $this->assertSame(Frame::TEXT, $frame->opcode);
    }

    public function testRejectsReservedBits(): void
    {
        // RSV1 set with no extension negotiated.
        $bytes = \chr(0xC1) . \chr(1) . 'a';

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/reserved bits/i');
        Frame::readFrom($this->reader($bytes));
    }

    public function testRejectsFragmentedControlFrame(): void
    {
        // Close with FIN clear: RFC 6455 section 5.5 forbids fragmenting control frames.
        $bytes = \chr(0x08) . \chr(2) . pack('n', 1000);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/control frame was fragmented/i');
        Frame::readFrom($this->reader($bytes));
    }

    public function testRejectsOversizedControlFrame(): void
    {
        // A ping claiming 126 bytes; the cap is 125.
        $bytes = \chr(0x89) . \chr(126) . pack('n', 200) . str_repeat('a', 200);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/exceeded 125 bytes/i');
        Frame::readFrom($this->reader($bytes));
    }

    public function testRejectsPayloadOverTheSizeLimit(): void
    {
        // The header claims far more than the cap. This must fail on the header alone,
        // before anything is allocated.
        $bytes = \chr(0x82) . \chr(127) . pack('J', Frame::MAX_PAYLOAD + 1);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/size limit/i');
        Frame::readFrom($this->reader($bytes));
    }

    public function testEncodeCloseCarriesCodeAndReason(): void
    {
        $frame = Frame::encodeClose(1001, 'client closing');
        $decoded = $this->unmask($frame);

        $this->assertSame(Frame::CLOSE, $decoded['opcode']);
        [$code, $reason] = Frame::parseClose($decoded['payload']);
        $this->assertSame(1001, $code);
        $this->assertSame('client closing', $reason);
    }

    public function testEncodeCloseTruncatesLongReason(): void
    {
        // Code plus reason must still fit the 125-byte control frame limit.
        $frame = Frame::encodeClose(1000, str_repeat('r', 500));
        $decoded = $this->unmask($frame);

        $this->assertLessThanOrEqual(Frame::MAX_CONTROL_PAYLOAD, \strlen($decoded['payload']));
    }

    public function testParseCloseHandlesEmptyPayload(): void
    {
        // A close frame may carry no payload at all; 1005 is "no status received".
        $this->assertSame([1005, ''], Frame::parseClose(''));
        $this->assertSame([1005, ''], Frame::parseClose('x'));
    }

    public function testParseCloseReadsServiceCode(): void
    {
        // 4001 is what websocket-service sends when the auth frame was not first.
        [$code, $reason] = Frame::parseClose(pack('n', 4001) . 'auth required');

        $this->assertSame(4001, $code);
        $this->assertSame('auth required', $reason);
    }

    public function testIsControlIdentifiesControlOpcodes(): void
    {
        $this->assertTrue((new Frame(true, Frame::CLOSE, ''))->isControl());
        $this->assertTrue((new Frame(true, Frame::PING, ''))->isControl());
        $this->assertTrue((new Frame(true, Frame::PONG, ''))->isControl());
        $this->assertFalse((new Frame(true, Frame::TEXT, ''))->isControl());
        $this->assertFalse((new Frame(true, Frame::BINARY, ''))->isControl());
        $this->assertFalse((new Frame(true, Frame::CONTINUATION, ''))->isControl());
    }
}
