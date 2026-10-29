<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\LearningRecordAuthorSnapshot;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** #3824 part 2: teacher change on a filled-but-unapproved assessment snapshots + clears; approved stays locked. Synthetic data. */
class AssessmentAuthorSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-04-10';
    private const BODY = '原作者填寫的評量正文';

    private string $token;
    private string $teacherToken;
    private int $aId;
    private int $bId;
    private StudentClass $sc;
    private StudentClass $scB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false]]);
        $mk = function (string $name, string $type, int $admin, int $campus = 1) {
            $u = User::create(['LoginName' => "{$name}@snap3824.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
            UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $dir = $mk('dirsnap', 'A', 1);
        $this->aId = (int) $mk('teachera', 'T', 0)->id;
        $tb = $mk('teacherb', 'T', 0);
        $this->bId = (int) $tb->id;
        $this->token = $this->tok($dir);
        $this->teacherToken = $this->tok($tb);
        $stu = Student::create(['name' => 'X student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $contract = fn (int $t) => StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $t, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
        $this->sc = $contract($this->aId);
        $this->scB = $contract($this->bId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_patch_teacher_single_snapshots_and_clears(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'reason' => 'r'])
            ->assertOk()->assertJsonPath('author_snapshot_count', 1);
        $this->assertSnapshotted($lr, $this->bId);
    }

    public function test_patch_teacher_series_snapshots_and_clears(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'update_class' => true])->assertOk();
        $this->assertSnapshotted($lr, $this->bId);
    }

    public function test_substitute_snapshots_and_clears(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $this->assertSnapshotted($lr, $this->bId);
    }

    public function test_restore_regular_teacher_snapshots_the_substitutes_body(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $lr->update(['Content' => '代課老師的正文']);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->aId])->assertOk();
        $this->assertSame(2, LearningRecordAuthorSnapshot::where('learning_record_id', $lr->id)->count());
        $this->assertSame('代課老師的正文', LearningRecordAuthorSnapshot::where('learning_record_id', $lr->id)->orderByDesc('id')->first()->payload['Content']);
        $this->assertSame('', $lr->fresh()->Content);
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    public function test_undo_snapshots_the_substitutes_body(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $lr->update(['Content' => '代課老師的正文']);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute/undo")->assertOk();
        $this->assertSame(2, LearningRecordAuthorSnapshot::where('learning_record_id', $lr->id)->count());
        $this->assertSame('', $lr->fresh()->Content);
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    public function test_batch_substitute_snapshots_and_clears(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', [
            'assignments' => [['class_session_id' => $s->id, 'substitute_teacher_id' => $this->bId]],
        ])->assertSuccessful();
        $this->assertSnapshotted($lr, $this->bId);
    }

    public function test_reassign_contract_snapshots_and_follows_new_contract_teacher(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/reassign-contract", ['new_student_class_id' => $this->scB->ID, 'reason' => 'synthetic'])->assertOk();
        $this->assertSnapshotted($lr, $this->bId);
        $this->assertSame((int) $this->scB->ID, (int) $lr->fresh()->StudentClassID);
    }

    public function test_teacher_comment_is_copied_into_the_snapshot_and_removed_from_the_live_row(): void
    {
        [$s, $lr] = $this->filled();
        DB::table('learning_record_teacher_comments')->insert([
            'learning_record_id' => $lr->id, 'student_id' => $this->sc->StudentID, 'student_class_id' => $this->sc->ID, 'class_session_id' => $s->id,
            'teacher_id' => $this->aId, 'campus_id' => 1, 'author_user_id' => $this->aId, 'content' => '給家長的話', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId])->assertOk();
        $this->assertSame('給家長的話', LearningRecordAuthorSnapshot::first()->payload['teacher_comment']['content']);
        $this->assertSame(0, DB::table('learning_record_teacher_comments')->where('learning_record_id', $lr->id)->count());
    }

    public function test_no_body_means_no_snapshot_and_a_repeat_does_not_duplicate(): void
    {
        $s = $this->mkSession();
        $blank = LearningRecord::create(['StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id, 'TeacherID' => $this->aId, 'Subject' => 'Math',
            'Content' => '', 'Status' => 'pending', 'SessionDate' => self::DATE, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00']);
        $this->api()->patchJson("/api/v1/learning-records/{$blank->id}/teacher", ['TeacherID' => $this->bId])->assertOk();
        $this->assertSame(0, LearningRecordAuthorSnapshot::count());

        [$s2, $lr] = $this->filled('2026-04-11');
        $this->api()->postJson("/api/v1/class-sessions/{$s2->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $this->api()->postJson("/api/v1/class-sessions/{$s2->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $this->assertSame(1, LearningRecordAuthorSnapshot::where('learning_record_id', $lr->id)->count());
    }

    public function test_approved_assessment_stays_locked_and_unchanged(): void
    {
        [$s, $lr] = $this->filled();
        $lr->update(['Status' => 'approved', 'ApprovedBy' => 1, 'ApprovedAt' => now()]);
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId])->assertStatus(409);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertStatus(409);
        $this->assertSame(0, LearningRecordAuthorSnapshot::count());
        $this->assertSame(self::BODY, $lr->fresh()->Content);
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    public function test_only_director_can_read_snapshots_and_they_are_read_only(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId])->assertOk();
        $url = "/api/v1/learning-records/{$lr->id}/author-snapshots";

        $this->api()->getJson($url)->assertOk()->assertJsonPath('0.payload.Content', self::BODY)->assertJsonPath('0.author_teacher_id', $this->aId);
        $this->withHeaders(['Authorization' => "Bearer {$this->teacherToken}", 'Accept' => 'application/json'])->getJson($url)->assertStatus(403); // new teacher
        $this->getJson($url)->assertStatus(403);
        $this->api()->postJson($url, [])->assertStatus(405);
        $this->api()->deleteJson($url)->assertStatus(405);

        $this->expectException(\LogicException::class);
        LearningRecordAuthorSnapshot::first()->update(['reason' => 'tamper']);
    }

    public function test_foreign_campus_director_cannot_read_snapshots(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId])->assertOk();
        $other = User::create(['LoginName' => 'dir2@snap3824.example.com', 'Name' => 'dir2', 'PSW' => 'x', 'type' => 'A',
            'phone' => '0911111111', 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
        UserCampus::create(['CampusID' => 2, 'UserID' => $other->id, 'Admin' => 1, 'Approved' => 1]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tok($other), 'Accept' => 'application/json'])
            ->getJson("/api/v1/learning-records/{$lr->id}/author-snapshots")->assertStatus(403);
    }

    public function test_migration_is_create_only_and_rolls_back_by_dropping_the_table(): void
    {
        $this->assertTrue(Schema::hasTable('learning_record_author_snapshots'));
        $m = require database_path('migrations/2026_10_29_150000_create_learning_record_author_snapshots_table.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('learning_record_author_snapshots'));
        $this->assertTrue(Schema::hasTable('LearningRecord'));
        $m->up();
        $this->assertTrue(Schema::hasTable('learning_record_author_snapshots'));
        DB::connection()->getPdo()->beginTransaction(); // MySQL DDL committed the test transaction; reopen so teardown rolls back cleanly
    }

    private function assertSnapshotted(LearningRecord $lr, int $newTeacherId): void
    {
        $snap = LearningRecordAuthorSnapshot::where('learning_record_id', $lr->id)->first();
        $this->assertNotNull($snap);
        $this->assertSame($this->aId, (int) $snap->author_teacher_id);
        $this->assertSame('pending', $snap->status_at_snapshot);
        $this->assertSame(self::BODY, $snap->payload['Content']);
        $this->assertSame('第三章', $snap->payload['Progress']);
        $fresh = $lr->fresh();
        $this->assertSame('', $fresh->Content);
        $this->assertNull($fresh->Progress);
        $this->assertSame('pending', $fresh->Status);
        $this->assertSame($newTeacherId, (int) $fresh->TeacherID);
        $this->assertSame(0, (int) User::find($this->aId)->TeachingSessionCount);
    }

    /** @return array{0: ClassSession, 1: LearningRecord} filled but unapproved, by teacher A */
    private function filled(string $date = self::DATE): array
    {
        $s = $this->mkSession($date);
        $lr = LearningRecord::create(['StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id, 'TeacherID' => $this->aId, 'Subject' => 'Math',
            'Content' => self::BODY, 'Progress' => '第三章', 'Status' => 'pending', 'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'SessionDeducted' => true]);

        return [$s, $lr];
    }

    private function mkSession(string $date = self::DATE): ClassSession
    {
        $s = ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'attended']);
        StudentSignIn::create([
            'StudentClassID' => $this->sc->ID, 'StudentID' => $this->sc->StudentID, 'TeacherID' => $this->aId, 'RecordedByUserID' => $this->aId,
            'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => "{$date} 16:05:00", 'MDT' => now(),
            'ClassSessionID' => $s->id, 'Status' => 'present', 'SessionDeducted' => 1,
        ]);

        return $s;
    }

    private function tok(User $u): string
    {
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

        return $t;
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }
}
