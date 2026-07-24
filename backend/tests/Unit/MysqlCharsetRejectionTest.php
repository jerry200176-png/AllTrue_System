<?php

namespace Tests\Unit;

use App\Support\MysqlCharsetRejection;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class MysqlCharsetRejectionTest extends TestCase
{
    public function test_matches_incorrect_string_value_1366(): void
    {
        $previous = new PDOException(
            "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F\\x93\\x85' for column 'Memo'"
        );
        $previous->errorInfo = ['HY000', 1366, 'Incorrect string value'];
        $e = new QueryException('insert into StudentClass', [], $previous);
        $e->errorInfo = ['HY000', 1366, 'Incorrect string value'];

        $this->assertTrue(MysqlCharsetRejection::matches($e));
    }

    public function test_matches_mysql8_conversion_impossible_3988(): void
    {
        $previous = new PDOException(
            'SQLSTATE[HY000]: General error: 3988 Conversion from collation utf8mb4_unicode_ci into utf8mb3_unicode_ci impossible for parameter'
        );
        $previous->errorInfo = ['HY000', 3988, 'Conversion from collation'];
        $e = new QueryException('insert into StudentClass', [], $previous);
        $e->errorInfo = ['HY000', 3988, 'Conversion from collation'];

        $this->assertTrue(MysqlCharsetRejection::matches($e));
    }

    public function test_matches_savepoint_wrapper_with_previous_charset_error(): void
    {
        $innerPrevious = new PDOException('Incorrect string value: emoji');
        $inner = new QueryException('insert', [], $innerPrevious);
        $outer = new PDOException('SQLSTATE[42000]: SAVEPOINT trans2 does not exist', 0, $inner);

        $this->assertTrue(MysqlCharsetRejection::matches($outer));
    }

    public function test_ignores_unrelated_query_exception(): void
    {
        $previous = new PDOException(
            "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'RoomID'"
        );
        $previous->errorInfo = ['42S22', 1054, 'Unknown column'];
        $e = new QueryException('insert', [], $previous);
        $e->errorInfo = ['42S22', 1054, 'Unknown column'];

        $this->assertFalse(MysqlCharsetRejection::matches($e));
    }
}
