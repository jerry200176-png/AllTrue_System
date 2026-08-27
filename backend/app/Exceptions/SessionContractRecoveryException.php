<?php

namespace App\Exceptions;

use RuntimeException;

final class SessionContractRecoveryException extends RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        string $message,
        private readonly array $payload = [],
        private readonly int $status = 422
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): int
    {
        return $this->status;
    }
}
