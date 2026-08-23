<?php

namespace Tests\Feature;

use App\Models\AuthToken;
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
