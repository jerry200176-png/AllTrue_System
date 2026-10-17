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
 */
class BillingSecurityDepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_audit_append_rethrows_when_insert_fails(): void
    {
        $this->assertTrue(Schema::hasTable('security_audit_events'));
        Schema::rename('security_audit_events', 'security_audit_events_hidden_for_required_test');
        try {
            // Default path: insert failure is swallowed (must not become a 500 for soft callers).
            SecurityAuditEvent::append('billing.security_deps_probe', 'success', [
                'correlation_id' => (string) Str::uuid(),
                'campus_id' => 1,
                'actor_type' => 'user',
                'actor_id' => 1,
            ], [], false);

            $threw = false;
            try {
                SecurityAuditEvent::append('billing.security_deps_probe', 'success', [
                    'correlation_id' => (string) Str::uuid(),
                    'campus_id' => 1,
                    'actor_type' => 'user',
                    'actor_id' => 1,
                ], [], true);
            } catch (\Throwable $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'required=true must rethrow when audit insert cannot succeed');
        } finally {
            if (Schema::hasTable('security_audit_events_hidden_for_required_test')) {
                Schema::rename('security_audit_events_hidden_for_required_test', 'security_audit_events');
            }
        }
    }

    public function test_invoice_corrections_row_counts_as_operational_history(): void
    {
        $this->assertTrue(Schema::hasTable('invoice_corrections'));
        $student = Student::create([
            'name' => 'security-deps-'.Str::uuid(),
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
