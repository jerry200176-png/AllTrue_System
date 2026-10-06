<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SubstituteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** TD-076 Track B PR-B1: OccurrenceAssignmentService behind schedule-occurrence-v2 (flag off = today's rows). */
class OccurrenceAssignmentFlagOnTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $aId;
    private int $bId;
    private StudentClass $sc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_substitute_after_cross_date_reschedule_reuses_the_live_row_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->crossDateShape();

        $this->postSubstitute($session)->assertOk();

        $live = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->get();
        $this->assertCount(1, $live, 'no second live row');
        $this->assertSame(9001, (int) $live[0]->id);
        $this->assertSame($this->bId, (int) $live[0]->teacher_id);
        $this->assertSame(9000, (int) $live[0]->original_schedule_id);
        $this->assertSame(1, Schedule::where('student_course_id', $this->sc->ID)->where('status', 'rescheduled')->count());
        $this->assertSame('2026-04-10', substr((string) $live[0]->original_schedule_date, 0, 10));

        $logs = ScheduleChangeLog::all();
        $this->assertCount(1, $logs);
        $this->assertSame('substitute', $logs[0]->reason);
        $this->assertSame([$this->aId, $this->bId], [(int) $logs[0]->from_teacher_id, (int) $logs[0]->to_teacher_id]);
        $this->assertSame(9001, (int) $logs[0]->schedule_id);
    }

    public function test_same_scenario_flag_off_keeps_todays_second_chain(): void
    {
        $this->seedWorld();
        $this->flag(false);
        $session = $this->crossDateShape();

        $this->postSubstitute($session)->assertOk();

        $live = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->get();
        $this->assertCount(1, $live);
        $this->assertNotSame(9001, (int) $live[0]->id, 'today replaces the live row with a new chain');
        $this->assertSame($this->bId, (int) $live[0]->teacher_id);
        $this->assertNull($live[0]->original_schedule_date);
        $this->assertSame(2, Schedule::where('student_course_id', $this->sc->ID)->where('status', 'rescheduled')->count());
        $this->assertSame(0, ScheduleChangeLog::count());
    }

    public function test_combined_substitute_and_reschedule_one_identity_row_one_log_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();

        $this->postSubstitute($session, ['new_date' => '2026-04-20', 'new_start_time' => '14:00', 'new_end_time' => '16:00'])->assertOk();

        $live = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->get();
        $this->assertCount(1, $live);
        $row = $live[0];
        $this->assertSame($this->bId, (int) $row->teacher_id);
        $this->assertSame(['2026-04-20', '14:00', '16:00'], [substr((string) $row->schedule_date, 0, 10), $row->start_time, $row->end_time]);
        $this->assertSame(['2026-04-19', '13:00'], [substr((string) $row->original_schedule_date, 0, 10), $row->original_start_time]);
        $anchor = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'rescheduled')->get();
        $this->assertCount(1, $anchor);
        $this->assertSame((int) $anchor[0]->id, (int) $row->original_schedule_id);
        $this->assertSame(['2026-04-20', '14:00'], [substr((string) $anchor[0]->schedule_date, 0, 10), $anchor[0]->start_time]);

        $log = ScheduleChangeLog::all();
        $this->assertCount(1, $log);
        $this->assertSame(['2026-04-19', '13:00', '2026-04-20', '14:00'], [
            substr((string) $log[0]->from_date, 0, 10), $log[0]->from_time, substr((string) $log[0]->to_date, 0, 10), $log[0]->to_time,
        ]);
        $this->assertSame([$this->aId, $this->bId], [(int) $log[0]->from_teacher_id, (int) $log[0]->to_teacher_id]);

        $session->refresh();
        $this->assertSame('2026-04-20', Carbon::parse($session->SessionDate)->toDateString());
        $this->assertSame($this->bId, (int) LearningRecord::where('ClassSessionID', $session->id)->value('TeacherID'));
        $this->assertSame(1, Notification::where('SourceType', 'ClassSession')->where('SourceID', $session->id)->count());
    }

    public function test_combined_flag_off_parity_has_no_identity_and_no_log(): void
    {
        $this->seedWorld();
        $this->flag(false);
        $session = $this->plainSession();

        $this->postSubstitute($session, ['new_date' => '2026-04-20', 'new_start_time' => '14:00', 'new_end_time' => '16:00'])->assertOk();

        $this->assertSame(1, Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->where('teacher_id', $this->bId)->where('schedule_date', '2026-04-20')->count());
        $this->assertSame(1, Schedule::where('student_course_id', $this->sc->ID)->where('status', 'rescheduled')->where('schedule_date', '2026-04-20')->count());
        $this->assertSame(0, Schedule::whereNotNull('original_schedule_date')->count());
        $this->assertSame(0, ScheduleChangeLog::count());
    }

    public function test_exception_inside_the_transaction_rolls_everything_back_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();
        $this->partialMock(SubstituteService::class, function ($mock) {
            $mock->shouldReceive('createParentNotification')->andThrow(new \RuntimeException('forced'));
        });

        $this->postSubstitute($session, ['new_date' => '2026-04-20', 'new_start_time' => '14:00', 'new_end_time' => '16:00'])->assertStatus(500);

        $session->refresh();
        $this->assertSame('2026-04-19', Carbon::parse($session->SessionDate)->toDateString());
        $this->assertSame($this->aId, (int) LearningRecord::where('ClassSessionID', $session->id)->value('TeacherID'));
        $this->assertSame(0, Schedule::where('student_course_id', $this->sc->ID)->count());
        $this->assertSame(0, ScheduleChangeLog::count());
    }

    public function test_undo_restores_slot_teacher_and_removes_substitute_only_rows_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();
        $this->postSubstitute($session, ['new_date' => '2026-04-20', 'new_start_time' => '14:00', 'new_end_time' => '16:00'])->assertOk();

        $this->postSession($session, 'substitute/undo')->assertOk()->assertJsonFragment(['restored_time' => true]);

        $session->refresh();
        $this->assertSame(['2026-04-19', '13:00'], [Carbon::parse($session->SessionDate)->toDateString(), substr((string) $session->StartTime, 0, 5)]);
        $this->assertSame($this->aId, (int) LearningRecord::where('ClassSessionID', $session->id)->value('TeacherID'));
        $this->assertSame(0, Schedule::where('student_course_id', $this->sc->ID)->count());
        $this->assertSame(['substitute', 'restore'], ScheduleChangeLog::orderBy('id')->pluck('reason')->all());
        $restore = ScheduleChangeLog::orderByDesc('id')->first();
        $this->assertSame([$this->bId, $this->aId], [(int) $restore->from_teacher_id, (int) $restore->to_teacher_id]);
    }

    public function test_undo_after_flag_rollback_still_restores_through_the_writer(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->crossDateShape();
        $this->postSubstitute($session)->assertOk();
        $this->flag(false); // rollback: undo must still go through the writer

        $this->postSession($session, 'substitute/undo')->assertOk();

        $this->assertSame($this->aId, (int) Schedule::findOrFail(9001)->teacher_id, 'cross-date row stays, teacher restored');
        $this->assertSame(['substitute', 'restore'], ScheduleChangeLog::orderBy('id')->pluck('reason')->all());
    }

    public function test_makeup_extra_row_is_never_taken_over_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();
        DB::table('schedules')->insert([
            'id' => 9100, 'student_id' => $this->sc->StudentID, 'teacher_id' => $this->aId, 'day_of_week' => 7,
            'type' => 'extra', 'status' => 'scheduled', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $this->sc->ID, 'schedule_date' => '2026-04-19',
            'start_time' => '13:00', 'end_time' => '15:00', 'original_schedule_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postSubstitute($session)->assertOk();
        $this->postSession($session, 'substitute/undo')->assertOk();
        $extra = Schedule::findOrFail(9100);
        $this->assertSame([$this->aId, null, 'extra'], [(int) $extra->teacher_id, $extra->original_schedule_id, $extra->type]);
    }

    public function test_leave_occurrence_is_refused_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();
        DB::table('schedules')->insert(['id' => 9200, 'student_id' => $this->sc->StudentID, 'day_of_week' => 7, 'type' => 'normal',
            'deduction' => 0, 'branch_id' => 1, 'student_course_id' => $this->sc->ID, 'teacher_id' => $this->aId, 'status' => 'leave',
            'schedule_date' => '2026-04-19', 'start_time' => '13:00', 'end_time' => '15:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->postSubstitute($session)->assertStatus(422);
        $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', ['assignments' => [['class_session_id' => $session->id, 'substitute_teacher_id' => $this->bId]]])->assertStatus(422);
        $this->assertSame([1, 0], [Schedule::count(), ScheduleChangeLog::count()]);
    }

    public function test_durable_restore_to_contract_teacher_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();
        $this->postSubstitute($session)->assertOk();

        $this->postSubstitute($session, [], $this->aId)->assertOk()->assertJsonFragment(['substitute_cleared' => true]);

        $this->assertSame(0, Schedule::where('student_course_id', $this->sc->ID)->count());
        $this->assertSame($this->aId, (int) LearningRecord::where('ClassSessionID', $session->id)->value('TeacherID'));
        $this->assertSame(['substitute', 'restore'], ScheduleChangeLog::orderBy('id')->pluck('reason')->all());
    }

    public function test_pending_leave_without_a_leave_row_is_refused_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();
        $session->Status = 'leave_requested';
        $session->save();
        $this->postSubstitute($session)->assertStatus(422);
        $this->assertSame([0, 0], [Schedule::count(), ScheduleChangeLog::count()]);
    }

    public function test_pure_substitution_syncs_live_row_end_time_to_the_session_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->crossDateShape();
        $session->EndTime = '16:00';
        $session->save();

        $this->postSubstitute($session)->assertOk();

        $live = Schedule::findOrFail(9001);
        $this->assertSame($this->bId, (int) $live->teacher_id);
        $this->assertSame('16:00', substr((string) $live->end_time, 0, 5));
        $this->assertEquals(3.0, (float) $live->duration_hours);
    }

    public function test_batch_substitute_one_live_row_and_one_log_per_lesson_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $s1 = $this->plainSession();
        $s2 = ClassSession::create([
            'StudentClassID' => $this->sc->ID, 'SessionDate' => '2026-04-20',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled',
        ]);

        $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', [
                'assignments' => [
                    ['class_session_id' => $s1->id, 'substitute_teacher_id' => $this->bId],
                    ['class_session_id' => $s2->id, 'substitute_teacher_id' => $this->bId],
                ],
                'atomic' => true,
            ])->assertOk()->assertJsonFragment(['success' => 2, 'fail' => 0]);

        $this->assertSame(2, Schedule::where('status', 'scheduled')->where('teacher_id', $this->bId)->whereNotNull('original_schedule_id')->count());
        $this->assertSame(2, ScheduleChangeLog::where('reason', 'substitute')->count());
    }

    public function test_substitute_on_a_makeup_session_writes_no_second_live_row_flag_on(): void
    {
        $this->seedWorld();
        $this->flag(true);
        $session = $this->plainSession();
        $this->insertMakeupRow();

        $this->postSubstitute($session)->assertOk();

        $this->assertSame([1, 0], [Schedule::where('student_course_id', $this->sc->ID)->count(), ScheduleChangeLog::count()], 'only the makeup row');
        $this->assertSame($this->bId, (int) LearningRecord::where('ClassSessionID', $session->id)->value('TeacherID'));
        $this->postSubstitute($session, ['new_date' => '2026-04-20', 'new_start_time' => '14:00', 'new_end_time' => '16:00'], $this->bId)->assertStatus(422);
        $this->assertSame(1, Schedule::where('student_course_id', $this->sc->ID)->count());
    }

    public function test_substitute_on_a_makeup_session_flag_off_parity_keeps_todays_rows(): void
    {
        $this->seedWorld();
        $this->flag(false);
        $session = $this->plainSession();
        $this->insertMakeupRow();

        $this->postSubstitute($session)->assertOk();

        $this->assertSame(1, Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->where('teacher_id', $this->bId)->count());
        $this->assertSame(0, ScheduleChangeLog::count());
    }

    public function test_sign_in_only_leave_is_refused_flag_on_and_unchanged_flag_off(): void
    {
        $this->seedWorld();
        $session = $this->plainSession();
        StudentSignIn::create([
            'StudentClassID' => $this->sc->ID, 'StudentID' => $this->sc->StudentID, 'TeacherID' => $this->aId,
            'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-19 13:00:00', 'MDT' => now(),
            'ClassSessionID' => $session->id, 'Status' => 'leave', 'SessionDeducted' => 0,
        ]);

        $this->flag(true);
        $this->postSubstitute($session)->assertStatus(422);
        $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', ['assignments' => [['class_session_id' => $session->id, 'substitute_teacher_id' => $this->bId]]])->assertStatus(422);
        $this->assertSame([0, 0], [Schedule::count(), ScheduleChangeLog::count()]);

        $this->flag(false);
        $this->postSubstitute($session)->assertOk();
    }

    private function insertMakeupRow(): void
    {
        DB::table('schedules')->insert([
            'id' => 9100, 'student_id' => $this->sc->StudentID, 'teacher_id' => $this->aId, 'day_of_week' => 7,
            'type' => 'extra', 'status' => 'scheduled', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $this->sc->ID, 'schedule_date' => '2026-04-19',
            'start_time' => '13:00', 'end_time' => '15:00', 'original_schedule_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function flag(bool $on): void
    {
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false, 'FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_1' => $on]]);
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }

    private function postSession(ClassSession $session, string $suffix)
    {
        return $this->api()->postJson("/api/v1/class-sessions/{$session->id}/{$suffix}");
    }

    private function postSubstitute(ClassSession $session, array $extra = [], ?int $teacherId = null)
    {
        return $this->api()->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
                'substitute_teacher_id' => $teacherId ?? $this->bId,
                'reason' => 'flag test',
            ] + $extra);
    }

    /** Session 04-19 13:00 with a learning record, no schedules rows. */
    private function plainSession(): ClassSession
    {
        $session = ClassSession::create([
            'StudentClassID' => $this->sc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $this->sc->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => $this->aId, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => '2026-04-19', 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);

        return $session;
    }

    /** Cross-date reschedule 04-10 -> 04-19 13:00 already done: anchor 9000 + live row 9001 (contract teacher). */
    private function crossDateShape(): ClassSession
    {
        $session = $this->plainSession();
        $base = [
            'student_id' => $this->sc->StudentID, 'day_of_week' => 7, 'type' => 'normal', 'deduction' => 1,
            'branch_id' => 1, 'student_course_id' => $this->sc->ID, 'teacher_id' => $this->aId,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('schedules')->insert($base + [
            'id' => 9000, 'status' => 'rescheduled', 'schedule_date' => '2026-04-10',
            'start_time' => '13:00', 'end_time' => '15:00', 'original_schedule_id' => null,
        ]);
        DB::table('schedules')->insert($base + [
            'id' => 9001, 'status' => 'scheduled', 'schedule_date' => '2026-04-19',
            'start_time' => '13:00', 'end_time' => '15:00', 'original_schedule_id' => 9000,
        ]);

        return $session;
    }

    private function seedWorld(): void
    {
        $mk = function (string $name, string $type, int $admin) {
            $u = User::create([
                'LoginName' => "{$name}@occ-b1.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false,
            ]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);
            return $u;
        };
        $dir = $mk('dirb1', 'A', 1);
        $this->aId = (int) $mk('teachera', 'T', 0)->id;
        $this->bId = (int) $mk('teacherb', 'T', 0)->id;
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $stu = Student::create([
            'name' => 'B1 student', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $this->sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $this->aId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
    }
}
