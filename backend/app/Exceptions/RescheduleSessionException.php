<?php

namespace App\Exceptions;

use RuntimeException;

class RescheduleSessionException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $httpStatus = 422,
        private ?string $errorCode = null,
        private array $context = []
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
