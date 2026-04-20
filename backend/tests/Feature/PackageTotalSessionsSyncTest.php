<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRD — 方案共用課程堂數同步 (FR-001)
 *
 * PUT /api/v1/course-packages/{id} 支援 total_sessions 欄位：
 *  - 加購：所有成員 SessionCount 同步、排課自動延伸
 *  - 縮減：取消多餘未來 scheduled 排課；attended 不動
 *  - 冪等：相同值為 no-op
 *  - Guard：new < used → 422；月結方案 → 422
 *  - RISK-002：同步不得覆寫成員 Charge/Rate
 */
class PackageTotalSessionsSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createDirectorToken(array $campusIds = [1]): string
    {
        $user = User::create([
            'LoginName' => 'dir-sync-' . uniqid() . '@test.com',
            'Name'      => '主任',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 900000000,
        ]);

        foreach ($campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function makeStudent(int $campusId = 1): Student
    {
        return Student::create([
            'name'         => '同步生' . uniqid(),
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName' => 'teacher-' . uniqid() . '@test.com',
            'Name'      => '老師' . uniqid(),
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 911000000,
        ]);
    }

    /**
     * 建立一個「count」方案與指定科目數的成員課程（每科一個 StudentClass），
     * 並為每個成員預排 $total 堂 scheduled 排課（每週一次，週一下午）。
     */
    private function createPackageWithMembers(int $total, int $subjectCount, int $used = 0): array
    {
        $campusId = 1;
        $student = $this->makeStudent($campusId);

        $pkg = CoursePackage::create([
            'student_id'         => $student->id,
            'campus_id'          => $campusId,
            'name'               => "同步方案{$total}堂",
            'billing_mode'       => 'count',
            'total_sessions'     => $total,
            'remaining_sessions' => max(0, $total - $used),
            'used_sessions'      => $used,
            'rate'               => 500,
            'rate_unit'          => 'session',
            'class_type'         => 'one_on_one',
            'paid'               => false,
            'stop'               => false,
            'enabled'            => true,
        ]);

        $members = [];
        for ($i = 0; $i < $subjectCount; $i++) {
            $teacher = $this->makeTeacher();
            $subjectId = $i + 1;
            DB::table('Subject')->insertOrIgnore([
                ['id' => $subjectId, 'Subject_Name' => 'Subj' . $subjectId, 'School_id' => 1, 'Grade_no' => 1],
            ]);

            $sc = StudentClass::create([
                'StudentID'            => $student->id,
                'GradeID'              => 1,
                'SubjectID'            => $subjectId,
                'TeacherID'            => $teacher->id,
                'by1'                  => 1,
                'Period'               => 4,
                'StartDate'            => Carbon::today()->subWeeks($total + 2)->toDateString(),
                'TotalHours'           => 0,
                'RoomID'               => 0,
                'ScheduleMode'         => 'count',
                'SessionCount'         => $total,
                'RemainingSessions'    => max(0, $total - $used),
                'UsedSessions'         => $used,
                'SessionDuration'      => 120,
                'ClassType'            => 'one_on_one',
                'Rate'                 => 500,
                'rate_unit'            => 'session',
                'Charge'               => 500 * $total,
                'Pay'                  => 0,
                'Paid'                 => 0,
                'Stop'                 => 0,
                'PackageID'            => $pkg->id,
                'PackageTotalSessions' => $total,
                'PackageName'          => $pkg->name,
                // 排課 slot：週一 16:00，讓 extendSessionsIfNeeded 能延伸
                'week'                 => 1,
                'time'                 => '16:00',
            ]);

            // 預建 $total 筆排課，從 StartDate 起每週一一堂，狀態預設 scheduled
            $base = Carbon::parse($sc->StartDate);
            // 跳到第一個週一
            while ($base->dayOfWeekIso !== 1) { $base = $base->addDay(); }

            for ($k = 0; $k < $total; $k++) {
                $date = $base->copy()->addWeeks($k);
                $status = $k < $used ? 'attended' : 'scheduled';
                ClassSession::create([
                    'StudentClassID' => $sc->ID,
                    'SessionDate'    => $date->toDateString(),
                    'StartTime'      => '16:00',
                    'EndTime'        => '18:00',
                    'Status'         => $status,
                ]);
            }

            $members[] = $sc;
        }

        return [$pkg, $members, $student];
    }

    private function putPackage(string $token, int $pkgId, array $body)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson("/api/v1/course-packages/{$pkgId}", $body);
    }

    // ───────────────────────────────────────────────────────

    public function test_increase_total_sessions_extends_all_members(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkg, $members] = $this->createPackageWithMembers(4, 2, 0);

        $res = $this->putPackage($token, $pkg->id, ['total_sessions' => 8]);
        $res->assertOk();

        $pkg->refresh();
        $this->assertEquals(8, $pkg->total_sessions);

        foreach ($members as $m) {
            $m->refresh();
            $this->assertEquals(8, (int) $m->SessionCount, 'member SessionCount synced');
            $this->assertEquals(8, (int) $m->PackageTotalSessions, 'member PackageTotalSessions synced');

            $activeCount = ClassSession::where('StudentClassID', $m->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->count();
            $this->assertEquals(8, $activeCount, 'extended to new SessionCount');
        }
    }

    public function test_decrease_total_sessions_cancels_excess_scheduled(): void
    {
        $token = $this->createDirectorToken([1]);
        // 8 堂，前 2 堂 attended (used=2)
        [$pkg, $members] = $this->createPackageWithMembers(8, 1, 2);

        $res = $this->putPackage($token, $pkg->id, ['total_sessions' => 5]);
        $res->assertOk();

        $m = $members[0];
        $attended = ClassSession::where('StudentClassID', $m->ID)
            ->where('Status', 'attended')->count();
        $scheduled = ClassSession::where('StudentClassID', $m->ID)
            ->where('Status', 'scheduled')->count();
        $cancelled = ClassSession::where('StudentClassID', $m->ID)
            ->where('Status', 'cancelled')->count();

        $this->assertEquals(2, $attended, 'attended sessions preserved');
        $this->assertEquals(3, $scheduled, 'scheduled remain = newTotal - attended');
        $this->assertEquals(3, $cancelled, 'excess 3 scheduled cancelled');
    }

    public function test_same_value_is_noop(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkg, $members] = $this->createPackageWithMembers(6, 2, 0);

        $before = [];
        foreach ($members as $m) {
            $before[$m->ID] = ClassSession::where('StudentClassID', $m->ID)->count();
        }

        $res = $this->putPackage($token, $pkg->id, ['total_sessions' => 6]);
        $res->assertOk();

        foreach ($members as $m) {
            $after = ClassSession::where('StudentClassID', $m->ID)->count();
            $this->assertEquals($before[$m->ID], $after, 'no-op on same total_sessions');
        }
    }

    public function test_below_used_sessions_returns_422(): void
    {
        $token = $this->createDirectorToken([1]);
        // used=5
        [$pkg] = $this->createPackageWithMembers(8, 1, 5);

        $res = $this->putPackage($token, $pkg->id, ['total_sessions' => 3]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['message']);
    }

    public function test_three_subjects_all_extended(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkg, $members] = $this->createPackageWithMembers(4, 3, 0);

        $res = $this->putPackage($token, $pkg->id, ['total_sessions' => 7]);
        $res->assertOk();

        foreach ($members as $m) {
            $m->refresh();
            $this->assertEquals(7, (int) $m->SessionCount);
            $activeCount = ClassSession::where('StudentClassID', $m->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->count();
            $this->assertEquals(7, $activeCount, 'all three subjects extended');
        }
    }

    public function test_sync_does_not_alter_charge(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkg, $members] = $this->createPackageWithMembers(4, 2, 0);

        // 手動把其中一科 Charge 設為帶 preserved_delta 的特殊值
        $target = $members[0];
        $target->Charge = 99999; // 故意與 Rate*SessionCount 不一致
        $target->save();

        $res = $this->putPackage($token, $pkg->id, ['total_sessions' => 8]);
        $res->assertOk();

        $target->refresh();
        $this->assertEquals(99999, (int) $target->Charge,
            'RISK-002: sync must not overwrite member Charge (preserved_delta safety)');
    }

    public function test_monthly_package_returns_422(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkg] = $this->createPackageWithMembers(4, 1, 0);

        // 強制切到月結模式
        $pkg->billing_mode = 'date';
        $pkg->save();

        $res = $this->putPackage($token, $pkg->id, ['total_sessions' => 8]);
        $res->assertStatus(422);
    }
}
