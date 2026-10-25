<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Json;

/**
 * A JSON number kept exactly as it was written.
 *
 * PHP's own decoder turns `961.10` into the float `961.1` and `1E3` into
 * `1000.0`. A signature is computed over the number's text, so a reader that
 * re-spells numbers would fail to verify an answer the terminal signed
 * correctly. The text is kept, and only ever compared or copied as text.
 */
final class JsonNumber
{
    public function __construct(public readonly string $raw)
    {
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
