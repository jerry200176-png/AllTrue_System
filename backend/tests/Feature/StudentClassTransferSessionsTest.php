<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\SessionDeductionLedger;
use App\Models\Schedule;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Interim workaround for in-app #1901/#1902/#1904: move already-materialized
// ClassSession rows (and their LearningRecord/StudentSignIn) to a different
// StudentClass so a teacher does not have to refill evaluations after a
// course split. Deliberately never touches SessionCount/Charge or billing
// fields; derived usage follows the existing ledger/counter rules.
class StudentClassTransferSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfers_session_and_carries_learning_record_and_signin(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-02');
        $this->createLearningRecord((int) $source->ID, $sessionId);
        $this->createSignIn((int) $source->ID, $sessionId);
        SessionDeductionLedger::create([
            'student_class_id' => $source->ID,
            'class_session_id' => $sessionId,
            'event_type' => 'deduct',
            'source' => 'attendance',
        ]);

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $this->assertSame(
            (int) $target->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
        $this->assertSame(
            (int) $target->ID,
            (int) LearningRecord::where('ClassSessionID', $sessionId)->value('StudentClassID')
        );
        $this->assertSame(
            (int) $target->ID,
            (int) StudentSignIn::where('ClassSessionID', $sessionId)->value('StudentClassID')
        );
        $this->assertSame(
            (int) $target->ID,
            (int) SessionDeductionLedger::query()->where('class_session_id', $sessionId)->value('student_class_id')
        );

        // Contract fields stay untouched while derived usage follows ownership.
        $source->refresh();
        $target->refresh();
        $this->assertSame(8, (int) $source->SessionCount);
        $this->assertSame(0, (int) $source->UsedSessions);
        $this->assertSame(1, (int) $target->UsedSessions);
    }

    // IDOR regression: authorizeStudentClassAccess() was only ever called on
    // the source course. A teacher who owns the source course could transfer
    // records into ANY other course for the same student, including one they
    // do not teach and have no write access to.
    public function test_rejects_when_caller_lacks_write_access_to_target_course(): void
    {
        $token = $this->createTeacherToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, $this->lastTeacherUserId);
        $target = $this->createCourse($student->id, 999999); // a different teacher's course
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(403);
        $this->assertSame(
            (int) $source->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_when_target_belongs_to_a_different_student(): void
    {
        $token = $this->createDirectorToken([1]);
        $studentA = $this->createStudent(1);
        $studentB = $this->createStudent(1);
        $source = $this->createCourse($studentA->id, 1);
        $target = $this->createCourse($studentB->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $source->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_session_id_not_in_source_course(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $otherCourse = $this->createCourse($student->id, 1);
        $foreignSessionId = $this->createClassSession((int) $otherCourse->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$foreignSessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $otherCourse->ID,
            (int) DB::table('ClassSession')->where('id', $foreignSessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_uncompleted_sessions_without_moving_them(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-02', 'scheduled');

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $source->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_target_with_different_subject(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1, ['SubjectID' => 2]);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $source->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_target_with_an_existing_active_slot_before_moving_anything(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sourceSessionId = $this->createClassSession((int) $source->ID, '2026-08-08');
        $targetSessionId = $this->createClassSession((int) $target->ID, '2026-08-08');

        $response = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sourceSessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(422)
            ->assertJsonPath('code', 'target_slot_conflict')
            ->assertJsonPath('conflicts.0.session_id', $targetSessionId);
        $this->assertSame(
            (int) $source->ID,
            (int) DB::table('ClassSession')->where('id', $sourceSessionId)->value('StudentClassID')
        );
    }

    public function test_replenishes_source_tail_for_active_count_mode_auto_recurrence(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent(1);
            $source = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-03',
                'week' => 1,
                'time' => '23:00',
                'SessionDuration' => 30,
                'SessionCount' => 3,
                'RemainingSessions' => 2,
                'Charge' => 3300,
                'Paid' => 1,
            ]);
            $target = $this->createCourse($student->id, 1);
            $this->createClassSession((int) $source->ID, '2026-08-03');
            $sessionId = $this->createClassSession((int) $source->ID, '2026-08-10');

            $response = $this->postJson(
                "/api/v1/student-classes/{$source->ID}/transfer-sessions",
                ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
                ['Authorization' => "Bearer {$token}"]
            );

            $response->assertOk()->assertJsonPath('replenished_source_session_count', 1);
            $this->assertSame(
                (int) $target->ID,
                (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
            );
            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $source->ID,
                'SessionDate' => '2026-08-03',
                'StartTime' => '23:00',
            ]);
            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $source->ID,
                'SessionDate' => '2026-08-17',
                'StartTime' => '23:00:00',
                'EndTime' => '23:30:00',
                'Status' => 'scheduled',
            ]);
            $this->assertDatabaseMissing('ClassSession', [
                'StudentClassID' => $source->ID,
                'SessionDate' => '2026-08-10',
            ]);

            $source->refresh();
            $this->assertSame(3, (int) $source->SessionCount);
            $this->assertSame(3300, (int) $source->Charge);
            $this->assertSame(1, (int) $source->Paid);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @dataProvider sourceScheduleModesThatMustNotBeReplenished
     */
    public function test_does_not_replenish_manual_date_or_stopped_sources(array $overrides): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent(1);
            $source = $this->createCourse($student->id, 1, array_merge([
                'StartDate' => '2026-08-03',
                'week' => 1,
                'time' => '23:00',
                'SessionDuration' => 30,
            ], $overrides));
            $target = $this->createCourse($student->id, 1);
            $sessionId = $this->createClassSession((int) $source->ID, '2026-08-10');

            $this->postJson(
                "/api/v1/student-classes/{$source->ID}/transfer-sessions",
                ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk()->assertJsonPath('replenished_source_session_count', 0);

            $this->assertDatabaseMissing('ClassSession', [
                'StudentClassID' => $source->ID,
                'SessionDate' => '2026-08-17',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function sourceScheduleModesThatMustNotBeReplenished(): array
    {
        return [
            'manual occurrence' => [['scheduling_policy' => 'manual_occurrence']],
            'date mode' => [['ScheduleMode' => 'date']],
            'stopped course' => [['Stop' => 1]],
        ];
    }

    public function test_replenishment_conflict_rolls_back_the_transfer(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent(1);
            $source = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-03',
                'week' => 1,
                'time' => '23:00',
                'SessionDuration' => 30,
            ]);
            $target = $this->createCourse($student->id, 1);
            $conflictCourse = $this->createCourse($student->id, 1);
            $sessionId = $this->createClassSession((int) $source->ID, '2026-08-10');
            $this->createClassSession((int) $conflictCourse->ID, '2026-08-17', 'scheduled');

            $this->postJson(
                "/api/v1/student-classes/{$source->ID}/transfer-sessions",
                ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
                ['Authorization' => "Bearer {$token}"]
            )->assertStatus(422)->assertJsonPath('code', 'student_slot_conflict');

            $this->assertSame(
                (int) $source->ID,
                (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
            );
            $this->assertDatabaseMissing('ClassSession', [
                'StudentClassID' => $source->ID,
                'SessionDate' => '2026-08-17',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_stale_schedule_exception_does_not_block_source_replenishment(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent(1);
            $source = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-03', 'week' => 1, 'time' => '23:00',
                'SessionDuration' => 30,
            ]);
            $target = $this->createCourse($student->id, 1);
            $conflictCourse = $this->createCourse($student->id, 2);
            $sessionId = $this->createClassSession((int) $source->ID, '2026-08-10');
            $this->createClassSession((int) $conflictCourse->ID, '2026-08-17', 'cancelled');
            $this->createSchedule((int) $conflictCourse->ID, $student->id, '2026-08-17', 'normal');

            $this->postJson(
                "/api/v1/student-classes/{$source->ID}/transfer-sessions",
                ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk()->assertJsonPath('replenished_source_session_count', 1);

            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $source->ID, 'SessionDate' => '2026-08-17',
                'StartTime' => '23:00:00', 'Status' => 'scheduled',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_schedule_before_target_contract_start_does_not_block_source_replenishment(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent(1);
            $source = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-03', 'week' => 1, 'time' => '23:00',
                'SessionDuration' => 30,
            ]);
            $target = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-18',
            ]);
            $sessionId = $this->createClassSession((int) $source->ID, '2026-08-10');

            // This schedule is outside the target contract's effective period.
            $this->createSchedule((int) $target->ID, $student->id, '2026-08-17', 'normal');

            $this->postJson(
                "/api/v1/student-classes/{$source->ID}/transfer-sessions",
                ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk()->assertJsonPath('replenished_source_session_count', 1);

            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $source->ID, 'SessionDate' => '2026-08-17',
                'StartTime' => '23:00:00', 'Status' => 'scheduled',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_active_session_before_target_contract_start_does_not_block_source_replenishment(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent(1);
            $source = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-03', 'week' => 1, 'time' => '23:00',
                'SessionDuration' => 30,
            ]);
            $target = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-18',
            ]);
            $sessionId = $this->createClassSession((int) $source->ID, '2026-08-10');

            // An old materialized row can be hidden from course details but is
            // still dangerous if the conflict query ignores contract dates.
            $this->createClassSession((int) $target->ID, '2026-08-17', 'scheduled');

            $this->postJson(
                "/api/v1/student-classes/{$source->ID}/transfer-sessions",
                ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk()->assertJsonPath('replenished_source_session_count', 1);

            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $source->ID, 'SessionDate' => '2026-08-17',
                'StartTime' => '23:00:00', 'Status' => 'scheduled',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_schedule_only_makeup_still_blocks_source_replenishment(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent(1);
            $source = $this->createCourse($student->id, 1, [
                'StartDate' => '2026-08-03', 'week' => 1, 'time' => '23:00',
                'SessionDuration' => 30,
            ]);
            $target = $this->createCourse($student->id, 1);
            $conflictCourse = $this->createCourse($student->id, 2);
            $sessionId = $this->createClassSession((int) $source->ID, '2026-08-10');
            $this->createSchedule((int) $conflictCourse->ID, $student->id, '2026-08-17', 'extra');

            $this->postJson(
                "/api/v1/student-classes/{$source->ID}/transfer-sessions",
                ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
                ['Authorization' => "Bearer {$token}"]
            )->assertStatus(422)->assertJsonPath('code', 'student_slot_conflict');

            $this->assertSame((int) $source->ID, (int) DB::table('ClassSession')
                ->where('id', $sessionId)->value('StudentClassID'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_recovers_evidence_backed_cancelled_session_and_transfers_all_artifacts(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-08', 'cancelled');

        DB::table('LearningRecord')->insert([
            'StudentClassID' => $source->ID,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'CreatedByUserID' => 1,
            'Content' => '取消前已填寫的評量',
            'Status' => 'pending',
            'VoidedAt' => now(),
            'VoidedByUserID' => 1,
            'VoidReason' => '由已上調整狀態',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('StudentSingIn')->insert([
            'StudentClassID' => $source->ID,
            'ClassSessionID' => $sessionId,
            'StudentID' => $student->id,
            'TeacherID' => 1,
            'Status' => 'present',
            'SessionDeducted' => 1,
            'SignInDT' => now(),
            'VoidedAt' => now(),
            'VoidedByUserID' => 1,
            'VoidReason' => '由已上調整狀態',
        ]);

        $response = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/recover-transfer-sessions",
            [
                'session_ids' => [$sessionId],
                'target_student_class_id' => $target->ID,
                'reason' => '原合約誤將已完成堂次標示為已取消，依歷史證據恢復移轉',
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertOk()
            ->assertJsonPath('recovered_session_ids.0', $sessionId)
            ->assertJsonPath('transferred_session_ids.0', $sessionId);
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $sessionId)->value('Status'));
        $this->assertSame((int) $target->ID, (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID'));
        $this->assertSame('取消前已填寫的評量', DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->value('Content'));
        $this->assertNull(DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->value('VoidedAt'));
        $this->assertNull(DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->value('VoidedAt'));
        $this->assertSame((int) $target->ID, (int) DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->value('StudentClassID'));
        $this->assertSame((int) $target->ID, (int) DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->value('StudentClassID'));

        $source->refresh();
        $target->refresh();
        $this->assertSame(0, (int) $source->UsedSessions);
        $this->assertSame(1, (int) $target->UsedSessions);
    }
    public function test_recovery_endpoint_rejects_cancelled_session_without_history(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-15', 'cancelled');

        $response = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/recover-transfer-sessions",
            [
                'session_ids' => [$sessionId],
                'target_student_class_id' => $target->ID,
                'reason' => '確認取消堂次資料',
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(422);
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $sessionId)->value('Status'));
        $this->assertSame((int) $source->ID, (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID'));
    }
    public function test_recovery_endpoint_requires_a_reason(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-22', 'cancelled');
        $this->createLearningRecord((int) $source->ID, $sessionId);

        $this->postJson(
            "/api/v1/student-classes/{$source->ID}/recover-transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(422);

        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $sessionId)->value('Status'));
    }
    public function test_recovery_endpoint_is_director_only(): void
    {
        $token = $this->createTeacherToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, $this->lastTeacherUserId);
        $target = $this->createCourse($student->id, $this->lastTeacherUserId);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-29', 'cancelled');
        $this->createLearningRecord((int) $source->ID, $sessionId);

        $this->postJson(
            "/api/v1/student-classes/{$source->ID}/recover-transfer-sessions",
            [
                'session_ids' => [$sessionId],
                'target_student_class_id' => $target->ID,
                'reason' => '主任授權恢復',
            ],
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(403);
    }
    public function test_recovery_target_slot_conflict_leaves_cancelled_evidence_untouched(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-09-05', 'cancelled');
        $this->createLearningRecord((int) $source->ID, $sessionId);
        $targetSessionId = $this->createClassSession((int) $target->ID, '2026-09-05', 'attended');

        $this->postJson(
            "/api/v1/student-classes/{$source->ID}/recover-transfer-sessions",
            [
                'session_ids' => [$sessionId],
                'target_student_class_id' => $target->ID,
                'reason' => '恢復歷史證據',
            ],
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(422)
            ->assertJsonPath('code', 'target_slot_conflict')
            ->assertJsonPath('conflicts.0.session_id', $targetSessionId);

        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $sessionId)->value('Status'));
        $this->assertSame((int) $source->ID, (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID'));
        $this->assertSame((int) $source->ID, (int) DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->value('StudentClassID'));
    }

    // ── helpers ──

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-transfersess-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private int $lastTeacherUserId = 0;

    private function createTeacherToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'teach-transfersess-' . uniqid() . '@test.com',
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912345679',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        }
        $this->lastTeacherUserId = (int) $user->id;
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '轉移測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createCourse(int $studentId, int $teacherId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 8800,
            'Paid' => 1,
            'Rate' => 1100,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createClassSession(int $courseId, string $date, string $status = 'attended'): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => $status,
        ]);
    }

    private function createSchedule(int $courseId, int $studentId, string $date, string $type): void
    {
        Schedule::create([
            'student_id' => $studentId, 'teacher_id' => 2, 'subject' => '數學',
            'day_of_week' => Carbon::parse($date)->dayOfWeekIso,
            'start_time' => '23:00', 'end_time' => '23:30', 'duration_hours' => 0.5,
            'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => $type,
            'deduction' => 1, 'branch_id' => 1, 'schedule_date' => $date,
            'student_course_id' => $courseId,
        ]);
    }

    private function createLearningRecord(int $courseId, int $sessionId): void
    {
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'CreatedByUserID' => 1,
            'Content' => '轉移測試評量內容',
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
