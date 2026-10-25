<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Json;

/**
 * A JSON object that keeps its keys in order and its values exactly as they
 * arrived.
 *
 * Values are `string`, `bool`, `null`, `int`, {@see JsonNumber},
 * {@see JsonObject} or {@see JsonArray}. Everything here reads a value the way
 * the terminal's own code reads it: any scalar as text, JSON `null` as absent.
 *
 * @implements \IteratorAggregate<string, mixed>
 */
final class JsonObject implements \IteratorAggregate, \Countable
{
    /**
     * Keys are stored with a prefix: a PHP array turns the key "123" into the
     * integer 123, and a key is text on the wire.
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value);
        }
    }

    public function has(string $key): bool
    {
        return \array_key_exists("\0" . $key, $this->values);
    }

    public function get(string $key): mixed
    {
        return $this->values["\0" . $key] ?? null;
    }

    /** Replaces the value in place, or appends a new key at the end. */
    public function set(string $key, mixed $value): self
    {
        $this->values["\0" . $key] = self::checked($value);
        return $this;
    }

    public function remove(string $key): self
    {
        unset($this->values["\0" . $key]);
        return $this;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (string $k): string => substr($k, 1), array_keys($this->values));
    }

    public function count(): int
    {
        return \count($this->values);
    }

    /** @return \Generator<string, mixed> */
    public function getIterator(): \Generator
    {
        foreach ($this->values as $key => $value) {
            yield substr($key, 1) => $value;
        }
    }

    /**
     * Reads a field as text, whatever JSON type it arrived as. A field sent as
     * JSON `null`, or not sent, reads as "" rather than as the word "null". A
     * nested object or array reads as its compact JSON.
     */
    public function str(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            return '';
        }
        return Json::content($value);
    }

    /** The field when it is an object, otherwise null. */
    public function obj(string $key): ?JsonObject
    {
        $value = $this->get($key);
        return $value instanceof JsonObject ? $value : null;
    }

    /** The field when it is an array, otherwise null. */
    public function arr(string $key): ?JsonArray
    {
        $value = $this->get($key);
        return $value instanceof JsonArray ? $value : null;
    }

    /** A boolean field: only `true`/`false` (or their text) count, anything else is false. */
    public function flag(string $key): bool
    {
        return $this->strictBool($key) ?? false;
    }

    /** The field as a strict boolean, or null when absent or not `true`/`false`. */
    public function strictBool(string $key): ?bool
    {
        $value = $this->get($key);
        if ($value === null || $value instanceof JsonObject || $value instanceof JsonArray) {
            return null;
        }
        return match (Json::content($value)) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /** Compact JSON text of this object. */
    public function toJson(): string
    {
        return Json::write($this);
    }

    public function __clone()
    {
        foreach ($this->values as $key => $value) {
            if (\is_object($value)) {
                $this->values[$key] = clone $value;
            }
        }
    }

    private static function checked(mixed $value): mixed
    {
        if (
            $value === null || \is_string($value) || \is_bool($value) || \is_int($value)
            || $value instanceof JsonNumber || $value instanceof JsonObject || $value instanceof JsonArray
        ) {
            return $value;
        }
        if (\is_float($value)) {
            // A float has no exact text; money must never pass through one.
            throw new \InvalidArgumentException('JSON values must not be floats; use JsonNumber or a string');
        }
        throw new \InvalidArgumentException('Unsupported JSON value of type ' . get_debug_type($value));
    }
}
