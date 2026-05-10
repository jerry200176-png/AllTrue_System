<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningRecordOnlyStartedFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_started_shows_pending_after_class_start_before_end(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->makeUser('A', '主任');
        $teacher = $this->makeUser('T', '老師');
        UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
        $directorToken = $this->makeStaffToken($director, [$campus->id]);

        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $sc = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'TeacherID' => $teacher->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-01',
            'RoomID' => '',
            'TotalHours' => 20,
            'Charge' => 1000,
            'Pay' => 500,
            'Paid' => 0,
            'Rate' => 1000,
            'Stop' => 0,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'SessionCount' => 10,
            'SessionDuration' => 60,
            'ClassType' => 'one_on_one',
        ]);
        $sessionDate = '2026-05-10';
        $cs = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc,
            'SessionDate' => $sessionDate,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'attended',
        ]);
        $lr = DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id,
            'StudentClassID' => $sc,
            'ClassSessionID' => $cs,
            'TeacherID' => $teacher->id,
            'Subject' => 'English',
            'SessionDate' => $sessionDate,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Content' => '課堂內容',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-10 22:30:00'));
        try {
            $idsDue = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&status=pending&only_due=1&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertEmpty(collect($idsDue)->pluck('id')->filter(fn ($id) => (int) $id === (int) $lr)->all());

            $idsStarted = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&status=pending&only_started=1&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertEmpty(collect($idsStarted)->pluck('id')->filter(fn ($id) => (int) $id === (int) $lr)->all());
        } finally {
            Carbon::setTestNow();
        }

        Carbon::setTestNow(Carbon::parse('2026-05-10 23:15:00'));
        try {
            $idsDue = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&status=pending&only_due=1&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertEmpty(collect($idsDue)->pluck('id')->filter(fn ($id) => (int) $id === (int) $lr)->all());

            $idsStarted = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&status=pending&only_started=1&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertNotEmpty(collect($idsStarted)->firstWhere('id', $lr));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeUser(string $type, string $name): User
    {
        return User::create([
            'LoginName' => Str::random(8) . '@test.com',
            'Name' => $name,
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
    }

    private function makeStaffToken(User $user, array $campusIds): string
    {
        foreach ($campusIds as $id) {
            UserCampus::firstOrCreate(
                ['CampusID' => $id, 'UserID' => $user->id],
                ['Admin' => $user->type !== 'T', 'Approved' => 1]
            );
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
