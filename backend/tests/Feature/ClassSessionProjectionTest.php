<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PROJECTION API invariant tests. See docs/GUIDE_PROJECTION_INTEGRITY.md.
 */
class ClassSessionProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_DATE = '2026-06-27';

    public function test_projection_returns_full_dataset_without_pagination_meta(): void
    {
        [$campus, $token, $courseId] = $this->seedBranchCourse();
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => self::TARGET_DATE,
            'StartTime' => '10:00',
            'EndTime' => '12:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $res = $this->projectionJson($token, (int) $campus->id, self::TARGET_DATE, self::TARGET_DATE);
        $res->assertOk();
        $body = $res->json();
        $this->assertSame('projection', $body['api_kind'] ?? null);
        $this->assertSame('full', $body['completeness'] ?? null);
        $this->assertArrayNotHasKey('current_page', $body);
        $this->assertArrayNotHasKey('last_page', $body);
        $this->assertArrayNotHasKey('per_page', $body);
        $this->assertSame(1, (int) ($body['total'] ?? 0));
        $this->assertCount(1, $body['data'] ?? []);
    }

    public function test_projection_count_matches_db_for_branch_and_date_range(): void
    {
        [$campus, $token, $courseId] = $this->seedBranchCourse();
        foreach (['09:00', '11:00', '14:00', '16:00'] as $hm) {
            ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => self::TARGET_DATE,
                'StartTime' => $hm,
                'EndTime' => '18:00',
                'Status' => 'attended',
                'Note' => '',
            ]);
        }

        $dbCount = $this->countVisibleSessionsForBranch((int) $campus->id, self::TARGET_DATE, self::TARGET_DATE);

        $res = $this->projectionJson($token, (int) $campus->id, self::TARGET_DATE, self::TARGET_DATE);
        $res->assertOk();
        $this->assertSame($dbCount, (int) $res->json('total'));
        $this->assertCount($dbCount, $res->json('data') ?? []);
    }

    public function test_projection_returns_all_rows_when_dataset_exceeds_list_api_page_cap(): void
    {
        [$campus, $token, $courseId] = $this->seedBranchCourse();
        $sessionCount = 2005;
        $rows = [];
        $base = Carbon::parse('2026-06-01');
        for ($i = 0; $i < $sessionCount; $i++) {
            $rows[] = [
                'StudentClassID' => $courseId,
                'SessionDate' => $base->copy()->addDays($i % 20)->toDateString(),
                'StartTime' => sprintf('%02d:00', 8 + ($i % 10)),
                'EndTime' => '18:00',
                'Status' => 'scheduled',
                'Note' => '',
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ClassSession')->insert($chunk);
        }

        $rangeStart = '2026-06-01';
        $rangeEnd = '2026-06-20';

        $list = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?branch_id=' . $campus->id
                . '&start=' . $rangeStart . '&end=' . $rangeEnd . '&per_page=2000');
        $list->assertOk();
        $this->assertSame(2005, (int) $list->json('total'));
        $this->assertSame(2, (int) $list->json('last_page'));
        $this->assertCount(2000, $list->json('data') ?? []);

        $projection = $this->projectionJson($token, (int) $campus->id, $rangeStart, $rangeEnd);
        $projection->assertOk();
        $dbCount = $this->countVisibleSessionsForBranch((int) $campus->id, $rangeStart, $rangeEnd);
        $this->assertGreaterThan(2000, $dbCount);
        $this->assertSame($dbCount, (int) $projection->json('total'));
        $this->assertCount($dbCount, $projection->json('data') ?? []);
        $this->assertArrayNotHasKey('last_page', $projection->json());
    }

    public function test_projection_isolates_branches(): void
    {
        [$campusA, $tokenA, $courseA] = $this->seedBranchCourse('branch-a');
        [$campusB, $tokenB, $courseB] = $this->seedBranchCourse('branch-b');

        $sessionA = ClassSession::create([
            'StudentClassID' => $courseA,
            'SessionDate' => self::TARGET_DATE,
            'StartTime' => '10:00',
            'EndTime' => '12:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);
        $sessionB = ClassSession::create([
            'StudentClassID' => $courseB,
            'SessionDate' => self::TARGET_DATE,
            'StartTime' => '11:00',
            'EndTime' => '13:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $resA = $this->projectionJson($tokenA, (int) $campusA->id, self::TARGET_DATE, self::TARGET_DATE);
        $resA->assertOk();
        $idsA = collect($resA->json('data') ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $sessionA->id, $idsA);
        $this->assertNotContains((int) $sessionB->id, $idsA);

        $resB = $this->projectionJson($tokenB, (int) $campusB->id, self::TARGET_DATE, self::TARGET_DATE);
        $resB->assertOk();
        $idsB = collect($resB->json('data') ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $sessionB->id, $idsB);
        $this->assertNotContains((int) $sessionA->id, $idsB);
    }

    public function test_projection_requires_start_and_end(): void
    {
        [, $token, ] = $this->seedBranchCourse();
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions/projection?branch_id=1')
            ->assertStatus(422);
    }

    /** @return array{0:Campus,1:string,2:int} */
    private function seedBranchCourse(string $codeSuffix = 'xz'): array
    {
        $suffix = substr(uniqid(), -6);
        $campus = Campus::factory()->create(['name' => '測試分校', 'code' => $codeSuffix . $suffix]);
        $room = Room::create([
            'name' => '教室A',
            'campus_id' => $campus->id,
            'capacity' => 4,
            'is_active' => true,
        ]);
        $teacherId = $this->createTeacher((int) $campus->id);
        $student = Student::create([
            'name' => '學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'room_id' => $room->id,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'StartDate' => '2026-01-01',
            'by1' => $teacherId,
            'Period' => 4,
            'TotalHours' => 0,
        ]);
        $token = $this->createDirectorToken([(int) $campus->id]);

        return [$campus, $token, $courseId];
    }

    private function projectionJson(string $token, int $branchId, string $start, string $end)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions/projection?branch_id=' . $branchId
            . '&start=' . $start . '&end=' . $end);
    }

    private function countVisibleSessionsForBranch(int $branchId, string $start, string $end): int
    {
        return (int) DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('rooms as cs_branch_room', 'cs_branch_room.id', '=', 'sc.room_id')
            ->where('cs.SessionDate', '>=', $start)
            ->where('cs.SessionDate', '<=', $end)
            ->where(function ($q) use ($branchId) {
                $q->where(function ($inner) use ($branchId) {
                    $inner->whereNotNull('sc.room_id')
                        ->where('cs_branch_room.campus_id', $branchId);
                })->orWhere(function ($inner) use ($branchId) {
                    $inner->whereNull('sc.room_id')
                        ->where('s.CampusID', $branchId);
                });
            })
            ->where(function ($q) {
                $q->where('cs.Status', '<>', 'cancelled')
                    ->orWhere(function ($inner) {
                        $inner->where('cs.Note', 'NOT LIKE', '%cancelled-duplicate-reschedule-placeholder%')
                            ->orWhereNull('cs.Note');
                    });
            })
            ->count();
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-proj-' . uniqid() . '@test.com',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911111111',
            'MustChangePassword' => false,
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
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createTeacher(int $campusId): int
    {
        $teacher = User::create([
            'LoginName' => 'teacher-proj-' . uniqid() . '@test.com',
            'Name' => '老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922111111',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }
}
