<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Channel;

/**
 * The terminal accepted the request and did not answer in time. Not a refusal:
 * the transaction may still have run.
 */
final class EcrChannelTimeout extends EcrIOException
{
}
