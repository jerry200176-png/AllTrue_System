<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Carbon\Carbon;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentClassSplitContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_calculates_both_contracts_without_mutating_source(): void
    {
        $token = $this->createDirectorToken();
        [$student, $source, $sessionIds] = $this->createTenSessionSource();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/student-classes/{$source->ID}/split-contract/preview", [
                'session_ids' => array_slice($sessionIds, 0, 3),
                'start_date' => '2026-09-01',
            ]);

        $response->assertOk()
            ->assertJsonPath('selected_session_count', 3)
            ->assertJsonPath('source_course.session_count', 10)
            ->assertJsonPath('source_correction.session_count', 5)
            ->assertJsonPath('source_correction.charge', 2500)
            ->assertJsonPath('new_course.session_count', 5)
            ->assertJsonPath('new_course.charge', 2500)
            ->assertJsonPath('new_course.future_session_count', 2);

        $source->refresh();
        $this->assertSame(10, (int) $source->SessionCount);
        $this->assertSame(5000, (int) $source->Charge);
        $this->assertSame($student->id, (int) $source->StudentID);
    }

    public function test_split_creates_balanced_new_contract_then_moves_records_and_corrects_source(): void
    {
        $token = $this->createDirectorToken();
        [$student, $source, $sessionIds] = $this->createTenSessionSource();
        $selectedIds = array_slice($sessionIds, 0, 3);
        $this->createLearningRecord((int) $source->ID, $selectedIds[0]);
        $this->createSignIn((int) $source->ID, $selectedIds[0]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/student-classes/{$source->ID}/split-contract", [
                'session_ids' => $selectedIds,
                'start_date' => '2026-09-01',
                'reason' => '主任確認拆分本期已上課紀錄與剩餘額度',
            ]);

        $response->assertCreated()
            ->assertJsonPath('source_course.session_count', 5)
            ->assertJsonPath('source_course.charge', 2500)
            ->assertJsonPath('source_course.remaining_sessions', 0)
            ->assertJsonPath('new_course.session_count', 5)
            ->assertJsonPath('new_course.charge', 2500)
            ->assertJsonPath('new_course.remaining_sessions', 2)
            ->assertJsonPath('new_course.transferred_session_count', 3)
            ->assertJsonPath('new_course.future_session_count', 2);

        $newId = (int) $response->json('new_course.id');
        $source->refresh();
        $newCourse = StudentClass::find($newId);

        $this->assertSame(5, (int) $source->SessionCount);
        $this->assertSame(2500, (int) $source->Charge);
        $this->assertSame(0, (int) $source->RemainingSessions);
        $this->assertSame(5, (int) $newCourse->SessionCount);
        $this->assertSame(2500, (int) $newCourse->Charge);
        $this->assertSame(2, (int) $newCourse->RemainingSessions);
        $this->assertSame(5, DB::table('ClassSession')->where('StudentClassID', $newId)->count());
        $this->assertSame(5, DB::table('ClassSession')->where('StudentClassID', $source->ID)->count());
        $this->assertSame($newId, (int) DB::table('LearningRecord')->where('ClassSessionID', $selectedIds[0])->value('StudentClassID'));
        $this->assertSame($newId, (int) DB::table('StudentSingIn')->where('ClassSessionID', $selectedIds[0])->value('StudentClassID'));
        $this->assertSame($student->id, (int) $newCourse->StudentID);
    }

    public function test_split_rejects_selecting_a_future_session_without_mutating_source(): void
    {
        $token = $this->createDirectorToken();
        [, $source, $sessionIds] = $this->createTenSessionSource();
        $futureId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $source->ID,
            'SessionDate' => '2026-09-01',
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/student-classes/{$source->ID}/split-contract", [
                'session_ids' => [$futureId],
                'start_date' => '2026-09-01',
                'reason' => '不應允許搬移未使用堂次',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'split_contract_used_sessions_only');
        $source->refresh();
        $this->assertSame(10, (int) $source->SessionCount);
        $this->assertSame(5000, (int) $source->Charge);
        $this->assertSame((int) $source->ID, (int) DB::table('ClassSession')->where('id', $futureId)->value('StudentClassID'));
        $this->assertCount(8, $sessionIds);
    }

    public function test_transfer_split_moves_only_remainder_to_new_subject_and_teacher(): void
    {
        $token = $this->createDirectorToken();
        [, $source, $sessionIds] = $this->createTenSessionSource();
        $mathId = (int) \App\Services\FrontendSubjectIdResolver::resolve('Math');
        $newTeacher = User::create([
            'LoginName' => 'math-teacher-' . uniqid() . '@test.com', 'Name' => '李維',
            'PSW' => 'secret', 'type' => 'T', 'phone' => '0911111111',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/student-classes/{$source->ID}/split-contract", [
                'start_date' => '2026-09-01',
                'reason' => '英文轉數學，剩餘堂數轉新合約',
                'subject_id' => $mathId,
                'teacher_id' => $newTeacher->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('source_course.session_count', 8)
            ->assertJsonPath('new_course.session_count', 2)
            ->assertJsonPath('new_course.transferred_session_count', 0);

        $newCourse = StudentClass::find((int) $response->json('new_course.id'));
        $this->assertSame($mathId, (int) $newCourse->SubjectID);
        $this->assertSame((int) $newTeacher->id, (int) $newCourse->TeacherID);
        $source->refresh();
        $this->assertNotSame($mathId, (int) $source->SubjectID); // old contract keeps its subject
        $this->assertSame(8, DB::table('ClassSession')->where('StudentClassID', $source->ID)->count());
        $this->assertCount(8, $sessionIds);
    }

    public function test_paid_transfer_carries_balance_with_linked_transfer_rows_and_new_subject_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken();
            [$source, $invoice, $payment] = $this->createPaidSource();
            $mathId = (int) \App\Services\FrontendSubjectIdResolver::resolve('Math');
            $newTeacher = $this->makeTeacher();
            $body = [
                'subject' => 'Math', 'teacher_id' => $newTeacher, 'start_date' => '2026-10-07',
                'slots' => [['weekday' => 3, 'time' => '15:00', 'duration_minutes' => 120]],
                'reason' => '英文轉數學',
            ];

            $this->withHeaders(['Authorization' => "Bearer {$token}"])
                ->postJson("/api/v1/student-classes/{$source->ID}/split-contract/preview", $body)
                ->assertOk()->assertJsonPath('paid_transfer.transfer_amount', 6000)
                ->assertJsonPath('source_correction.charge', 6000)->assertJsonPath('new_course.charge', 6000);

            $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
                ->postJson("/api/v1/student-classes/{$source->ID}/split-contract", $body);
            $res->assertCreated();
            $newId = (int) $res->json('new_course.id');
            $new = StudentClass::find($newId);

            $this->assertSame($mathId, (int) $new->SubjectID);
            $this->assertSame($newTeacher, (int) $new->TeacherID);
            $this->assertSame(3, (int) $new->week);
            $this->assertSame(1, (int) $new->Paid);
            $this->assertSame(6000, (int) $new->Charge);
            $this->assertNotSame($mathId, (int) $source->fresh()->SubjectID);
            $this->assertSame(4, DB::table('ClassSession')->where('StudentClassID', $source->ID)->where('Status', 'attended')->count());
            $this->assertSame(4, DB::table('ClassSession')->where('StudentClassID', $newId)->where('Status', 'scheduled')->count());
            $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $source->ID)->where('Status', 'scheduled')->count());

            // Original payment untouched.
            $orig = Payment::find($payment->id);
            $this->assertSame(12000, (int) $orig->Amount);
            $this->assertSame('cash', (string) $orig->Method);
            $this->assertSame((int) $invoice->id, (int) $orig->InvoiceID);

            $srcInv = Invoice::find($invoice->id);
            $newInv = Invoice::where('StudentClassID', $newId)->first();
            $this->assertSame([6000, 6000], [(int) $srcInv->TotalAmount, (int) $srcInv->PaidAmount]);
            $this->assertSame([6000, 6000, 'paid'], [(int) $newInv->TotalAmount, (int) $newInv->PaidAmount, (string) $newInv->Status]);
            $out = Payment::where('InvoiceID', $srcInv->id)->where('Method', 'transfer_out')->first();
            $in = Payment::where('InvoiceID', $newInv->id)->where('Method', 'transfer_in')->first();
            $this->assertSame(-6000, (int) $out->Amount);
            $this->assertSame(6000, (int) $in->Amount);
            // Ledger nets to the contract totals; transfer rows net to zero overall.
            $this->assertSame(6000, (int) Payment::where('InvoiceID', $srcInv->id)->sum('Amount'));
            $this->assertSame(6000, (int) Payment::where('InvoiceID', $newInv->id)->sum('Amount'));
            $this->assertSame(0, (int) Payment::whereIn('Method', ['transfer_out', 'transfer_in'])->sum('Amount'));
            $this->assertSame(12000, (int) Payment::where('Method', 'cash')->sum('Amount'));

            $this->assertSame(2, DB::table('course_contract_group_members')->whereIn('student_class_id', [$source->ID, $newId])->count());
            $this->assertDatabaseHas('security_audit_events', ['event_type' => 'student_class.contract_transfer']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_paid_transfer_refuses_partially_paid_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken();
            [$source, $invoice, $payment] = $this->createPaidSource();
            $payment->update(['Amount' => 6000]);
            $invoice->update(['PaidAmount' => 6000, 'Status' => 'partial']);

            $this->withHeaders(['Authorization' => "Bearer {$token}"])
                ->postJson("/api/v1/student-classes/{$source->ID}/split-contract", [
                    'subject' => 'Math', 'start_date' => '2026-10-07', 'reason' => '不應成功',
                ])->assertStatus(422)->assertJsonPath('code', 'transfer_paid_not_simple');

            $this->assertSame(1, StudentClass::where('StudentID', $source->StudentID)->count());
            $this->assertSame(0, Payment::whereIn('Method', ['transfer_out', 'transfer_in'])->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return array{0: StudentClass, 1: Invoice, 2: Payment} */
    private function createPaidSource(): array
    {
        $student = Student::create([
            'name' => '轉課測試生-' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
        $source = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1,
            'SubjectID' => (int) \App\Services\FrontendSubjectIdResolver::resolve('English'),
            'TeacherID' => $this->makeTeacher(), 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-02',
            'TotalHours' => 16, 'Charge' => 12000, 'Pay' => 12000, 'Paid' => 1, 'PayDate' => '2026-09-01',
            'Rate' => 1500, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'UsedSessions' => 4, 'ClassType' => 'one_on_one',
            'week' => 3, 'time' => '13:00:00',
        ]);
        foreach (['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23'] as $d) {
            DB::table('ClassSession')->insert(['StudentClassID' => $source->ID, 'SessionDate' => $d,
                'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'attended']);
        }
        foreach (['2026-09-30', '2026-10-07', '2026-10-14', '2026-10-21'] as $d) {
            DB::table('ClassSession')->insert(['StudentClassID' => $source->ID, 'SessionDate' => $d,
                'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled']);
        }
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $source->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 12000, 'PaidAmount' => 12000, 'Status' => 'paid', 'Note' => '',
        ]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $source->ID, 'Description' => '英文 8 堂', 'Amount' => 12000]);
        $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 12000, 'PaidAt' => '2026-09-01', 'Method' => 'cash', 'Note' => '']);

        return [$source, $invoice, $payment];
    }

    private function makeTeacher(): int
    {
        $u = User::create([
            'LoginName' => 'xfer-teacher-' . uniqid() . '@test.com', 'Name' => '轉課老師',
            'PSW' => 'secret', 'type' => 'T', 'phone' => '0922222222',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => 0, 'Approved' => 1]);

        return (int) $u->id;
    }

    private function createTenSessionSource(): array
    {
        $student = Student::create([
            'name' => '拆分測試生-' . uniqid(),
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $source = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-08-01',
            'TotalHours' => 20,
            'Charge' => 5000,
            'Paid' => 0,
            'Rate' => 500,
            'rate_unit' => 'session',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => 2,
            'UsedSessions' => 8,
            'ClassType' => 'one_on_one',
        ]);

        $sessionIds = [];
        for ($i = 0; $i < 8; $i++) {
            $sessionIds[] = DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $source->ID,
                'SessionDate' => sprintf('2026-08-%02d', $i + 1),
                'StartTime' => '23:00',
                'EndTime' => '23:30',
                'Status' => 'attended',
            ]);
        }

        return [$student, $source, $sessionIds];
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'dir-split-' . uniqid() . '@test.com',
            'Name' => '拆分主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function createLearningRecord(int $courseId, int $sessionId): void
    {
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'CreatedByUserID' => 1,
            'Content' => '拆分測試評量',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSignIn(int $courseId, int $sessionId): void
    {
        DB::table('StudentSingIn')->insert([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $sessionId,
            'StudentID' => 1,
            'TeacherID' => 1,
            'Status' => 'present',
            'SignInDT' => now(),
        ]);
    }
}
