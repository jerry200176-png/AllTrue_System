<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Models\Student;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-007 ~ FR-013 (刪除 / Void 出缺勤記錄)
 * DELETE /api/v1/attendance/{id}
 *
 * 防再犯紀錄 §TEST-001 ─ StudentSingIn NOT NULL 欄位：StudentID, SignInDT。
 */
class AttendanceVoidTest extends TestCase
{
    use RefreshDatabase;

    // ── AC-1: 主任成功 void，VoidedAt 被寫入 ──────────────────────────────────

    public function test_director_can_void_attendance_record(): void
    {
        [$token, $student] = $this->scaffold();

        $signin = StudentSignIn::create([
            'StudentID'       => $student->id,
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", [
            'void_reason' => '測試資料，請刪除',
        ]);

        $res->assertOk()->assertJson(['ok' => true, 'voided_id' => $signin->id]);

        // 直接查 DB 確認 VoidedAt 被寫入（不走 Model scope）
        $voidedAt = \Illuminate\Support\Facades\DB::table('StudentSingIn')
            ->where('id', $signin->id)
            ->value('VoidedAt');
        $this->assertNotNull($voidedAt, 'VoidedAt 應被寫入');

        // 再次查詢時應消失（active scope 過濾掉 VoidedAt != null）
        $this->assertNull(StudentSignIn::whereNull('VoidedAt')->find($signin->id));
    }

    // ── AC-2: 缺少 void_reason → 422 ─────────────────────────────────────────

    public function test_void_requires_reason(): void
    {
        [$token, $student] = $this->scaffold();

        $signin = StudentSignIn::create([
            'StudentID'  => $student->id,
            'SignInDT'   => now()->toDateTimeString(),
            'Status'     => 'present',
            'CampusID'   => 1,
            'PersonType' => 'student',
            'MDT'        => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '']);

        $res->assertUnprocessable();
    }

    // ── AC-3: 刪除不存在 or 已 void 的記錄 → 404 ─────────────────────────────

    public function test_void_nonexistent_record_returns_404(): void
    {
        [$token] = $this->scaffold();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson('/api/v1/attendance/999999', ['void_reason' => '測試刪除不存在記錄']);

        $res->assertNotFound();
    }

    // ── AC-4: 一般老師不能刪除 ────────────────────────────────────────────────

    public function test_teacher_cannot_void(): void
    {
        [$dirToken, $student] = $this->scaffold();
        $teacherToken         = $this->createTeacherToken();

        $signin = StudentSignIn::create([
            'StudentID'  => $student->id,
            'SignInDT'   => now()->toDateTimeString(),
            'Status'     => 'present',
            'CampusID'   => 1,
            'PersonType' => 'student',
            'MDT'        => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '老師嘗試刪除']);

        $res->assertStatus(403);
        $this->assertNull(StudentSignIn::whereNull('VoidedAt')->find($signin->id)?->VoidedAt);
    }

    // ── AC-5: void 已 voided 記錄 → 404（active scope 找不到） ──────────────

    public function test_void_already_voided_record_returns_404(): void
    {
        [$token, $student] = $this->scaffold();

        $signin = StudentSignIn::create([
            'StudentID'       => $student->id,
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'MDT'             => now(),
            'VoidedAt'        => now(),
            'VoidedByUserID'  => 1,
            'VoidReason'      => '已刪除',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '重複刪除測試']);

        $res->assertNotFound();
    }

    // ── in-app #512: voiding the sign-in that debited a 'completed' lesson hands it back to 待補點名 as scheduled ──

    public function test_void_reverts_completed_session_when_this_sign_in_debited_it(): void
    {
        [$token, $student] = $this->scaffold();
        [$session, $signin] = $this->completedSessionWithSignIn($student, true);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '誤登'])
            ->assertOk()->assertJson(['class_session_reverted' => true]);

        $this->assertSame('scheduled', $session->fresh()->Status);
        $this->assertSame(0, (int) StudentClass::find($session->StudentClassID)->UsedSessions);
    }

    public function test_void_of_completed_lesson_voids_its_pending_and_approved_learning_records(): void
    {
        [$token, $student] = $this->scaffold();
        foreach (['pending', 'approved'] as $status) {
            [$session, $signin] = $this->completedSessionWithSignIn($student, true);
            $lr = \App\Models\LearningRecord::create([
                'StudentClassID' => $session->StudentClassID, 'ClassSessionID' => $session->id, 'TeacherID' => 99, 'Content' => '',
                'Subject' => 'x', 'SessionDate' => '2026-11-01', 'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => $status,
            ]);

            $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '誤登'])->assertOk();

            $this->assertNotNull($lr->fresh()->VoidedAt, "{$status} learning record is voided with the lesson");
            $this->assertSame(\App\Services\LearningRecordResurrectionPolicy::VOID_REASON_STATUS_ADJUST, $lr->fresh()->VoidReason);

            // StudentSingIn.ClassSessionID is unique even for voided rows (existing limit, not this change): free it so the re-mark can insert.
            \Illuminate\Support\Facades\DB::table('StudentSingIn')->where('id', $signin->id)->update(['ClassSessionID' => null]);
            // Re-marking 到班 must work and bring the evaluation back (a director's free text would 500/409 here).
            $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->postJson('/api/v1/attendance', [
                    'StudentID' => $student->id, 'StudentClassID' => $session->StudentClassID, 'ClassSessionID' => $session->id,
                    'TeacherID' => 99, 'Status' => 'present',
                ])->assertStatus(201);
            $this->assertNull($lr->fresh()->VoidedAt, "{$status} learning record is restored by the re-mark");
        }
    }

    public function test_void_keeps_completed_session_when_the_sign_in_debited_nothing(): void
    {
        [$token, $student] = $this->scaffold();
        [$session, $signin] = $this->completedSessionWithSignIn($student, false);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '誤登'])
            ->assertOk()->assertJson(['class_session_reverted' => false]);

        $this->assertSame('completed', $session->fresh()->Status);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function completedSessionWithSignIn(Student $student, bool $deducted): array
    {
        $class = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 1,
            'StartDate' => '2026-11-01', 'EndDate' => '2026-11-30', 'TotalHours' => 8, 'Charge' => 0, 'Paid' => 0, 'Rate' => 500,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionDuration' => 120, 'ClassType' => 'one_on_one',
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $class->ID, 'SessionDate' => '2026-11-01', 'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => 'completed',
        ]);
        $signin = StudentSignIn::create([
            'StudentID' => $student->id, 'StudentClassID' => $class->ID, 'ClassSessionID' => $session->id, 'SignInDT' => now()->toDateTimeString(),
            'Status' => 'present', 'CampusID' => 1, 'PersonType' => 'student', 'SessionDeducted' => $deducted, 'MDT' => now(),
        ]);

        if ($deducted) {
            \App\Services\SessionDeductionService::recomputeCounters((int) $class->ID);
            $this->assertSame(1, (int) $class->fresh()->UsedSessions, 'precondition: the completed lesson counts as used');
        }

        return [$session, $signin];
    }

    private function scaffold(): array
    {
        Campus::firstOrCreate(
            ['id' => 1],
            ['name' => '測試分校', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
             'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '',
             'TeachLIFFID' => '', 'TeachLIFF_URL' => '']
        );

        $user = User::create([
            'LoginName'          => 'dir-void-' . uniqid() . '@example.com',
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0900111000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name'         => '刪除測試生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);

        return [$raw, $student];
    }

    private function createTeacherToken(): string
    {
        $teacher = User::create([
            'LoginName'          => 'tch-void-' . uniqid() . '@example.com',
            'Name'               => '老師',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => '0911000002',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }
}
