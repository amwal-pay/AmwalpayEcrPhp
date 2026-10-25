<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Json;

/**
 * A JSON array. Kept apart from {@see JsonObject} so the two can never be
 * confused the way two PHP arrays can.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class JsonArray implements \IteratorAggregate, \Countable
{
    /** @var list<mixed> */
    private array $items;

    /** @param list<mixed> $items */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    /** @return list<mixed> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function __clone()
    {
        foreach ($this->items as $i => $item) {
            if (\is_object($item)) {
                $this->items[$i] = clone $item;
            }
        }
    }
}
