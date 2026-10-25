<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Link;

/**
 * ECR Web Service: HTTPS to the Amwal Hub, which relays to the terminal
 * configured for Web Service mode (`ecrMode: 4`). The Hub address comes from
 * {@see \AmwalPay\Ecr\EcrConfig::$environment}.
 */
final class WebServiceLink extends EcrLink
{
    /**
     * @param string $merchantId Numeric backend merchant ID.
     * @param string $terminalId Numeric backend terminal ID.
     */
    public function __construct(
        public readonly string $merchantId,
        public readonly string $terminalId,
    ) {
    }
}
