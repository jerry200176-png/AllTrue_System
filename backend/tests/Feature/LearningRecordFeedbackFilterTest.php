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

/**
 * #138/#139: the "家長回饋待看" CTA / feedback chip must surface records WITH parent feedback
 * regardless of the default time window or pagination page. Counts/lists were previously derived
 * from a single client page so older feedback (often on approved, back-dated records) was hidden.
 */
class LearningRecordFeedbackFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_filter_returns_old_feedback_records_and_excludes_others(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-30 12:00:00'));
        try {
            $campus = CampusFactory::new()->create();
            $director = $this->makeUser('A', '主任');
            $teacher = $this->makeUser('T', '老師');
            UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
            $directorToken = $this->makeStaffToken($director, [$campus->id]);

            $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
            $sc = $this->makeStudentClass($student->id, $teacher->id);

            // (1) OLD, approved record WITH unread parent feedback (the one a director cannot find).
            $oldLr = $this->makeLearningRecord($student->id, $sc, $teacher->id, '2026-01-05', 'approved');
            $this->makeFeedback($oldLr, $student->id, $sc, $teacher->id, $campus->id, readByDirector: false);

            // (2) RECENT record WITHOUT any feedback.
            $noFbLr = $this->makeLearningRecord($student->id, $sc, $teacher->id, '2026-05-20', 'approved');

            // feedback=unread: only the old feedback record, even though it is months old and approved.
            $unread = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&feedback=unread&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $unreadIds = collect($unread)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $this->assertContains((int) $oldLr, $unreadIds, 'unread feedback record must be returned regardless of age');
            $this->assertNotContains((int) $noFbLr, $unreadIds, 'records without feedback must be excluded');

            // feedback=has: same record is returned.
            $has = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&feedback=has&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertContains((int) $oldLr, collect($has)->pluck('id')->map(fn ($i) => (int) $i)->all());

            // After the director reads it, feedback=unread drops it but feedback=has still keeps it.
            DB::table('learning_record_feedbacks')
                ->where('learning_record_id', $oldLr)
                ->update(['last_read_by_director_at' => now()->addMinute()]);

            $unreadAfter = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&feedback=unread&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertNotContains((int) $oldLr, collect($unreadAfter)->pluck('id')->map(fn ($i) => (int) $i)->all());

            $hasAfter = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&feedback=has&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertContains((int) $oldLr, collect($hasAfter)->pluck('id')->map(fn ($i) => (int) $i)->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeStudentClass(int $studentId, int $teacherId): int
    {
        return DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-01-01',
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
    }

    private function makeLearningRecord(int $studentId, int $sc, int $teacherId, string $date, string $status): int
    {
        $cs = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc,
            'SessionDate' => $date,
            'StartTime' => '18:00',
            'EndTime' => '19:00',
            'Status' => 'attended',
        ]);

        return DB::table('LearningRecord')->insertGetId([
            'StudentID' => $studentId,
            'StudentClassID' => $sc,
            'ClassSessionID' => $cs,
            'TeacherID' => $teacherId,
            'Subject' => 'English',
            'SessionDate' => $date,
            'StartTime' => '18:00',
            'EndTime' => '19:00',
            'Content' => '課堂內容',
            'Status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeFeedback(int $lrId, int $studentId, int $sc, int $teacherId, int $campusId, bool $readByDirector): void
    {
        DB::table('learning_record_feedbacks')->insert([
            'learning_record_id' => $lrId,
            'student_id' => $studentId,
            'student_class_id' => $sc,
            'teacher_id' => $teacherId,
            'campus_id' => $campusId,
            'content' => '家長回饋內容',
            'last_read_by_director_at' => $readByDirector ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
