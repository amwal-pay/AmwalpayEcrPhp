<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Channel;

/**
 * The link to the terminal failed.
 *
 * What the failure means for the payment is decided from the message, the
 * same way the Windows SDK reads `IOException`: "closed the connection" means
 * the request may have arrived (connection lost), "not valid JSON" or "empty
 * message" means the answer was unreadable (malformed), anything else means
 * nothing was sent (unreachable).
 */
class EcrIOException extends \RuntimeException
{
}
