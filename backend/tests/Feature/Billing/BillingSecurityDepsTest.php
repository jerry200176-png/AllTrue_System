<?php

namespace Tests\Feature\Billing;

use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Focused evidence for S1 security deps (land before fee service):
 * - SecurityAuditEvent::append(..., required: true) rethrows
 * - StudentClass::hasOperationalHistory blocks on invoice_corrections rows
 *
 * Fixture checklist (avoid DDL under RefreshDatabase / varchar overflows):
 * - no Schema::rename/drop/alter inside RefreshDatabase cases (MySQL DDL implicit-commit)
 * - Student.name ≤ varchar(32); invoice_corrections.reason ≤ 80; idempotency_key char(36)
 * - required-audit failure via test-scoped beforeExecuting (no swallowed rollback errors)
 */
class BillingSecurityDepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_audit_append_rethrows_when_insert_fails(): void
    {
        $this->assertTrue(Schema::hasTable('security_audit_events'));

        // Test-scoped insert failure only — no DDL (RefreshDatabase outer txn stays honest).
        $armed = true;
        DB::connection()->beforeExecuting(function ($query) use (&$armed) {
            if (!$armed) {
                return;
            }
            if (stripos((string) $query, 'insert') !== false
                && stripos((string) $query, 'security_audit_events') !== false) {
                throw new \RuntimeException('forced audit insert failure for required=true test');
            }
        });

        try {
            // Default path: insert failure is swallowed (must not become a 500 for soft callers).
            SecurityAuditEvent::append('billing.security_deps_probe', 'success', [
                'correlation_id' => (string) Str::uuid(),
                'campus_id' => 1,
                'actor_type' => 'user',
                'actor_id' => 1,
            ], [], false);

            $threw = false;
            $caught = null;
            try {
                SecurityAuditEvent::append('billing.security_deps_probe', 'success', [
                    'correlation_id' => (string) Str::uuid(),
                    'campus_id' => 1,
                    'actor_type' => 'user',
                    'actor_id' => 1,
                ], [], true);
            } catch (\Throwable $e) {
                $threw = true;
                $caught = $e;
            }
            $this->assertTrue($threw, 'required=true must rethrow when audit insert cannot succeed');
            $this->assertInstanceOf(\RuntimeException::class, $caught);
            $this->assertStringContainsString('forced audit insert failure', $caught->getMessage());
        } finally {
            $armed = false;
        }
    }

    public function test_invoice_corrections_row_counts_as_operational_history(): void
    {
        $this->assertTrue(Schema::hasTable('invoice_corrections'));
        // students.name is varchar(32) strict — keep synthetic name ≤32 (uuid alone is 36).
        $student = Student::create([
            'name' => 'sd-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 28),
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'T',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 0,
            'Charge' => 500,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
        $this->assertFalse(StudentClass::hasOperationalHistory([(int) $course->ID]));

        DB::table('invoice_corrections')->insert([
            'original_invoice_id' => 900001,
            'replacement_invoice_id' => 900002,
            'student_class_id' => (int) $course->ID,
            'amount' => 500,
            'reason' => 'Synthetic security-deps history',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertTrue(StudentClass::hasOperationalHistory([(int) $course->ID]));
    }
}
