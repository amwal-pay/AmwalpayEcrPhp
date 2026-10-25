<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Channel;

/**
 * The wire framing: a 2-byte big-endian length followed by that many bytes of
 * UTF-8 JSON. A TCP stream carries no message boundaries, so the header is what
 * says where one message ends.
 */
final class EcrFrames
{
    public const HEADER_BYTES = 2;

    /** The header is two bytes, so nothing longer can be described. */
    public const MAX_BODY_BYTES = 0xFFFF;

    /**
     * Puts a length header in front of `$body`.
     *
     * @throws EcrIOException when the body does not fit a frame
     */
    public static function wrap(string $body): string
    {
        $length = \strlen($body);
        if ($length > self::MAX_BODY_BYTES) {
            throw new EcrIOException(sprintf('Message of %d bytes does not fit a frame', $length));
        }
        return pack('n', $length) . $body;
    }

    /** The body length a 2-byte header announces. */
    public static function bodyLength(string $header): int
    {
        if (\strlen($header) < self::HEADER_BYTES) {
            throw new \InvalidArgumentException('A frame header is two bytes');
        }
        return (\ord($header[0]) << 8) | \ord($header[1]);
    }

    /**
     * Reads exactly one message body from a byte source.
     *
     * @param callable(int $count, string $what): string $readExactly returns exactly `$count` bytes or throws
     * @throws EcrIOException
     */
    public static function readBody(callable $readExactly): string
    {
        $length = self::bodyLength($readExactly(self::HEADER_BYTES, 'response header'));
        if ($length <= 0) {
            throw new EcrIOException('Terminal returned an empty message');
        }
        return $readExactly($length, 'response body');
    }

    /** The error for a stream that ended part way through `$what`. */
    public static function closed(string $what): EcrIOException
    {
        return new EcrIOException('Terminal closed the connection while sending the ' . $what);
    }

    /**
     * Reads one framed body from an in-memory string, starting at `$offset`,
     * which is moved past it. For channels that already hold the bytes.
     *
     * @throws EcrIOException
     */
    public static function readBodyFrom(string $bytes, int &$offset = 0): string
    {
        return self::readBody(static function (int $count, string $what) use ($bytes, &$offset): string {
            if (\strlen($bytes) - $offset < $count) {
                throw self::closed($what);
            }
            $chunk = substr($bytes, $offset, $count);
            $offset += $count;
            return $chunk;
        });
    }
}
