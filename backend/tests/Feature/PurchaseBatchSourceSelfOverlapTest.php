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
use Tests\TestCase;

/**
 * in-app #422 (GitHub #4287): 加購 (purchase-batch) while the source course still holds pre-created future
 * lessons on its fixed slot was refused as a student overlap with "another" course — the course being extended.
 * The batch must continue after the source's own lessons; a slot held by a different course still blocks.
 */
class PurchaseBatchSourceSelfOverlapTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $base;

    protected function setUp(): void
    {
        parent::setUp();
        // A future Monday so the write-time student guard (which skips past dates) is active.
        $this->base = Carbon::today()->next(Carbon::MONDAY)->addWeek();
    }

    public function test_purchase_batch_skips_slots_the_source_course_still_holds(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        $sourceDates = $this->seedSourceFutureSessions($source, 3);

        $res = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
                'sessions' => 4,
                'start_date' => $this->base->toDateString(),
                'mode' => 'new_purchase',
            ]);

        $res->assertCreated()
            ->assertJsonPath('new_course.created_sessions', 4)
            ->assertJsonPath('new_course.first_session_date', $this->weeksAfterBase(3))
            ->assertJsonPath('new_course.last_session_date', $this->weeksAfterBase(6));

        $newId = (int) $res->json('new_course.id');
        $newDates = ClassSession::query()
            ->where('StudentClassID', $newId)
            ->orderBy('SessionDate')
            ->pluck('SessionDate')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();
        $this->assertSame(
            [$this->weeksAfterBase(3), $this->weeksAfterBase(4), $this->weeksAfterBase(5), $this->weeksAfterBase(6)],
            $newDates
        );
        $this->assertSame([], array_values(array_intersect($sourceDates, $newDates)), 'batch must not double-book the source lessons');
        // The contract starts on its first real lesson (a Monday slot), not on the requested date.
        $this->assertSame($this->weeksAfterBase(3), substr((string) StudentClass::query()->findOrFail($newId)->StartDate, 0, 10));

        // The source keeps every lesson it already had.
        $this->assertSame(3, ClassSession::query()
            ->where('StudentClassID', $source->ID)
            ->where('Status', 'scheduled')
            ->count());
    }

    public function test_batch_starts_after_the_source_final_lesson_even_with_a_gap(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        // Source lessons in weeks 0 and 2; week 1 is free but still before the source's last lesson.
        foreach ([0, 2] as $week) {
            ClassSession::create([
                'StudentClassID' => $source->ID,
                'SessionDate' => $this->weeksAfterBase($week),
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
            ]);
        }

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
                'sessions' => 2,
                'start_date' => $this->base->toDateString(),
                'mode' => 'new_purchase',
            ])
            ->assertCreated()
            ->assertJsonPath('new_course.first_session_date', $this->weeksAfterBase(3))
            ->assertJsonPath('new_course.last_session_date', $this->weeksAfterBase(4));
    }

    public function test_source_lessons_after_its_end_date_do_not_shift_the_batch(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        // The source contract ends in week 0; its week-1/2 rows are residue the overlap guard ignores.
        $source = $this->createSourceCourse($student->id, ['EndDate' => $this->weeksAfterBase(0)]);
        $this->seedSourceFutureSessions($source, 3);

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
                'mode' => 'purchase_batch',
                'sessions' => 2,
                'start_date' => $this->base->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('schedule.source_live_until', $this->weeksAfterBase(0))
            ->assertJsonPath('schedule.first_session_date', $this->weeksAfterBase(1));
    }

    public function test_retry_after_the_source_lessons_pass_is_still_a_duplicate(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        $this->seedSourceFutureSessions($source, 3);
        $payload = ['sessions' => 4, 'start_date' => $this->base->toDateString(), 'mode' => 'new_purchase'];

        $first = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", $payload)
            ->assertCreated();
        $firstId = (int) $first->json('new_course.id');
        $key = StudentClass::query()->findOrFail($firstId)->purchase_request_key;
        $this->assertIsString($key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);

        // The source lessons are now in the past, so today's anchor no longer shifts the request.
        AuthToken::query()->update(['expires_at' => now()->addMonths(3)]);
        $this->travelTo($this->base->copy()->addWeeks(2)->addDay());

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", $payload)
            ->assertStatus(409)
            ->assertJsonPath('duplicate_course.id', $firstId);
        $this->travelBack();
    }

    public function test_retry_after_the_source_cadence_is_edited_is_still_a_duplicate(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        $this->seedSourceFutureSessions($source, 2);
        $payload = ['sessions' => 3, 'start_date' => $this->base->toDateString(), 'mode' => 'new_purchase'];

        $first = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", $payload)
            ->assertCreated();

        // Moving the source from Monday 18:00 to Wednesday 19:00 changes every start the request could shift to.
        StudentClass::query()->whereKey($source->ID)->update(['week' => 3, 'time' => '19:00:00']);

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", $payload)
            ->assertStatus(409)
            ->assertJsonPath('duplicate_course.id', (int) $first->json('new_course.id'));
        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
                'mode' => 'purchase_batch', 'sessions' => 3, 'start_date' => $this->base->toDateString(),
            ])
            ->assertJsonFragment(['code' => 'possible_duplicate_batch']);
    }

    public function test_a_separate_later_batch_is_not_flagged_as_a_duplicate(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        $this->seedSourceFutureSessions($source, 2);
        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
                'sessions' => 3, 'start_date' => $this->base->toDateString(), 'mode' => 'new_purchase',
            ])
            ->assertCreated();

        // Same student, subject and size, but a different request (a later requested start).
        $later = ['sessions' => 3, 'start_date' => $this->weeksAfterBase(8)];
        $res = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", ['mode' => 'purchase_batch'] + $later)
            ->assertOk();
        $this->assertNotContains('possible_duplicate_batch', array_column($res->json('blockers') ?? [], 'code'));

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", ['mode' => 'new_purchase'] + $later)
            ->assertCreated()
            ->assertJsonPath('new_course.first_session_date', $this->weeksAfterBase(8));
    }

    public function test_a_legacy_batch_without_a_key_on_the_requested_start_is_still_a_duplicate(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        $this->seedSourceFutureSessions($source, 2);
        // Created before purchase_request_key existed: StartDate is the requested start, key is NULL.
        $legacy = $this->createSourceCourse($student->id, [
            'StartDate' => $this->base->toDateString(), 'SessionCount' => 3, 'Paid' => 0, 'UsedSessions' => 0,
        ]);
        $this->assertNull(StudentClass::query()->findOrFail($legacy->ID)->purchase_request_key);

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
                'sessions' => 3, 'start_date' => $this->base->toDateString(), 'mode' => 'new_purchase',
            ])
            ->assertStatus(409)
            ->assertJsonPath('duplicate_course.id', (int) $legacy->ID);
    }

    public function test_renewal_preview_shows_the_same_shifted_batch_dates(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        $this->seedSourceFutureSessions($source, 2);

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
                'mode' => 'purchase_batch',
                'sessions' => 3,
                'start_date' => $this->base->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('schedule.created_sessions', 3)
            ->assertJsonPath('schedule.first_session_date', $this->weeksAfterBase(2))
            ->assertJsonPath('schedule.last_session_date', $this->weeksAfterBase(4))
            ->assertJsonPath('schedule.source_live_until', $this->weeksAfterBase(1))
            ->assertJsonPath('proposed_course.start_date', $this->weeksAfterBase(2));
    }

    public function test_purchase_batch_still_rejects_a_slot_held_by_another_course(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $source = $this->createSourceCourse($student->id);
        $this->seedSourceFutureSessions($source, 2);

        // A different course of the same student owns the first slot after the source's lessons.
        $other = $this->createSourceCourse($student->id, ['SubjectID' => 2]);
        ClassSession::create([
            'StudentClassID' => $other->ID,
            'SessionDate' => $this->weeksAfterBase(2),
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
                'sessions' => 4,
                'start_date' => $this->base->toDateString(),
                'mode' => 'new_purchase',
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('code', 'student_slot_conflict')
            ->assertJsonPath('conflict_course_id', (int) $other->ID);

        // The refused purchase rolled back: only the two seeded courses exist.
        $this->assertSame(2, StudentClass::query()->where('StudentID', $student->id)->count());
    }

    private function weeksAfterBase(int $weeks): string
    {
        return $this->base->copy()->addWeeks($weeks)->toDateString();
    }

    /** @return array<int, string> */
    private function seedSourceFutureSessions(StudentClass $source, int $count): array
    {
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $this->weeksAfterBase($i);
            ClassSession::create([
                'StudentClassID' => $source->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
            ]);
            $dates[] = $date;
        }

        return $dates;
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '加購重疊測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createSourceCourse(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $this->base->copy()->subWeeks(8)->toDateString(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 5000,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 1,
            'Disconunt' => null,
            'Rate' => 500,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => 3,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 7,
            'week' => 1,
            'time' => '18:00:00',
        ], $overrides));
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'director-self-overlap@example.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
