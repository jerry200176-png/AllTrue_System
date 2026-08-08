<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TeacherEligibilityMigrationContractTest extends TestCase
{
    public function test_teacher_eligibility_migration_is_additive_and_reversible(): void
    {
        $path = __DIR__ . '/../../database/migrations/2026_08_08_000001_create_teacher_eligibility_tables.php';
        $source = file_get_contents($path);

        self::assertIsString($source);
        foreach ([
            "Schema::create('teacher_payroll_events'",
            "Schema::create('teacher_payroll_achievements'",
            "Schema::create('teacher_payroll_deductions'",
            'public function down',
            "Schema::dropIfExists('teacher_payroll_deductions'",
            "Schema::dropIfExists('teacher_payroll_achievements'",
            "Schema::dropIfExists('teacher_payroll_events'",
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }

        self::assertStringContainsString("->unsignedBigInteger('teacher_id')->nullable()", $source);
        self::assertStringContainsString("->string('status', 24)->default('pending')", $source);
    }
}
