<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Link\EcrLink;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\WebService\WebServiceSecureHash;

/**
 * A validated configuration for one terminal link, built by
 * {@see EcrSessions::plan()} so the app does not repeat transport rules.
 * `issues()` lists what is wrong; an empty list means it can be opened.
 */
final class EcrSessionPlan
{
    /** @param list<string> $issues */
    public function __construct(
        public readonly EcrLink $link,
        public readonly EcrConfig $config,
        private readonly array $issues = [],
    ) {
    }

    /** @return list<string> What is wrong with this plan; empty when it can be opened. */
    public function issues(): array
    {
        return $this->issues;
    }

    public function isReady(): bool
    {
        return $this->issues === [];
    }

    public function usesLan(): bool
    {
        return $this->link instanceof LanLink;
    }

    public function usesWebService(): bool
    {
        return $this->link instanceof WebServiceLink;
    }

    /** Which of the two secrets this plan expects: Wi-Fi and Web Service keys are issued separately. */
    public function signingKeyLabel(): string
    {
        return self::keyLabelFor($this->link);
    }

    public function signingKeyConfigured(): bool
    {
        return $this->config->secureHashKey !== '';
    }

    /** Operator-facing summary, never a secret: `192.168.1.50:9100` or `UAT · merchant 10001 · terminal 20002`. */
    public function connectionSummary(): string
    {
        if ($this->link instanceof LanLink) {
            return $this->link->host . ':' . $this->config->port;
        }
        if ($this->link instanceof WebServiceLink) {
            $summary = $this->config->environment->value;
            if ($this->link->merchantId !== '') {
                $summary .= ' · merchant ' . $this->link->merchantId;
            }
            if ($this->link->terminalId !== '') {
                $summary .= ' · terminal ' . $this->link->terminalId;
            }
            return $summary;
        }
        throw new \LogicException('Unsupported ECR link: ' . $this->link::class);
    }

    /** @internal */
    public static function keyLabelFor(EcrLink $link): string
    {
        return match (true) {
            $link instanceof LanLink => SecureHash::KEY_LABEL,
            $link instanceof WebServiceLink => WebServiceSecureHash::KEY_LABEL,
            default => throw new \LogicException('Unsupported ECR link: ' . $link::class),
        };
    }
}
