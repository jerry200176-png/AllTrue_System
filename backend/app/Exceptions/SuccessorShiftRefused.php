<?php

namespace App\Exceptions;

/** In-app #410: a leave successor shift that must not be written; carries the HTTP status and the re-computed plan. */
class SuccessorShiftRefused extends \RuntimeException
{
    /** @param  array<string, mixed>  $plan */
    public function __construct(string $message, private int $status, private array $plan = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function plan(): array
    {
        return $this->plan;
    }
}
