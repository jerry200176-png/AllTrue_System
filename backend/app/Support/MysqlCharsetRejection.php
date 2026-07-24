<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Detect MySQL rejections of 4-byte Unicode on utf8mb3 columns (#1378 / F6 write path).
 */
class MysqlCharsetRejection
{
    public static function matches(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $msg = $cur->getMessage();
            if (str_contains($msg, 'Incorrect string value')) {
                return true;
            }
            if (str_contains($msg, 'Conversion from collation') && str_contains($msg, 'impossible')) {
                return true;
            }
            if ($cur instanceof QueryException) {
                $code = (int) ($cur->errorInfo[1] ?? 0);
                if ($code === 1366 || $code === 3988) {
                    return true;
                }
            }
        }
        return false;
    }
}
