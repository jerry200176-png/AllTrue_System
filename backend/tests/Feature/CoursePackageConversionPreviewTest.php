<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoursePackageConversionPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_count_course_is_reported_as_read_only_conversion_candidate(): void
    {
        $student = $this->createStudent(1);
        $course = $this->createCountCourse($student->id);
        $before = (array) DB::table('StudentClass')->where('ID', $course->ID)->first();

        $response = $this->getPreview($this->createDirectorToken([1]), $course);

        $response->assertOk()->assertJson([
            'read_only' => true,
            'mode' => 'single_course_to_shared_package_preview',
            'can_convert' => true,
            'recommendation' => 'conversion_ready_for_review',
            'evidence' => [
                'attendance_records' => 0,
                'learning_records' => 0,
                'invoices' => 0,
                'payment_reports' => 0,
                'legacy_payment_state' => false,
            ],
        ]);
        $course->refresh();
        $after = (array) DB::table('StudentClass')->where('ID', $course->ID)->first();
        $this->assertSame($before, $after, 'Read-only preview must not mutate the source contract');
    }

    public function test_preview_blocks_usage_and_financial_history_with_actionable_reasons(): void
    {
        $student = $this->createStudent(1);
        $course = $this->createCountCourse($student->id, [
            'UsedSessions' => 1,
            'RemainingSessions' => 7,
            'Paid' => 1,
            'Pay' => 100,
        ]);

        Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 8000,
            'PaidAmount' => 100,
            'Status' => 'partial',
        ]);
        $itemOnlyInvoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => null,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 2000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);
        InvoiceItem::create([
            'InvoiceID' => $itemOnlyInvoice->id,
            'StudentClassID' => $course->ID,
            'Description' => '單科課程',
            'Amount' => 2000,
        ]);
        PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'cash',
            'reported_amount' => 100,
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'conversion-preview-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $response = $this->getPreview($this->createDirectorToken([1]), $course);

        $response->assertOk()->assertJsonPath('can_convert', false);
        $this->assertEqualsCanonicalizing(
            ['usage_history_exists', 'invoice_exists', 'payment_report_exists', 'payment_state_exists'],
            collect($response->json('blocking_reasons'))->pluck('code')->all(),
        );
        $this->assertSame(2, (int) $response->json('evidence.invoices'));
        $this->assertSame(1, (int) $response->json('evidence.invoice_items'));
        $this->assertSame(1, (int) $response->json('evidence.payment_reports'));
        $this->assertSame('create_new_package', $response->json('recommendation'));
    }

    public function test_preview_respects_campus_scope(): void
    {
        $student = $this->createStudent(1);
        $course = $this->createCountCourse($student->id);

        $this->getPreview($this->createDirectorToken([2]), $course)->assertForbidden();
    }

    public function test_preview_blocks_even_a_future_non_cancelled_class_session(): void
    {
        $student = $this->createStudent(1);
        $course = $this->createCountCourse($student->id);
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2099-01-01',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'scheduled',
        ]);

        $response = $this->getPreview($this->createDirectorToken([1]), $course);

        $response->assertOk()->assertJsonPath('can_convert', false);
        $this->assertContains(
            'class_sessions_exist',
            collect($response->json('blocking_reasons'))->pluck('code')->all(),
        );
    }

    private function getPreview(string $token, StudentClass $course)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/package-conversion-preview");
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'conversion-preview-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => Carbon::now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '轉換預檢學生' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createCountCourse(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(),
            'TotalHours' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
            'Rate' => 500,
            'rate_unit' => 'session',
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ], $overrides));
    }
}
