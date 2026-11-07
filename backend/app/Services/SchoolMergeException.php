<?php

namespace App\Services;

/** Expected, user-facing school-merge failure (never wraps DB errors, so no SQL leaks). */
class SchoolMergeException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
