<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Channel;

/**
 * How bytes reach a terminal, and nothing else.
 *
 * Signing a request, checking the answer and deciding what a response code
 * means all belong to {@see \AmwalPay\Ecr\EcrTerminal} and are the same however
 * the bytes travel. A channel carries one framed request and brings back one
 * framed answer.
 */
interface EcrChannel
{
    /** What to show an operator when this link fails, e.g. `192.168.1.50:9100`. */
    public function endpoint(): string;

    public function host(): string;

    /** Zero when the link is not a socket. */
    public function port(): int;

    /**
     * Whether the terminal can be reached, without sending anything.
     *
     * @return string|null null when reachable, otherwise the reason
     */
    public function probe(float $timeout): ?string;

    /**
     * Sends one request body and returns the answer body, both UTF-8 JSON
     * without the length header. Framing is the channel's to apply.
     *
     * @throws EcrChannelTimeout the terminal accepted the request and did not answer within `$timeout` seconds
     * @throws EcrIOException anything else that broke the link
     */
    public function exchange(string $body, float $timeout): string;
}
