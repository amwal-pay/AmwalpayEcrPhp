<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Exception;

/**
 * The call was refused before anything was built or sent: a merchant reference
 * the wire cannot carry, an amount that is not a positive figure in the
 * currency's precision, a receipt number or date in the wrong shape.
 *
 * Nothing reached the terminal, so there is no outcome to reconcile. The
 * Windows SDK throws `ArgumentException` for the same cases.
 */
class InvalidRequestException extends \InvalidArgumentException
{
}
