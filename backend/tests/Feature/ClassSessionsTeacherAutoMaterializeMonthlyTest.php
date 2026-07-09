<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassSessionsTeacherAutoMaterializeMonthlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_index_materializes_missing_monthly_projected_session_for_same_day_query(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;

            $teacher = User::create([
                'LoginName' => 'teacher-materialize@example.com',
                'Name' => '自動補建老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => '0911222333',
                'MustChangePassword' => false,
            ]);
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $teacher->id,
                'Admin' => 0,
                'Approved' => 1,
            ]);
            $token = bin2hex(random_bytes(16));
            AuthToken::create([
                'user_id' => $teacher->id,
                'token' => $token,
                'expires_at' => now()->addDay(),
            ]);

            $student = Student::create([
                'name' => '名單補建學生',
                'CampusID' => $campusId,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => $teacher->id,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-05-01',
                'EndDate' => '2026-05-31',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 1,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'date',
                'SessionDuration' => 120,
                // 2026-05-08 is Friday (ISO 5)
                'week' => 5,
                'time' => '15:00:00',
            ]);

            $this->assertDatabaseMissing('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-05-08',
                'StartTime' => '15:00:00',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?start=2026-05-08&end=2026-05-08&per_page=100');

            $res->assertOk();
            $rows = collect($res->json('data'));
            $matched = $rows->first(fn ($r) => (int) ($r['student_class_id'] ?? 0) === (int) $course->ID);
            $this->assertNotNull($matched, 'Teacher same-day class-sessions list should include auto-materialized monthly slot');

            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-05-08',
                'StartTime' => '15:00:00',
                'Status' => 'scheduled',
                'Note' => 'projected-monthly-materialized-auto',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * #546/TD-018：自動補建的「抑制例外 + 既有堂次」查詢已批次化，
     * query 數不應隨老師當日課數線性成長（原本每堂 2 次 exists()）。
     * 也驗證重複請求不會建立重複堂次（批次語意與原 exists() 一致）。
     */
    public function test_auto_materialize_does_not_n_plus_one_on_teacher_class_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00', 'Asia/Taipei')); // Friday (ISO 5)
        try {
            $count2 = $this->materializeQueryCountForClasses(2);
            $count8 = $this->materializeQueryCountForClasses(8);

            // 只有「每堂一筆 insert + upsertSlot FOR UPDATE」應隨課數成長；存在性檢查已批次化。
            // Phase B 投影讀取為每課程固定 overhead；課數 +6 → 增量應遠小於 N+1 的 6*(2 selects + 1 insert)=18。
            $this->assertLessThanOrEqual(
                14,
                $count8 - $count2,
                "query 數隨課數線性成長（疑似 N+1）：2 課={$count2}, 8 課={$count8}"
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 建立 N 堂同為週五 15:00 的 monthly(date) 課程，呼叫一次 teacher class-sessions，
     * 回傳該請求的 DB query 總數；同時斷言每堂只被建立一次（無重複）。
     */
    private function materializeQueryCountForClasses(int $n): int
    {
        $campusId = 1;
        $teacher = User::create([
            'LoginName' => 'teacher-nplus1-' . uniqid() . '@example.com',
            'Name' => 'N+1 老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        for ($i = 0; $i < $n; $i++) {
            $student = Student::create([
                'name' => "N+1 學生{$i}", 'CampusID' => $campusId, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id,
                'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'EndDate' => '2026-05-31',
                'TotalHours' => 20, 'Charge' => 0, 'Paid' => 1, 'Rate' => 500,                 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionDuration' => 120,
                'week' => 5, 'time' => '15:00:00',
            ]);
        }

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders($headers)->getJson('/api/v1/class-sessions?start=2026-05-08&end=2026-05-08&per_page=200')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        // 第二次請求不應再建立任何重複堂次。
        $this->withHeaders($headers)->getJson('/api/v1/class-sessions?start=2026-05-08&end=2026-05-08&per_page=200')->assertOk();
        $dupes = ClassSession::where('SessionDate', '2026-05-08')
            ->where('StartTime', '15:00:00')
            ->where('StudentClassID', '>', 0)
            ->whereIn('StudentClassID', StudentClass::where('TeacherID', $teacher->id)->pluck('ID'))
            ->count();
        $this->assertSame($n, $dupes, '每堂課應只被自動補建一次（無重複）');

        return $queryCount;
    }
}

