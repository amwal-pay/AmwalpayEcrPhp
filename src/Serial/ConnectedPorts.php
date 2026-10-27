<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

/**
 * The ports to show for one terminal, and the row to select. `$ports` is only
 * what is plugged in on this call.
 */
final class ConnectedPorts
{
    /**
     * @param list<SerialPortChoice> $ports
     * @param string $selected The {@see SerialPortChoice::selection()} to store on the terminal.
     */
    public function __construct(
        public readonly array $ports,
        public readonly string $selected,
    ) {
    }
}
