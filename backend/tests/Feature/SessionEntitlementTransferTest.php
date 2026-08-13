<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\SessionEntitlementTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SessionEntitlementTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_command_defaults_to_read_only_preview(): void
    {
        $student = Student::create(['name' => '命令預覽測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = $this->course($student->id);
        $target = $this->course($student->id);
        $session = ClassSession::create(['StudentClassID' => $source->ID, 'SessionDate' => '2026-08-05', 'StartTime' => '19:30:00', 'EndTime' => '21:30:00', 'Status' => 'attended']);

        $exit = Artisan::call('repair:transfer-session-entitlement', [
            '--source-class' => $source->ID,
            '--target-class' => $target->ID,
            '--session-id' => $session->id,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry-run complete; no data changed.', Artisan::output());
        $this->assertSame($source->ID, (int) $session->refresh()->StudentClassID);
        $this->assertDatabaseCount('session_entitlement_transfers', 0);
    }

    public function test_completed_session_transfer_moves_all_attendance_ownership_and_recomputes_counters(): void
    {
        $student = Student::create([
            'name' => '堂次轉移測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $source = $this->course($student->id, ['SessionCount' => 8, 'UsedSessions' => 9, 'RemainingSessions' => 0, 'Paid' => 1]);
        $target = $this->course($student->id, ['SessionCount' => 8, 'UsedSessions' => 0, 'RemainingSessions' => 8, 'Paid' => 0]);

        for ($i = 0; $i < 8; $i++) {
            ClassSession::create([
                'StudentClassID' => $source->ID,
                'SessionDate' => sprintf('2026-06-%02d', 1 + $i),
                'StartTime' => '19:30:00',
                'EndTime' => '21:30:00',
                'Status' => 'attended',
                'Note' => '既有已上課',
            ]);
        }

        $session = ClassSession::create([
            'StudentClassID' => $source->ID,
            'SessionDate' => '2026-08-05',
            'StartTime' => '19:30:00',
            'EndTime' => '21:30:00',
            'Status' => 'attended',
            'Note' => '已上課',
        ]);
        $learningRecord = LearningRecord::create([
            'StudentClassID' => $source->ID,
            'ClassSessionID' => $session->id,
            'TeacherID' => $source->TeacherID,
            'Status' => 'approved',
            'Content' => '堂次轉移測試評量',
            'SessionDate' => '2026-08-05',
            'StartTime' => '19:30:00',
            'EndTime' => '21:30:00',
        ]);
        $signIn = StudentSignIn::create([
            'StudentClassID' => $source->ID,
            'StudentID' => $student->id,
            'TeacherID' => $source->TeacherID,
            'ClassSessionID' => $session->id,
            'Status' => 'present',
            'SessionDeducted' => true,
            'SignInDT' => '2026-08-05 19:30:00',
            'SignOutDT' => '2026-08-05 21:30:00',
            'MDT' => now(),
        ]);
        $ledger = SessionDeductionLedger::create([
            'student_class_id' => $source->ID,
            'class_session_id' => $session->id,
            'event_type' => 'deduct',
            'source' => 'attendance',
        ]);

        $result = app(SessionEntitlementTransferService::class)->transfer(
            $source->ID,
            $target->ID,
            $session->id,
            'confirmed makeup was charged to the wrong batch',
            'P0-TEST-001',
        );

        $this->assertSame($target->ID, (int) $session->refresh()->StudentClassID);
        $this->assertSame($target->ID, (int) $learningRecord->refresh()->StudentClassID);
        $this->assertSame($target->ID, (int) $signIn->refresh()->StudentClassID);
        $this->assertSame($target->ID, (int) $ledger->refresh()->student_class_id);
        $this->assertSame(8, (int) $result['source']->refresh()->UsedSessions);
        $this->assertSame(0, (int) $result['source']->RemainingSessions);
        $this->assertSame(1, (int) $result['target']->refresh()->UsedSessions);
        $this->assertSame(7, (int) $result['target']->RemainingSessions);
        $this->assertDatabaseHas('session_entitlement_transfers', [
            'id' => $result['transfer']->id,
            'class_session_id' => $session->id,
            'source_student_class_id' => $source->ID,
            'target_student_class_id' => $target->ID,
        ]);
    }

    public function test_preview_rejects_cross_student(): void
    {
        $studentA = Student::create(['name' => '來源學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $studentB = Student::create(['name' => '目標學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = $this->course($studentA->id);
        $target = $this->course($studentB->id);
        $session = ClassSession::create([
            'StudentClassID' => $source->ID,
            'SessionDate' => '2026-08-05',
            'StartTime' => '19:30:00',
            'EndTime' => '21:30:00',
            'Status' => 'attended',
        ]);

        $preview = app(SessionEntitlementTransferService::class)->preview($source->ID, $target->ID, $session->id);
        $this->assertFalse($preview['ok']);
        $this->assertContains('來源與目標不是同一位學生', $preview['errors']);
    }

    public function test_preview_rejects_same_student_target_slot_collision(): void
    {
        $student = Student::create(['name' => '重複堂測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = $this->course($student->id);
        $target = $this->course($student->id);
        $session = ClassSession::create([
            'StudentClassID' => $source->ID,
            'SessionDate' => '2026-08-05',
            'StartTime' => '19:30:00',
            'EndTime' => '21:30:00',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $target->ID,
            'SessionDate' => '2026-08-05',
            'StartTime' => '19:30:00',
            'EndTime' => '21:30:00',
            'Status' => 'leave',
        ]);

        $preview = app(SessionEntitlementTransferService::class)->preview($source->ID, $target->ID, $session->id);
        $this->assertFalse($preview['ok']);
        $this->assertContains('目標批次已有同日同時段堂次，禁止製造重複堂', $preview['errors']);
    }

    public function test_preview_rejects_same_batch_and_full_target(): void
    {
        $student = Student::create(['name' => '容量防線測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = $this->course($student->id);
        $target = $this->course($student->id, ['SessionCount' => 1]);
        $sourceSession = ClassSession::create(['StudentClassID' => $source->ID, 'SessionDate' => '2026-08-05', 'StartTime' => '19:30:00', 'EndTime' => '21:30:00', 'Status' => 'attended']);
        ClassSession::create(['StudentClassID' => $target->ID, 'SessionDate' => '2026-08-06', 'StartTime' => '19:30:00', 'EndTime' => '21:30:00', 'Status' => 'scheduled']);

        $sameBatch = app(SessionEntitlementTransferService::class)->preview($source->ID, $source->ID, $sourceSession->id);
        $fullTarget = app(SessionEntitlementTransferService::class)->preview($source->ID, $target->ID, $sourceSession->id);

        $this->assertContains('來源與目標不可為同一批次', $sameBatch['errors']);
        $this->assertContains('目標批次已無可用堂數', $fullTarget['errors']);
    }

    public function test_preview_rejects_settlement_locked_source_and_target(): void
    {
        $student = Student::create(['name' => '結算鎖定測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = $this->course($student->id);
        $target = $this->course($student->id);
        $session = ClassSession::create(['StudentClassID' => $source->ID, 'SessionDate' => '2026-08-05', 'StartTime' => '19:30:00', 'EndTime' => '21:30:00', 'Status' => 'attended']);
        $source->setAttribute('settlement_locked_at', now())->save();
        $target->setAttribute('closed_reason', 'usage_settled')->save();

        $preview = app(SessionEntitlementTransferService::class)->preview($source->ID, $target->ID, $session->id);

        $this->assertContains('來源批次已完成用量結算，禁止轉移', $preview['errors']);
        $this->assertContains('目標批次已完成用量結算，禁止轉移', $preview['errors']);
    }

    public function test_rollback_restores_ownership_and_counters_without_deleting_audit(): void
    {
        $student = Student::create(['name' => '回滾測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = $this->course($student->id, ['SessionCount' => 8, 'UsedSessions' => 1, 'RemainingSessions' => 7]);
        $target = $this->course($student->id, ['SessionCount' => 8, 'UsedSessions' => 0, 'RemainingSessions' => 8]);
        $session = ClassSession::create([
            'StudentClassID' => $source->ID,
            'SessionDate' => '2026-08-05',
            'StartTime' => '19:30:00',
            'EndTime' => '21:30:00',
            'Status' => 'attended',
        ]);
        SessionDeductionLedger::create(['student_class_id' => $source->ID, 'class_session_id' => $session->id, 'event_type' => 'deduct', 'source' => 'attendance']);

        $transfer = app(SessionEntitlementTransferService::class)->transfer($source->ID, $target->ID, $session->id, 'test', 'P0-TEST-ROLLBACK');
        app(SessionEntitlementTransferService::class)->rollback($transfer['transfer']->id);

        $this->assertSame($source->ID, (int) $session->refresh()->StudentClassID);
        $this->assertNotNull($transfer['transfer']->fresh()->rolled_back_at);
        $this->assertDatabaseHas('session_entitlement_transfers', ['id' => $transfer['transfer']->id]);
        $this->assertTrue(app(SessionEntitlementTransferService::class)->verify($transfer['transfer']->id)['ok']);
    }

    private function course(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-20',
            'TotalHours' => 16,
            'Charge' => 12000,
            'Paid' => 0,
            'Rate' => 1500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_two',
        ], $overrides));
    }
}
