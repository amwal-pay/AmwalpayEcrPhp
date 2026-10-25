<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Json;

/**
 * The one JSON reader and writer the SDK uses on the wire.
 *
 * Strict on purpose. The terminal signs the text it sends, so the reader keeps
 * numbers as written ({@see JsonNumber}) and refuses anything a lenient parser
 * would quietly "fix": a repeated key (two readings of one message is how a
 * decline gets read as an approval), trailing commas, comments, single quotes,
 * invalid UTF-8, lone surrogates, and trailing text; {@see parseObject()}
 * also refuses anything that is not one object.
 */
final class Json
{
    /** Same limit as System.Text.Json, which the Windows SDK parses with. */
    public const MAX_DEPTH = 64;

    private const STRING_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;

    private string $text;
    private int $pos = 0;
    private int $length;

    private function __construct(string $text)
    {
        $this->text = $text;
        $this->length = \strlen($text);
    }

    /**
     * Parses text that must be exactly one JSON object.
     *
     * @throws JsonException when it is anything else, or not valid JSON
     */
    public static function parseObject(string $text): JsonObject
    {
        $value = self::parse($text);
        if (!$value instanceof JsonObject) {
            throw new JsonException('The text is not a JSON object');
        }
        return $value;
    }

    /**
     * Parses any single JSON value.
     *
     * @throws JsonException
     */
    public static function parse(string $text): mixed
    {
        if ($text === '' || preg_match('//u', $text) !== 1) {
            throw new JsonException($text === '' ? 'The text is empty' : 'The text is not valid UTF-8');
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            throw new JsonException('The text starts with a byte order mark');
        }
        $parser = new self($text);
        $parser->skipWhitespace();
        $value = $parser->readValue(0);
        $parser->skipWhitespace();
        if ($parser->pos !== $parser->length) {
            throw $parser->error('Unexpected text after the JSON value');
        }
        return $value;
    }

    /** Compact JSON text, with non-ASCII, `<`, `>`, `&` and `/` written as themselves. */
    public static function write(mixed $value): string
    {
        return self::encode($value, null, 0);
    }

    /** Indented JSON text, for logs. */
    public static function pretty(mixed $value): string
    {
        return self::encode($value, '  ', 0);
    }

    /**
     * The text of a value: a string unquoted, a number or boolean as written,
     * an object or array as compact JSON.
     */
    public static function content(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            $value instanceof JsonNumber => $value->raw,
            $value === null => 'null',
            default => self::write($value),
        };
    }

    /** Whether a value counts toward a signature: a string, number or boolean. */
    public static function isScalar(mixed $value): bool
    {
        return \is_string($value) || \is_bool($value) || \is_int($value) || $value instanceof JsonNumber;
    }

    /**
     * The scalar entries of `$json` whose key is not `$excluded`, sorted by key
     * byte for byte, as `key=value&key=value`. Nulls, objects and arrays are left
     * out: two JSON libraries cannot be relied on to write them identically.
     */
    public static function compose(JsonObject $json, string $excluded): string
    {
        $pairs = [];
        foreach ($json as $key => $value) {
            if ($key === $excluded || !self::isScalar($value)) {
                continue;
            }
            $pairs[] = [$key, self::content($value)];
        }
        // Ordinal, like the terminal: "B" sorts before "a".
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
    }

    // ── writing ───────────────────────────────────────────────────────

    private static function encode(mixed $value, ?string $indent, int $level): string
    {
        if ($value instanceof JsonObject) {
            if (\count($value) === 0) {
                return '{}';
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = self::encodeString($key) . ($indent === null ? ':' : ': ')
                    . self::encode($item, $indent, $level + 1);
            }
            return self::wrap('{', '}', $parts, $indent, $level);
        }
        if ($value instanceof JsonArray) {
            if (\count($value) === 0) {
                return '[]';
            }
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::encode($item, $indent, $level + 1);
            }
            return self::wrap('[', ']', $parts, $indent, $level);
        }
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            $value instanceof JsonNumber => $value->raw,
            \is_string($value) => self::encodeString($value),
            default => throw new \InvalidArgumentException('Unsupported JSON value of type ' . get_debug_type($value)),
        };
    }

    /** @param list<string> $parts */
    private static function wrap(string $open, string $close, array $parts, ?string $indent, int $level): string
    {
        if ($indent === null) {
            return $open . implode(',', $parts) . $close;
        }
        $inner = str_repeat($indent, $level + 1);
        return $open . "\n" . $inner . implode(",\n" . $inner, $parts) . "\n" . str_repeat($indent, $level) . $close;
    }

    private static function encodeString(string $text): string
    {
        try {
            return json_encode($text, self::STRING_FLAGS);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('A text field is not valid UTF-8 and cannot be sent');
        }
    }

    // ── reading ───────────────────────────────────────────────────────

    private function readValue(int $depth): mixed
    {
        if ($this->pos >= $this->length) {
            throw $this->error('Unexpected end of text');
        }
        $c = $this->text[$this->pos];
        switch ($c) {
            case '{':
                return $this->readObject($depth + 1);
            case '[':
                return $this->readArray($depth + 1);
            case '"':
                return $this->readString();
            case 't':
                return $this->readLiteral('true', true);
            case 'f':
                return $this->readLiteral('false', false);
            case 'n':
                return $this->readLiteral('null', null);
            default:
                if ($c === '-' || ($c >= '0' && $c <= '9')) {
                    return $this->readNumber();
                }
                throw $this->error("Unexpected character '" . $c . "'");
        }
    }

    private function readObject(int $depth): JsonObject
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->error('The JSON is nested too deeply');
        }
        $this->pos++; // {
        $object = new JsonObject();
        $this->skipWhitespace();
        if ($this->peek() === '}') {
            $this->pos++;
            return $object;
        }
        while (true) {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                throw $this->error('Expected a property name in double quotes');
            }
            $key = $this->readString();
            if ($object->has($key)) {
                throw $this->error("The property '" . $key . "' appears more than once");
            }
            $this->skipWhitespace();
            if ($this->peek() !== ':') {
                throw $this->error("Expected ':' after a property name");
            }
            $this->pos++;
            $this->skipWhitespace();
            $object->set($key, $this->readValue($depth));
            $this->skipWhitespace();
            $next = $this->peek();
            $this->pos++;
            if ($next === '}') {
                return $object;
            }
            if ($next !== ',') {
                throw $this->error("Expected ',' or '}' in an object");
            }
        }
    }

    private function readArray(int $depth): JsonArray
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->error('The JSON is nested too deeply');
        }
        $this->pos++; // [
        $items = [];
        $this->skipWhitespace();
        if ($this->peek() === ']') {
            $this->pos++;
            return new JsonArray();
        }
        while (true) {
            $this->skipWhitespace();
            $items[] = $this->readValue($depth);
            $this->skipWhitespace();
            $next = $this->peek();
            $this->pos++;
            if ($next === ']') {
                return new JsonArray($items);
            }
            if ($next !== ',') {
                throw $this->error("Expected ',' or ']' in an array");
            }
        }
    }

    private function readString(): string
    {
        $this->pos++; // opening quote
        $out = '';
        while (true) {
            // Copy the run up to the next quote, backslash or control character.
            $run = strcspn($this->text, "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
                . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F", $this->pos);
            $out .= substr($this->text, $this->pos, $run);
            $this->pos += $run;
            if ($this->pos >= $this->length) {
                throw $this->error('Unterminated string');
            }
            $c = $this->text[$this->pos];
            if ($c === '"') {
                $this->pos++;
                return $out;
            }
            if ($c !== '\\') {
                throw $this->error('A control character must be escaped inside a string');
            }
            $this->pos++;
            $escape = $this->peek();
            $this->pos++;
            switch ($escape) {
                case '"': $out .= '"'; break;
                case '\\': $out .= '\\'; break;
                case '/': $out .= '/'; break;
                case 'b': $out .= "\x08"; break;
                case 'f': $out .= "\x0C"; break;
                case 'n': $out .= "\n"; break;
                case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;
                case 'u': $out .= $this->readUnicodeEscape(); break;
                default:
                    throw $this->error('Invalid escape sequence in a string');
            }
        }
    }

    private function readUnicodeEscape(): string
    {
        $code = $this->readHex4();
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            if (substr($this->text, $this->pos, 2) !== '\\u') {
                throw $this->error('A high surrogate must be followed by a low surrogate');
            }
            $this->pos += 2;
            $low = $this->readHex4();
            if ($low < 0xDC00 || $low > 0xDFFF) {
                throw $this->error('A high surrogate must be followed by a low surrogate');
            }
            $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
        } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
            throw $this->error('A low surrogate without a high surrogate');
        }
        return self::utf8($code);
    }

    private function readHex4(): int
    {
        $hex = substr($this->text, $this->pos, 4);
        if (\strlen($hex) !== 4 || !ctype_xdigit($hex)) {
            throw $this->error('Invalid \\u escape');
        }
        $this->pos += 4;
        return (int) hexdec($hex);
    }

    private function readNumber(): JsonNumber
    {
        if (preg_match('/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A', $this->text, $m, 0, $this->pos) !== 1) {
            throw $this->error('Invalid number');
        }
        $this->pos += \strlen($m[0]);
        $next = $this->peek();
        if ($next !== null && (ctype_digit($next) || $next === '.' || $next === 'e' || $next === 'E')) {
            throw $this->error('Invalid number');
        }
        return new JsonNumber($m[0]);
    }

    private function readLiteral(string $word, mixed $value): mixed
    {
        if (substr($this->text, $this->pos, \strlen($word)) !== $word) {
            throw $this->error('Invalid literal');
        }
        $this->pos += \strlen($word);
        return $value;
    }

    private function skipWhitespace(): void
    {
        $this->pos += strspn($this->text, " \t\n\r", $this->pos);
    }

    private function peek(): ?string
    {
        return $this->pos < $this->length ? $this->text[$this->pos] : null;
    }

    private function error(string $message): JsonException
    {
        return new JsonException($message . ' at offset ' . $this->pos);
    }

    private static function utf8(int $code): string
    {
        if ($code < 0x80) {
            return \chr($code);
        }
        if ($code < 0x800) {
            return \chr(0xC0 | ($code >> 6)) . \chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return \chr(0xE0 | ($code >> 12)) . \chr(0x80 | (($code >> 6) & 0x3F)) . \chr(0x80 | ($code & 0x3F));
        }
        return \chr(0xF0 | ($code >> 18)) . \chr(0x80 | (($code >> 12) & 0x3F))
            . \chr(0x80 | (($code >> 6) & 0x3F)) . \chr(0x80 | ($code & 0x3F));
    }
}
