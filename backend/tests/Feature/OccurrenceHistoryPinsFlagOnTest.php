<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** TD-076 Track B PR-B2: history pins + LR teacher correction through OccurrenceAssignmentService (flag off = #207 as today). */
class OccurrenceHistoryPinsFlagOnTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $aId;
    private int $bId;
    private int $cId;
    private StudentClass $sc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        $this->seedWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_contract_change_pins_taught_occurrence_and_readers_keep_old_teacher_flag_on(): void
    {
        $this->flag(true);
        $past = $this->taughtSession('2026-04-10', $this->aId, signInManual: true);
        $future = $this->makeSession('2026-04-24', 'scheduled');

        $this->changeContractTeacher($this->bId)->assertOk();

        $live = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->whereDate('schedule_date', '2026-04-10')->get();
        $this->assertCount(1, $live);
        $this->assertSame($this->aId, (int) $live[0]->teacher_id);
        $this->assertSame('2026-04-10', substr((string) $live[0]->original_schedule_date, 0, 10));
        $log = ScheduleChangeLog::all();
        $this->assertCount(1, $log);
        $this->assertSame('pin', $log[0]->reason);
        $this->assertSame((int) $live[0]->id, (int) $log[0]->schedule_id);

        $this->assertSame($this->aId, SubstituteScheduleService::effectiveInstructorUserId((int) $this->sc->ID, '2026-04-10', $this->bId, '16:00'));
        $rows = collect($this->api()->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$this->sc->ID}&per_page=100")->assertOk()->json('data'));
        $this->assertSame($this->aId, (int) $rows->firstWhere('id', $past->id)['teacher_id']);
        $this->assertSame($this->bId, (int) $rows->firstWhere('id', $future->id)['teacher_id']);
    }

    public function test_rfid_and_substitute_disagreement_is_not_pinned(): void
    {
        $this->flag(true);
        $past = $this->taughtSession('2026-04-10', $this->aId, signInManual: false);
        LearningRecord::where('ClassSessionID', $past->id)->delete();
        $this->substituteRow('2026-04-10', $this->cId);

        $this->changeContractTeacher($this->bId)->assertOk();

        $this->assertSame(0, ScheduleChangeLog::count());
        $live = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->whereDate('schedule_date', '2026-04-10')->get();
        $this->assertCount(1, $live);
        $this->assertSame($this->cId, (int) $live[0]->teacher_id, 'existing substitute row untouched, no guess');
    }

    public function test_lr_update_teacher_without_update_class_moves_the_occurrence_teacher(): void
    {
        $this->flag(true);
        $past = $this->taughtSession('2026-04-10', $this->aId, signInManual: true);
        $lr = LearningRecord::where('ClassSessionID', $past->id)->firstOrFail();

        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId])->assertOk();

        $this->assertSame($this->bId, SubstituteScheduleService::effectiveInstructorUserId((int) $this->sc->ID, '2026-04-10', $this->aId, '16:00'));
        $this->assertSame($this->aId, (int) $this->sc->fresh()->TeacherID, 'contract untouched when update_class is false');
        $this->assertSame(1, ScheduleChangeLog::where('to_teacher_id', $this->bId)->count());
    }

    public function test_lr_update_teacher_flag_off_parity_leaves_schedules_alone(): void
    {
        $this->flag(false);
        $past = $this->taughtSession('2026-04-10', $this->aId, signInManual: true);
        $lr = LearningRecord::where('ClassSessionID', $past->id)->firstOrFail();

        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId])->assertOk();

        $this->assertSame(0, Schedule::count());
        $this->assertSame(0, ScheduleChangeLog::count());
    }

    public function test_contract_change_flag_off_keeps_legacy_207_pin_without_log(): void
    {
        $this->flag(false);
        $this->taughtSession('2026-04-10', $this->aId, signInManual: true);

        $this->changeContractTeacher($this->bId)->assertOk();

        $live = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->whereDate('schedule_date', '2026-04-10')->get();
        $this->assertCount(1, $live);
        $this->assertSame($this->aId, (int) $live[0]->teacher_id);
        $this->assertNull($live[0]->original_schedule_date);
        $this->assertSame(0, ScheduleChangeLog::count());
    }

    public function test_repeat_change_keeps_a_substitute_row_naming_the_current_contract_teacher(): void
    {
        $this->flag(true);
        $past = $this->taughtSession('2026-04-10', $this->aId, signInManual: false);
        LearningRecord::where('ClassSessionID', $past->id)->delete();
        $this->substituteRow('2026-04-10', $this->bId);
        $this->sc->update(['TeacherID' => $this->bId]);

        $this->changeContractTeacher($this->cId)->assertOk();

        $this->assertSame(0, ScheduleChangeLog::count(), 'sub row B vs RFID A disagree: no overwrite');
        $this->assertSame($this->bId, SubstituteScheduleService::effectiveInstructorUserId((int) $this->sc->ID, '2026-04-10', $this->cId, '16:00'));
    }

    public function test_sign_in_only_leave_and_makeup_occurrences_are_not_pinned(): void
    {
        $this->flag(true);
        $leave = $this->taughtSession('2026-04-10', $this->aId, signInManual: true);
        StudentSignIn::where('ClassSessionID', $leave->id)->update(['Status' => 'leave', 'SignOutDT' => '2026-04-10 18:00:00']);
        $leave->update(['Status' => 'scheduled']);
        $retro = $this->makeSession('2026-04-09', 'leave_adjusted');
        $makeup = $this->taughtSession('2026-04-11', $this->aId, signInManual: true);
        DB::table('schedules')->insert([
            'student_id' => $this->sc->StudentID, 'teacher_id' => $this->aId, 'subject' => 'Math', 'day_of_week' => 6, 'type' => 'extra',
            'status' => 'scheduled', 'deduction' => 1, 'branch_id' => 1, 'student_course_id' => $this->sc->ID,
            'schedule_date' => '2026-04-11', 'start_time' => '16:00', 'end_time' => '18:00', 'class_type' => 'one_on_one',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->changeContractTeacher($this->bId)->assertOk();

        $this->assertSame(0, ScheduleChangeLog::count());
        $this->assertSame(1, Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->count(), 'only the makeup row, no second live row');
    }

    private function changeContractTeacher(int $teacherId)
    {
        return $this->api()->putJson("/api/v1/student-classes/{$this->sc->ID}", ['teacher_id' => $teacherId]);
    }

    private function makeSession(string $date, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $this->sc->ID, 'SessionDate' => $date,
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => $status,
        ]);
    }

    /** Attended session with an approved LR and one sign-in (manual = has RecordedByUserID, else RFID-style). */
    private function taughtSession(string $date, int $teacherId, bool $signInManual): ClassSession
    {
        $session = $this->makeSession($date, 'attended');
        StudentSignIn::create([
            'StudentClassID' => $this->sc->ID, 'StudentID' => $this->sc->StudentID, 'TeacherID' => $teacherId,
            'RecordedByUserID' => $signInManual ? $this->aId : null,
            'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => "{$date} 16:05:00", 'MDT' => now(),
            'ClassSessionID' => $session->id, 'Status' => 'present', 'SessionDeducted' => 1,
        ]);
        LearningRecord::create([
            'StudentClassID' => $this->sc->ID, 'ClassSessionID' => $session->id, 'TeacherID' => $teacherId,
            'Status' => 'approved', 'Content' => 'past', 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00',
        ]);

        return $session;
    }

    /** Today's substitute chain: rescheduled anchor + live scheduled row naming $teacherId. */
    private function substituteRow(string $date, int $teacherId): void
    {
        $base = [
            'student_id' => $this->sc->StudentID, 'day_of_week' => 5, 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $this->sc->ID, 'schedule_date' => $date, 'start_time' => '16:00', 'end_time' => '18:00',
            'created_at' => now(), 'updated_at' => now(), 'subject' => 'Math', 'class_type' => 'one_on_one',
        ];
        $anchor = DB::table('schedules')->insertGetId($base + ['status' => 'rescheduled', 'teacher_id' => $this->aId, 'deduction' => 0]);
        DB::table('schedules')->insert($base + ['status' => 'scheduled', 'teacher_id' => $teacherId, 'original_schedule_id' => $anchor]);
    }

    private function flag(bool $on): void
    {
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false, 'FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_1' => $on]]);
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }

    private function seedWorld(): void
    {
        $mk = function (string $name, string $type, int $admin) {
            $u = User::create([
                'LoginName' => "{$name}@occ-b2.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false,
            ]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $dir = $mk('dirb2', 'A', 1);
        $this->aId = (int) $mk('teachera', 'T', 0)->id;
        $this->bId = (int) $mk('teacherb', 'T', 0)->id;
        $this->cId = (int) $mk('teacherc', 'T', 0)->id;
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $stu = Student::create(['name' => 'B2 student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->aId, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
    }
}
