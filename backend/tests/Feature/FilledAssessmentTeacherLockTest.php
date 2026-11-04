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
use Tests\TestCase;

/** in-app #499 (Founder 「也鎖死」): a teacher-filled assessment, approved or not, locks the lesson's teacher on every path. Synthetic data. */
class FilledAssessmentTeacherLockTest extends TestCase
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

    public function test_director_paths_are_refused_for_a_filled_unapproved_assessment_and_nothing_changes(): void
    {
        [$s, $lr] = $this->filled();
        $before = DB::table('LearningRecord')->where('id', $lr->id)->first();
        $this->assertLockedResponse($this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'reason' => 'r']));
        $this->assertLockedResponse($this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'update_class' => true]));
        $this->assertLockedResponse($this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId]));
        $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', [
            'assignments' => [['class_session_id' => $s->id, 'substitute_teacher_id' => $this->bId]],
        ])->assertStatus(422);
        $this->assertLockedResponse($this->api()->postJson("/api/v1/class-sessions/{$s->id}/reassign-contract", ['new_student_class_id' => $this->scB->ID, 'reason' => 'synthetic']));
        $this->assertEquals($before, DB::table('LearningRecord')->where('id', $lr->id)->first());
        $this->assertSame($this->aId, (int) $this->sc->fresh()->TeacherID);
    }

    public function test_undo_and_restore_are_refused_when_the_substitute_filled_the_assessment(): void
    {
        $s = $this->mkSession();
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        LearningRecord::create(['StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id, 'TeacherID' => $this->bId, 'Subject' => 'Math',
            'Content' => self::BODY, 'Progress' => '第三章', 'Status' => 'pending', 'SessionDate' => self::DATE, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00']);
        $this->assertLockedResponse($this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute/undo"));
        $this->assertLockedResponse($this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->aId]));
    }

    public function test_the_author_can_still_edit_their_own_record(): void
    {
        [$s, $lr] = $this->filled();
        $this->api()->putJson("/api/v1/learning-records/{$lr->id}", ['Content' => '作者自己改'])->assertOk();
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    public function test_voided_record_back_fill_and_missing_record_do_not_lock(): void
    {
        [$s, $lr] = $this->filled();
        $lr->update(['VoidedAt' => now()]);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();

        $s2 = $this->mkSession('2026-04-11');
        LearningRecord::create(['StudentClassID' => $s2->StudentClassID, 'ClassSessionID' => $s2->id, 'TeacherID' => $this->aId, 'Subject' => 'Math',
            'Content' => '（系統補登/扣除堂數）', 'Status' => 'approved', 'ApprovedAt' => now(), 'SessionDate' => '2026-04-11', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00']);
        $this->api()->postJson("/api/v1/class-sessions/{$s2->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();

        $s3 = $this->mkSession('2026-04-12');
        $this->api()->postJson("/api/v1/class-sessions/{$s3->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
    }

    public function test_a_blank_pending_row_and_a_future_lesson_can_be_changed(): void
    {
        $s = $this->mkSession();
        $blank = LearningRecord::create(['StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id, 'TeacherID' => $this->aId, 'Subject' => 'Math',
            'Content' => '', 'Status' => 'pending', 'SessionDate' => self::DATE, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00']);
        $this->api()->patchJson("/api/v1/learning-records/{$blank->id}/teacher", ['TeacherID' => $this->bId])->assertOk();
        $this->assertSame($this->bId, (int) $blank->fresh()->TeacherID);

        $future = ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled']);
        $this->api()->postJson("/api/v1/class-sessions/{$future->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
    }

    public function test_a_director_comment_on_a_blank_record_does_not_lock(): void
    {
        $s = $this->mkSession();
        $lr = LearningRecord::create(['StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id, 'TeacherID' => $this->aId, 'Subject' => 'Math',
            'Content' => '', 'Status' => 'pending', 'SessionDate' => self::DATE, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00']);
        DB::table('learning_record_teacher_comments')->insert([
            'learning_record_id' => $lr->id, 'student_id' => $this->sc->StudentID, 'student_class_id' => $this->sc->ID, 'class_session_id' => $s->id,
            'teacher_id' => $this->aId, 'campus_id' => 1, 'author_user_id' => $this->aId, 'content' => 'note', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId])->assertOk();
    }

    public function test_undo_is_allowed_when_it_restores_the_assessment_author(): void
    {
        [$s, $lr] = $this->filled();
        $n = \App\Models\Notification::create(['Title' => 't', 'SourceKey' => 'k', 'Type' => 'substitute', 'SourceType' => 'ClassSession', 'SourceID' => $s->id, 'Payload' => ['old_teacher_id' => $this->bId, 'new_teacher_id' => $this->aId, 'operator_id' => 0]]);
        $this->assertLockedResponse($this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute/undo", []));
        $n->update(['Payload' => ['old_teacher_id' => $this->aId, 'new_teacher_id' => $this->bId, 'operator_id' => 0]]);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute/undo", [])->assertStatus(200);
    }

    public function test_approved_assessment_stays_locked_and_must_be_rolled_back_then_deleted_to_correct(): void
    {
        [$s, $lr] = $this->filled();
        $lr->update(['Status' => 'approved', 'ApprovedBy' => 1, 'ApprovedAt' => now()]);
        $this->assertLockedResponse($this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId]));
        $this->api()->deleteJson("/api/v1/learning-records/{$lr->id}")->assertStatus(409);
        $this->api()->postJson("/api/v1/learning-records/{$lr->id}/rollback-approval", [])->assertOk();
        $this->assertLockedResponse($this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])); // un-approving alone does not unlock
        $this->api()->deleteJson("/api/v1/learning-records/{$lr->id}")->assertOk();
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
    }

    public function test_historical_snapshot_pin_still_counts_as_owner_evidence(): void
    {
        // The snapshot-and-clear flow is retired, but rows it wrote earlier keep pinning their new owner.
        $s = $this->mkSession();
        $lr = LearningRecord::create(['StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id, 'TeacherID' => $this->bId, 'Subject' => 'Math',
            'Content' => '', 'Status' => 'pending', 'SessionDate' => self::DATE, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00']);
        $s->update(['Status' => 'scheduled']);
        DB::table('StudentSingIn')->where('ClassSessionID', $s->id)->delete();
        LearningRecordAuthorSnapshot::create(['learning_record_id' => $lr->id, 'author_teacher_id' => $this->aId, 'new_teacher_id' => $this->bId,
            'status_at_snapshot' => 'pending', 'payload' => ['Content' => self::BODY], 'created_by' => 1, 'reason' => 'old']);
        $own = \App\Support\LearningRecordMutableOwnership::class;
        $this->assertFalse($own::canFollowCurrentCourseTeacher($lr->fresh()));
        $this->assertTrue($own::isActionableOwner($lr->fresh(), $this->bId));
    }

    public function test_director_reads_historical_snapshots_read_only_and_a_foreign_campus_cannot(): void
    {
        [$s, $lr] = $this->filled();
        LearningRecordAuthorSnapshot::create(['learning_record_id' => $lr->id, 'author_teacher_id' => $this->aId, 'new_teacher_id' => $this->bId,
            'status_at_snapshot' => 'pending', 'payload' => ['Content' => self::BODY], 'created_by' => 1, 'reason' => 'old']);
        $url = "/api/v1/learning-records/{$lr->id}/author-snapshots";
        $this->api()->getJson($url)->assertOk()->assertJsonPath('0.payload.Content', self::BODY)->assertJsonPath('0.author_teacher_id', $this->aId);
        $this->withHeaders(['Authorization' => "Bearer {$this->teacherToken}", 'Accept' => 'application/json'])->getJson($url)->assertStatus(403);
        $this->api()->postJson($url, [])->assertStatus(405);
        $other = User::create(['LoginName' => 'dir2@snap3824.example.com', 'Name' => 'dir2', 'PSW' => 'x', 'type' => 'A',
            'phone' => '0911111111', 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
        UserCampus::create(['CampusID' => 2, 'UserID' => $other->id, 'Admin' => 1, 'Approved' => 1]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tok($other), 'Accept' => 'application/json'])->getJson($url)->assertStatus(403);
        $this->expectException(\LogicException::class);
        LearningRecordAuthorSnapshot::first()->update(['reason' => 'tamper']);
    }

    public function test_update_reads_the_record_under_a_row_lock(): void
    {
        [$s, $lr] = $this->filled();
        $locked = 0;
        DB::listen(function ($q) use (&$locked) {
            if (preg_match('/from [`"]?LearningRecord[`"]?.* for update/i', $q->sql)) {
                $locked++;
            }
        });
        $this->api()->putJson("/api/v1/learning-records/{$lr->id}", ['Content' => '主任修改'])->assertOk()->assertJsonPath('Content', '主任修改');
        $this->assertGreaterThanOrEqual(1, $locked);
    }

    public function test_attachment_url_must_be_http_or_site_relative(): void
    {
        [$s, $lr] = $this->filled();
        $body = ['StudentClassID' => $this->sc->ID, 'TeacherID' => $this->aId, 'Content' => 'x', 'AttachmentUrl' => 'javascript:alert(1)', 'SessionDate' => self::DATE];
        $this->api()->putJson("/api/v1/learning-records/{$lr->id}", $body)->assertStatus(422)->assertJsonValidationErrors('AttachmentUrl');
    }


    private function assertLockedResponse(\Illuminate\Testing\TestResponse $r): void
    {
        $r->assertStatus(409)->assertJsonPath('code', 'approved_assessment_teacher_locked');
        $this->assertStringContainsString('已有', (string) $r->json('message'));
        $this->assertStringContainsString('刪除這筆評量', (string) $r->json('message'));
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
