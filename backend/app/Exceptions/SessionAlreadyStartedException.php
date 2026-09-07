<?php

namespace App\Exceptions;

use RuntimeException;

class SessionAlreadyStartedException extends RuntimeException
{
    public function __construct(string $message = '已達或超過開課時間，無法於家長端線上請假，請直接聯絡分校處理。')
    {
        parent::__construct($message);
    }
}
