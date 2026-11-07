<?php

namespace App\Services;

/** Expected, user-facing school-merge failure (never wraps DB errors, so no SQL leaks). */
class SchoolMergeException extends \RuntimeException
{
}
