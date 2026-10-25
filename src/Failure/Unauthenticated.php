<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * The answer could not be shown to come from the terminal: not signed with
 * this till's key, or it answers a different request. Discarded, not believed.
 * The terminal may have taken the payment: inquire before retrying. Seen
 * repeatedly, the key on the till and on the terminal differ.
 */
final class Unauthenticated extends Failure
{
    public function kind(): string
    {
        return 'unauthenticated';
    }
}
