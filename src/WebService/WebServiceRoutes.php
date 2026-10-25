<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService;

use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Internal\EnvironmentUrls;

/** Resolves the Hub URL and the fixed ECR operation paths for an environment. */
final class WebServiceRoutes
{
    public function __construct(private readonly EcrEnvironment $environment)
    {
    }

    public function hub(): string
    {
        return EnvironmentUrls::hub($this->environment);
    }

    public function path(WebServiceOperation $operation): string
    {
        return $operation->path();
    }

    public function url(WebServiceOperation $operation): string
    {
        return rtrim($this->hub(), '/') . $operation->path();
    }

    /** The path for a transaction type, or null for a receipt, which has no Web Service operation. */
    public function pathFor(EcrTransactionType $type): ?string
    {
        return WebServiceOperation::forType($type)?->path();
    }
}
