<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ClassSessionIndexReadService;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** TD-076 Track B PR-C: flag-aware resolver (teacherForOccurrence), lineage key (#3590 item 1), stranded fallback (item 3). */
class OccurrenceResolverV2Test extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-04-19';

    private string $token;
    private int $aId;
    private int $bId;
    private StudentClass $sc;
    private ClassSession $session;

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

    public function test_flag_off_is_the_legacy_lookup_and_never_touches_the_course_or_campus(): void
    {
        $this->flag(false);
        $this->row(1, 'rescheduled', $this->aId);
        $this->row(2, 'scheduled', $this->bId, ['original_schedule_id' => 1, 'original_schedule_date' => self::DATE, 'original_start_time' => '13:00']);

        DB::enableQueryLog();
        $tid = SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00:00');
        $sub = SubstituteScheduleService::resolveSubstituteUserId((int) $this->sc->ID, self::DATE, '13:00');
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertSame($this->bId, $tid);
        $this->assertSame($this->bId, $sub);
        $this->assertStringNotContainsString('StudentClass', $sql);
        $this->assertStringNotContainsString('Student`', $sql);
    }

    public function test_flag_on_stamped_live_row_wins_over_a_stale_unstamped_chain_row(): void
    {
        $this->row(1, 'rescheduled', $this->aId);
        $this->row(2, 'scheduled', $this->aId, ['original_schedule_id' => 1, 'original_schedule_date' => self::DATE, 'original_start_time' => '13:00']); // restored live row
        $this->row(3, 'scheduled', $this->bId, ['original_schedule_id' => 1]);                        // stale, newer id, unstamped
        $this->flag(false);
        $this->assertSame($this->bId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00'), 'flag off keeps the legacy pick');

        $this->flag(true);
        $this->assertSame($this->aId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00'));
        $this->assertNull(SubstituteScheduleService::resolveSubstituteUserId((int) $this->sc->ID, self::DATE, '13:00'));
        $this->assertSame($this->aId, $this->indexRow()->teacher_id);
        $this->assertNull($this->indexRow()->substitute_teacher_id);
    }

    public function test_flag_on_a_null_teacher_live_row_means_the_contract_teacher_and_flag_is_per_campus(): void
    {
        $this->row(2, 'scheduled', null, ['original_schedule_date' => self::DATE, 'original_start_time' => '13:00']);
        $this->flag(true);
        $this->assertSame($this->aId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, 0, '13:00'));

        DB::table('schedules')->where('id', 2)->update(['teacher_id' => $this->bId]);
        $this->assertSame($this->bId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00'));
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => true, 'FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_1' => false]]);
        $this->assertSame($this->aId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00'), 'campus override off = legacy (no substitute chain)');
        $this->assertSame('x NOT IN (1)', SubstituteScheduleService::campusOnSql('x'), 'global on with a campus override off');
    }

    public function test_flag_on_makeup_resolves_to_the_non_voided_learning_record_teacher(): void
    {
        $this->row(9, 'scheduled', $this->aId, ['type' => 'extra']);
        $lr = DB::table('LearningRecord')->where('ClassSessionID', $this->session->id);
        $this->flag(true);

        $this->assertSame($this->aId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00'), 'LR names the makeup teacher');
        $lr->update(['TeacherID' => $this->bId]);
        $this->assertSame($this->bId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00'));
        $this->assertSame($this->bId, SubstituteScheduleService::resolveSubstituteUserId((int) $this->sc->ID, self::DATE, '13:00'));
        $this->assertSame($this->bId, $this->indexRow()->teacher_id);
        $this->assertTrue(SubstituteScheduleService::isSubstitutedAway((int) $this->sc->ID, self::DATE, '13:00'));

        $lr->update(['VoidedAt' => now()]);
        $this->assertSame($this->aId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, self::DATE, $this->aId, '13:00'), 'voided LR is ignored');
    }

    /** #3590 item 1: flag on -> substitute, flag rolled back, legacy reschedule moves the slot, undo must still go through the writer. */
    public function test_undo_finds_writer_owned_row_after_flag_rollback_and_a_legacy_reschedule(): void
    {
        $this->flag(true);
        $this->api()->postJson("/api/v1/class-sessions/{$this->session->id}/substitute", ['substitute_teacher_id' => $this->bId, 'reason' => 'lineage'])->assertOk();
        $live = Schedule::where('student_course_id', $this->sc->ID)->where('status', 'scheduled')->firstOrFail();

        $this->flag(false);
        $this->api()->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $this->sc->ID, 'old_date' => self::DATE, 'old_start_time' => '13:00',
            'new_date' => '2026-04-21', 'start_time' => '15:00', 'end_time' => '17:00', 'ensure_schedule_exception' => true,
        ])->assertOk();
        $this->session->refresh();
        $this->assertSame('2026-04-21', Carbon::parse($this->session->SessionDate)->toDateString(), 'the slot moved, so the writer log no longer matches it');
        $moved = Schedule::findOrFail($live->id);
        $this->assertSame('2026-04-21', Carbon::parse($moved->schedule_date)->toDateString());
        $this->assertSame(self::DATE, Carbon::parse($moved->original_schedule_date)->toDateString(), 'identity stays frozen');

        $this->api()->postJson("/api/v1/class-sessions/{$this->session->id}/substitute/undo")->assertOk();

        $kept = Schedule::find($live->id);
        $this->assertNotNull($kept, 'the legacy cleanup would have deleted the row that carries the reschedule');
        $this->assertSame($this->aId, (int) $kept->teacher_id);
        $this->assertContains('restore', ScheduleChangeLog::orderBy('id')->pluck('reason')->all());
    }

    /** #3590 item 3: flag on, the writer finds no live row at the slot (null) -> legacy cleanup still clears the stranded anchor and restores. */
    public function test_restore_with_no_live_row_falls_back_to_the_legacy_cleanup_flag_on(): void
    {
        $this->flag(true);
        $this->row(30, 'rescheduled', $this->aId);                       // stranded anchor, its live row is gone
        LearningRecord::where('ClassSessionID', $this->session->id)->update(['TeacherID' => $this->bId]);
        $before = ScheduleChangeLog::count();

        $this->api()->postJson("/api/v1/class-sessions/{$this->session->id}/substitute", ['substitute_teacher_id' => $this->aId, 'reason' => 'restore'])
            ->assertOk()->assertJsonFragment(['restored_teacher_id' => $this->aId]);

        $this->assertNull(Schedule::find(30), 'legacy fallback removed the stranded anchor');
        $this->assertSame($this->aId, (int) LearningRecord::where('ClassSessionID', $this->session->id)->value('TeacherID'));
        $this->assertSame($before, ScheduleChangeLog::count(), 'the writer found nothing, so it logged nothing');
    }

    private function indexRow(): object
    {
        $svc = app(ClassSessionIndexReadService::class);
        $row = $svc->buildQuery(Request::create('/api/v1/class-sessions', 'GET', ['start' => self::DATE, 'end' => '2026-04-30']))->get()
            ->firstWhere('id', $this->session->id);

        return $svc->transformRow($row);
    }

    private function row(int $id, string $status, ?int $teacherId, array $extra = []): void
    {
        DB::table('schedules')->insert($extra + [
            'id' => $id, 'student_id' => $this->sc->StudentID, 'teacher_id' => $teacherId, 'day_of_week' => 7,
            'type' => 'normal', 'status' => $status, 'deduction' => 1, 'branch_id' => 1, 'student_course_id' => $this->sc->ID,
            'schedule_date' => self::DATE, 'start_time' => '13:00', 'end_time' => '15:00', 'original_schedule_id' => null,
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

    private function seedWorld(): void
    {
        $mk = function (string $name, string $type, int $admin) {
            $u = User::create([
                'LoginName' => "{$name}@occ-c.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false,
            ]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $dir = $mk('dirc', 'A', 1);
        $this->aId = (int) $mk('teachera', 'T', 0)->id;
        $this->bId = (int) $mk('teacherb', 'T', 0)->id;
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $stu = Student::create(['name' => 'C student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->aId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 6, 'UsedSessions' => 2, 'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $this->session = ClassSession::create([
            'StudentClassID' => $this->sc->ID, 'SessionDate' => self::DATE, 'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $this->sc->ID, 'ClassSessionID' => $this->session->id, 'TeacherID' => $this->aId, 'Status' => 'pending',
            'Content' => '', 'SessionDate' => self::DATE, 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);
    }
}
