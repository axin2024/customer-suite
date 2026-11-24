<?php

declare(strict_types=1);

namespace CustomerSuite;

use RuntimeException;

final class BusinessError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
