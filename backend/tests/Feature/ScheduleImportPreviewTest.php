<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #770 批次排課 CSV 匯入 — 衝突檢查 preview。
 */
class ScheduleImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private int $teacherId;

    private function director(int $campus = 1): string
    {
        $u = User::create(['LoginName' => 'dir-imp@example.com', 'Name' => 'Dir', 'PSW' => 'x', 'type' => 'A', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => 1, 'Approved' => 1]);
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);
        return $t;
    }

    private function seedExistingSession(int $teacherId, string $date, string $start, string $end, int $campus = 1): void
    {
        $s = Student::create(['name' => '既有生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subMonth(), 'TotalHours' => 20,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $date, 'StartTime' => $start . ':00',
            'EndTime' => $end . ':00', 'Status' => 'scheduled',
        ]);
    }

    /** @test 同老師同時段衝突；乾淨行 ok；同檔互衝；格式錯誤 */
    public function preview_flags_teacher_conflict_clean_intra_and_format(): void
    {
        $token = $this->director();
        $tid = (int) User::create(['LoginName' => 'imp-teach@example.com', 'Name' => '師', 'PSW' => 'x', 'type' => 'T', 'MustChangePassword' => false])->id;
        UserCampus::create(['CampusID' => 1, 'UserID' => $tid, 'Admin' => 0, 'Approved' => 1]);

        // 既有：老師 $tid 在 2026-09-01 14:00–16:00 已有課
        $this->seedExistingSession($tid, '2026-09-01', '14:00', '16:00');

        $csv = "date,start_time,end_time,teacher_id,room_id,subject,student_ids,note\n"
            . "2026-09-01,14:00,16:00,{$tid},101,數學,\"1,2\",與既有衝突\n"   // 衝突（DB 同老師時段）
            . "2026-09-02,16:00,18:00,{$tid},102,英文,\"3\",乾淨\n"           // ok
            . "2026-09-03,10:00,12:00,{$tid},103,理化,\"4\",A\n"             // 與下一列同檔衝突
            . "2026-09-03,11:00,13:00,{$tid},103,理化,\"5\",B\n"             // 與上一列同老師同教室時段衝突
            . "BAD,9:99,xx,0,,,,\n";                                         // 格式錯誤

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/schedule-import/preview', ['csv' => $csv]);

        $res->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('rows.0.status', 'conflict')   // DB 衝突
            ->assertJsonPath('rows.1.status', 'ok')         // 乾淨
            ->assertJsonPath('rows.2.status', 'conflict')   // 同檔互衝
            ->assertJsonPath('rows.3.status', 'conflict')
            ->assertJsonPath('rows.4.status', 'conflict');  // 格式錯誤
    }

    /** @test 缺必要欄位 → 422 */
    public function preview_rejects_missing_header(): void
    {
        $token = $this->director();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/schedule-import/preview', ['csv' => "foo,bar\n1,2"])
            ->assertStatus(422);
    }
}
