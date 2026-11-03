<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingPayableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingRefundTest extends TestCase
{
    use RefreshDatabase;

    private function token(int $campusId = 1): string
    {
        $user = User::create(['LoginName' => 'refund-' . uniqid() . '@example.com', 'Name' => 'Director fixture', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    /** 8 lessons x 500 = 4000 paid, 3 held: refundable 2500. */
    private function course(string $mode = 'count'): StudentClass
    {
        $student = Student::create(['name' => 'Fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1, 'by1' => 2, 'Period' => 4,
            'StartDate' => '2026-10-01', 'EndDate' => '2026-12-31', 'TotalHours' => 16, 'Charge' => 4000, 'Rate' => 500, 'SessionCount' => 8,
            'RemainingSessions' => 5, 'Paid' => 1, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => $mode]);
        foreach (['2026-10-05' => 'attended', '2026-10-12' => 'late', '2026-10-19' => 'absent', '2026-10-26' => 'cancelled'] as $d => $st) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $st]);
        }
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => now()->addDays(3)->toDateString(), 'StartTime' => '23:00', 'EndTime' => '23:59', 'Status' => 'scheduled']);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => 4000, 'PaidAmount' => 4000, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 4000, 'PaidAt' => '2026-10-01', 'Method' => 'cash']);

        return $course;
    }

    private function body(int $amount = 2500): array
    {
        return ['amount' => $amount, 'method' => 'cash', 'date' => now()->toDateString(), 'reason' => 'moved away'];
    }

    private function send(string $token, StudentClass $c, array $body)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$c->ID}/refund", $body);
    }

    private function state(StudentClass $c): array
    {
        return app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$c->ID], [$c->fresh()])[$c->ID];
    }

    public function test_preview_is_computed_by_the_server(): void
    {
        $c = $this->course();
        $this->withToken($this->token())->getJson("/api/v1/student-classes/{$c->ID}/refund/preview")->assertOk()
            ->assertExactJson(['paid' => 4000, 'used_lessons' => 3, 'rate' => 500, 'refundable' => 2500]);
    }

    public function test_refund_balances_the_books_and_ends_the_contract(): void
    {
        $c = $this->course();
        $this->assertSame('paid', $this->state($c)['status']);
        $this->send($this->token(), $c, $this->body(2000))->assertOk();

        $state = $this->state($c);
        $this->assertSame('paid', $state['status']); // not owing: total was cut together with the money
        $this->assertSame(0, $state['outstanding']);
        $this->assertSame(2000, $state['applied']);
        $this->assertSame(-2000, (int) Payment::query()->where('Amount', '<', 0)->sum('Amount'));
        $this->assertSame(2000, (int) Invoice::query()->value('TotalAmount'));
        $c = $c->fresh();
        $this->assertSame([1, 'refunded', 0], [(int) $c->Stop, $c->closed_reason, (int) $c->RemainingSessions]);
        $this->assertSame(now()->toDateString(), substr((string) $c->EndDate, 0, 10));
        $this->assertSame(0, ClassSession::where('StudentClassID', $c->ID)->where('Status', 'scheduled')->count());
        $audit = DB::table('security_audit_events')->where('event_type', 'student_class.refund')->first();
        $this->assertNotNull($audit);
        $this->assertSame(64, strlen((string) (json_decode((string) $audit->metadata, true)['reason_hash'] ?? '')));
        $rows = $this->withToken($this->token())->getJson('/api/v1/accounting/settled-courses?branch_id=1')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('student_class_id', $c->ID);
        $this->assertNotNull($row);
        $this->assertSame('歷史 · 已退費', $row['reconciliation_label']);
    }

    public function test_over_refund_is_refused(): void
    {
        $c = $this->course();
        $this->send($this->token(), $c, $this->body(2501))->assertStatus(422);
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
        $this->assertSame(0, (int) $c->fresh()->Stop);
    }

    public function test_double_refund_is_refused(): void
    {
        $c = $this->course();
        $token = $this->token();
        $this->send($token, $c, $this->body())->assertOk();
        $this->send($token, $c, $this->body())->assertStatus(409);
        $this->assertSame(1, Payment::where('Amount', '<', 0)->count());
    }

    public function test_other_campus_director_is_refused(): void
    {
        $c = $this->course();
        $this->send($this->token(2), $c, $this->body())->assertForbidden();
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
    }

    public function test_monthly_contract_is_refused(): void
    {
        $c = $this->course('date');
        $this->send($this->token(), $c, $this->body())->assertStatus(422);
    }

    public function test_refund_rolls_back_when_the_audit_row_cannot_be_written(): void
    {
        $c = $this->course();
        $token = $this->token();
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into `security_audit_events`')) {
                $fail = false;
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $this->send($token, $c, $this->body())->assertStatus(500);
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
        $this->assertSame(4000, (int) Invoice::query()->value('TotalAmount'));
        $this->assertSame(0, (int) $c->fresh()->Stop);
    }
}
