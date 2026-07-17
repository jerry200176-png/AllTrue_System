<?php

namespace Tests\Unit;

use App\Services\NotificationSyncService;
use Illuminate\Database\QueryException;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class NotificationSyncServiceTest extends TestCase
{
    public function test_unrelated_integrity_violation_is_not_treated_as_source_key_race(): void
    {
        $previous = new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'other_unique'",
            23000
        );
        $previous->errorInfo = ['23000', 1062, "Duplicate entry '1' for key 'other_unique'"];
        $exception = new QueryException('insert into example values (?)', [1], $previous);

        $method = new ReflectionMethod(NotificationSyncService::class, 'isSourceKeyDuplicate');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, $exception));
    }
}
