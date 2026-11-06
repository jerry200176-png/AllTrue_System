<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\PayrollMonthStatus;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParttimePayrollTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────
    // Golden calculation: one-on-one high school
    // ──────────────────────────────────────────
    public function test_golden_one_on_one_high_school_rate(): void
    {
        $dir = $this->createDirector('dir-payroll-1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt1@test.com', '兼職甲');
        $stu = $this->createStudent(1, '高中生A');

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '16:00', '18:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $data = $res->json();
        $this->assertCount(1, $data['teachers']);
        $teacher = $data['teachers'][0];
        $this->assertEquals(800, $teacher['total_salary']); // 400/h * 2h
        $this->assertEquals(2, $teacher['total_hours']);
        $this->assertEquals(2, $teacher['high_hours']);
    }

    // ──────────────────────────────────────────
    // Golden: one-on-two junior high
    // ──────────────────────────────────────────
    public function test_golden_one_on_two_junior_rate(): void
    {
        $dir = $this->createDirector('dir-payroll-2@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt2@test.com', '兼職乙');
        $stu = $this->createStudent(1, '國中生B');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_two');
        $this->makeApprovedLR($sc, $tid, '2026-04-02', '10:00', '12:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // base 350/h * 2h = 700 (headcountBonus removed; single LR = no concurrency)
        $this->assertEquals(700, $teacher['total_salary']);
        $this->assertEquals(2, $teacher['junior_hours']);
    }

    // ──────────────────────────────────────────
    // Golden: one-on-three elementary
    // ──────────────────────────────────────────
    public function test_golden_one_on_three_elementary_rate(): void
    {
        $dir = $this->createDirector('dir-payroll-3@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt3@test.com', '兼職丙');
        $stu = $this->createStudent(1, '國小生C');

        $sc = $this->makeStudentClass($stu, $tid, 3, 'one_on_three');
        $this->makeApprovedLR($sc, $tid, '2026-04-03', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // base 300/h * 2h = 600 (headcountBonus removed; single LR = no concurrency)
        $this->assertEquals(600, $teacher['total_salary']);
        $this->assertEquals(2, $teacher['elementary_hours']);
    }

    // ──────────────────────────────────────────
    // Golden: tutoring always 200/h, no headcount bonus
    // ──────────────────────────────────────────
    public function test_golden_tutoring_rate_no_bonus(): void
    {
        $dir = $this->createDirector('dir-payroll-4@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt4@test.com', '兼職丁');
        $stu = $this->createStudent(1, '輔導生D');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'tutoring');
        $this->makeApprovedLR($sc, $tid, '2026-04-04', '09:00', '11:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // tutoring 200/h * 2h = 400
        $this->assertEquals(400, $teacher['total_salary']);
        $this->assertEquals(2, $teacher['tutoring_hours']);
    }

    // ──────────────────────────────────────────
    // Only part_time teachers show up
    // ──────────────────────────────────────────
    public function test_excludes_full_time_teachers(): void
    {
        $dir = $this->createDirector('dir-payroll-5@test.com', [1]);
        $ptId = $this->createPartTimeTeacher(1, 'pt5@test.com', '兼職');
        $ftId = $this->createFullTimeTeacher(1, 'ft5@test.com', '專職');
        $stu1 = $this->createStudent(1, '學生E');
        $stu2 = $this->createStudent(1, '學生F');

        $sc1 = $this->makeStudentClass($stu1, $ptId, 10, 'one_on_one');
        $sc2 = $this->makeStudentClass($stu2, $ftId, 10, 'one_on_one');
        $this->makeApprovedLR($sc1, $ptId, '2026-04-05', '10:00', '12:00');
        $this->makeApprovedLR($sc2, $ftId, '2026-04-05', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $this->assertCount(1, $res->json('teachers'));
        $this->assertEquals('兼職', $res->json('teachers.0.teacher_name'));
    }

    // ──────────────────────────────────────────
    // Attendance, rather than learning-record approval, determines payroll.
    // ──────────────────────────────────────────
    public function test_counts_attended_sessions_regardless_of_evaluation_status(): void
    {
        $dir = $this->createDirector('dir-payroll-6@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt6@test.com', '兼職戊');
        $stu = $this->createStudent(1, '學生G');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');

        // All three sessions have valid attendance; evaluation state must not affect payroll.
        $this->makeApprovedLR($sc, $tid, '2026-04-10', '10:00', '12:00');
        // Pending evaluation — included
        $this->makeLR($sc, $tid, '2026-04-11', '10:00', '12:00', 'pending');
        // Voided evaluation — included
        $voided = $this->makeLR($sc, $tid, '2026-04-12', '10:00', '12:00', 'approved');
        $voided->update(['VoidedAt' => now()]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $this->assertEquals(3, $res->json('teachers.0.session_count'));
    }

    public function test_counts_attendance_without_any_learning_record_and_excludes_voided_or_non_payable_attendance(): void
    {
        $dir = $this->createDirector('dir-payroll-attendance@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-attendance@test.com', '兼職點名');
        $stu = $this->createStudent(1, '點名學生');
        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');

        $this->makeAttendance($sc, $tid, '2026-04-10', '10:00', '12:00', 'present');
        $this->makeAttendance($sc, $tid, '2026-04-11', '10:00', '12:00', 'leave');
        $voided = $this->makeAttendance($sc, $tid, '2026-04-12', '10:00', '12:00', 'present');
        $voided->update(['VoidedAt' => now()]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $this->assertSame(1, $res->json('teachers.0.session_count'));
        $this->assertSame(2, $res->json('teachers.0.total_hours'));
    }

    public function test_attendance_anomaly_is_visible_in_draft_and_blocks_lock(): void
    {
        $dir = $this->createDirector('dir-payroll-anomaly@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-anomaly@test.com', '兼職異常');
        $stu = $this->createStudent(1, '異常學生');
        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');
        // makeAttendance intentionally leaves ClassSession.Status=scheduled while the sign-in is present.
        $this->makeAttendance($sc, $tid, '2026-04-10', '10:00', '12:00', 'present');

        $headers = $this->authHeaders($dir['token']);
        $summary = $this->withHeaders($headers)->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $summary->assertOk();
        $this->assertGreaterThan(0, $summary->json('summary.anomaly_count'));
        $this->assertContains('status_mismatch', array_column($summary->json('summary.anomalies'), 'code'));

        $this->withHeaders($headers)
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['anomaly_count', 'anomalies']);
    }

    public function test_ended_lesson_without_attendance_is_listed_as_unmarked_notice_and_does_not_block_lock(): void
    {
        $dir = $this->createDirector('dir-payroll-unmarked@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-unmarked@test.com', '兼職未點');
        $ftId = $this->createFullTimeTeacher(1, 'ft-unmarked@test.com', '專職未點');
        $stu = $this->createStudent(1, '未點學生');
        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');
        $ftSc = $this->makeStudentClass($stu, $ftId, 7, 'one_on_one');
        $mk = fn ($class, string $date, string $status) => ClassSession::create([
            'StudentClassID' => $class->ID, 'SessionDate' => $date,
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => $status, 'Note' => '',
        ]);

        $unmarked = $mk($sc, '2026-04-10', 'completed');
        $marked = $mk($sc, '2026-04-11', 'completed');
        $this->makeAttendanceForSession($sc, $tid, $marked, '2026-04-11', '10:00', 'present');
        $mk($sc, '2026-04-12', 'cancelled');
        $mk($ftSc, '2026-04-13', 'completed');
        foreach (['leave', 'leave_adjusted', 'excused', 'voided'] as $i => $outcome) { // a recorded non-attendance outcome is not "unmarked"
            $mk($sc, sprintf('2026-04-%02d', 14 + $i), $outcome);
        }

        $headers = $this->authHeaders($dir['token']);
        $res = $this->withHeaders($headers)->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $res->assertOk();
        $hits = $res->json('summary.unmarked_lessons');
        $this->assertSame(1, $res->json('summary.unmarked_count'));
        $this->assertSame([$unmarked->id], array_column($hits, 'class_session_id'));
        $this->assertSame('unmarked_attendance', $hits[0]['code']);
        $this->assertSame('已結束但沒有點名，不會算進薪資', $hits[0]['message']);
        // in-app #482: the row names the lesson
        $this->assertSame(['10:00', '12:00', '未點學生', (int) $sc->ID], [$hits[0]['start_time'], $hits[0]['end_time'], $hits[0]['student_name'], $hits[0]['student_class_id']]);
        $this->assertNotContains('unmarked_attendance', array_column($res->json('summary.anomalies'), 'code'));

        $this->withHeaders($headers)
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1])
            ->assertOk();

        // Future lesson (not ended yet) is not flagged.
        $mk($sc, '2099-04-10', 'scheduled');
        $future = $this->withHeaders($headers)->getJson('/api/v1/finance/parttime-payroll?month=2099-04&branch_id=1');
        $future->assertOk();
        $this->assertSame(0, $future->json('summary.unmarked_count'));
    }

    public function test_campus_scope_without_students_lists_no_unmarked_lessons_or_anomalies(): void
    {
        $dir = $this->createDirector('dir-payroll-scope@test.com', [2]);
        $tid = $this->createPartTimeTeacher(1, 'pt-scope@test.com', '兼職他校');
        $stu = $this->createStudent(1, '他校學生');
        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');
        ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-10',
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'completed', 'Note' => '',
        ]);
        $this->makeAttendance($sc, $tid, '2026-04-11', '10:00', '12:00', 'present');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=2');
        $res->assertOk();
        $this->assertSame(0, $res->json('summary.unmarked_count'));
        $this->assertSame(0, $res->json('summary.anomaly_count'));
    }

    public function test_locked_payroll_reads_immutable_snapshot_until_reopen(): void
    {
        $dir = $this->createDirector('dir-payroll-snapshot@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-snapshot@test.com', '兼職快照');
        $stu = $this->createStudent(1, '快照學生');
        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-10',
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00',
            'Status' => 'attended', 'Note' => '',
        ]);
        $signIn = StudentSignIn::create([
            'StudentClassID' => $sc->ID, 'StudentID' => $stu->id, 'TeacherID' => $tid,
            'ClassSessionID' => $session->id, 'Status' => 'present', 'SignInDT' => '2026-04-10 10:00:00',
        ]);

        $headers = $this->authHeaders($dir['token']);
        $this->withHeaders($headers)
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1])
            ->assertOk();

        $run = PayrollRun::where('branch_id', 1)->where('month', '2026-04')->where('status', 'locked')->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(1, PayrollRunLine::where('run_id', $run->id)->count());
        $lockedSalary = $this->withHeaders($headers)->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1')->json('teachers.0.total_salary');
        $lockedDetail = $this->withHeaders($headers)->getJson("/api/v1/finance/parttime-payroll/{$tid}/sessions?month=2026-04&branch_id=1");
        $lockedDetail->assertOk()->assertJsonPath('sessions.0.session_salary', $lockedSalary);

        $session->update(['EndTime' => '14:00:00']);
        $sc->update(['SessionDuration' => 180]);
        $stillLockedSalary = $this->withHeaders($headers)->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1')->json('teachers.0.total_salary');
        $this->assertSame($lockedSalary, $stillLockedSalary);

        $this->withHeaders($this->authHeaders($this->createSuperAdmin('sa-payroll-snapshot@test.com', [1])['token']))
            ->postJson('/api/v1/finance/parttime-payroll/reopen', ['month' => '2026-04', 'branch_id' => 1, 'reason' => '修正堂次時間'])
            ->assertOk();
        $draftSalary = $this->withHeaders($headers)->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1')->json('teachers.0.total_salary');
        $this->assertGreaterThan($lockedSalary, $draftSalary);
        $this->assertSame('reopened', $run->fresh()->status);
    }

    // ──────────────────────────────────────────
    // Branch isolation
    // ──────────────────────────────────────────
    public function test_branch_isolation(): void
    {
        $dir1 = $this->createDirector('dir-b1@test.com', [1]);
        $dir2 = $this->createDirector('dir-b2@test.com', [2]);
        $tid = $this->createPartTimeTeacher(1, 'pt7@test.com', '兼職己');
        $stu1 = $this->createStudent(1, '分校1學生');
        $stu2 = $this->createStudent(2, '分校2學生');

        $sc1 = $this->makeStudentClass($stu1, $tid, 10, 'one_on_one');
        $sc2 = $this->makeStudentClass($stu2, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc1, $tid, '2026-04-15', '10:00', '12:00');
        $this->makeApprovedLR($sc2, $tid, '2026-04-15', '14:00', '16:00');

        // Director 1 sees only branch 1
        $r1 = $this->withHeaders($this->authHeaders($dir1['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $r1->assertOk();
        $this->assertEquals(1, $r1->json('teachers.0.session_count'));

        // Director 2 sees only branch 2
        $r2 = $this->withHeaders($this->authHeaders($dir2['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=2');
        $r2->assertOk();
        $this->assertEquals(1, $r2->json('teachers.0.session_count'));
    }

    // ──────────────────────────────────────────
    // Teacher sessions endpoint pagination
    // ──────────────────────────────────────────
    public function test_teacher_sessions_pagination(): void
    {
        $dir = $this->createDirector('dir-payroll-8@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt8@test.com', '分頁老師');
        $stu = $this->createStudent(1, '學生H');
        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');

        for ($d = 1; $d <= 5; $d++) {
            $this->makeApprovedLR($sc, $tid, sprintf('2026-04-%02d', $d), '10:00', '12:00');
        }

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson("/api/v1/finance/parttime-payroll/{$tid}/sessions?month=2026-04&branch_id=1&per_page=2&page=1");

        $res->assertOk();
        $this->assertCount(2, $res->json('sessions'));
        $this->assertEquals(5, $res->json('meta.total'));
        $this->assertEquals(3, $res->json('meta.last_page'));
    }

    // ──────────────────────────────────────────
    // Lock & reopen workflow
    // ──────────────────────────────────────────
    public function test_lock_and_reopen_workflow(): void
    {
        $dir = $this->createDirector('dir-payroll-9@test.com', [1]);
        $superAdmin = $this->createSuperAdmin('sa-payroll@test.com', [1]);

        // Lock
        $r1 = $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1]);
        $r1->assertOk();
        $this->assertEquals('locked', $r1->json('status'));

        // Double lock should fail
        $r2 = $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1]);
        $r2->assertStatus(422);

        // Director cannot reopen
        $r3 = $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/reopen', ['month' => '2026-04', 'branch_id' => 1, 'reason' => 'test']);
        $r3->assertStatus(403);

        // Super admin can reopen
        $r4 = $this->withHeaders($this->authHeaders($superAdmin['token']))
            ->postJson('/api/v1/finance/parttime-payroll/reopen', ['month' => '2026-04', 'branch_id' => 1, 'reason' => '重新計算']);
        $r4->assertOk();
        $this->assertEquals('draft', $r4->json('status'));

        // Verify audit log
        $this->assertDatabaseHas('payroll_audit_log', ['branch_id' => 1, 'month' => '2026-04', 'action' => 'lock']);
        $this->assertDatabaseHas('payroll_audit_log', ['branch_id' => 1, 'month' => '2026-04', 'action' => 'reopen', 'reason' => '重新計算']);
    }

    // ──────────────────────────────────────────
    // Export endpoint returns CSV
    // ──────────────────────────────────────────
    public function test_export_returns_csv(): void
    {
        $dir = $this->createDirector('dir-payroll-10@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt10@test.com', '匯出老師');
        $stu = $this->createStudent(1, '學生X');
        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-07', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->get('/api/v1/finance/parttime-payroll/export?month=2026-04&branch_id=1');

        $res->assertOk();
        $content = $res->streamedContent();
        $this->assertStringContainsString('老師姓名', $content);
        $this->assertStringContainsString('匯出老師', $content);
    }

    // ──────────────────────────────────────────
    // Month validation
    // ──────────────────────────────────────────
    public function test_invalid_month_returns_422(): void
    {
        $dir = $this->createDirector('dir-payroll-11@test.com', [1]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=bad&branch_id=1');

        $res->assertStatus(422);
    }

    // ──────────────────────────────────────────
    // Concurrency bonus: two 1-on-1 sessions overlap 1h
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_two_sessions_overlap_1h(): void
    {
        $dir = $this->createDirector('dir-cb1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb1@test.com', '併堂甲');
        $stuA = $this->createStudent(1, '學生A');
        $stuB = $this->createStudent(1, '學生B');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F (2026-04-18)：30-min slice 模型，重疊部份算並堂。
        // 17:00-18:00 A solo 400 + 18:00-19:00 A+B (400+50) + 19:00-20:00 B solo 400 = 1250
        $this->assertEquals(1250, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency bonus: 0.5h overlap
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_half_hour_overlap(): void
    {
        $dir = $this->createDirector('dir-cb2@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb2@test.com', '併堂乙');
        $stuA = $this->createStudent(1, '學生C');
        $stuB = $this->createStudent(1, '學生D');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:30', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：30-min slice。A 17:00-19:00、B 18:30-20:30（contracted 120min）→ 重疊 18:30-19:00。
        // 17:00-18:30 A solo 400×1.5=600 + 18:30-19:00 並堂 (400+50)×0.5=225 + 19:00-20:30 B solo 400×1.5=600 = 1425
        $this->assertEquals(1425, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency bonus: 2h full overlap
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_two_hour_overlap(): void
    {
        $dir = $this->createDirector('dir-cb3@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb3@test.com', '併堂丙');
        $stuA = $this->createStudent(1, '學生E');
        $stuB = $this->createStudent(1, '學生F');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '20:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：contracted=120min，A 17:00-19:00、B 18:00-20:00 → 重疊 18:00-19:00。
        // 17:00-18:00 solo 400 + 18:00-19:00 並堂 450 + 19:00-20:00 solo 400 = 1250
        $this->assertEquals(1250, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency: one_on_two + one_on_one overlap 1h (k=3 students)
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_mixed_class_types(): void
    {
        $dir = $this->createDirector('dir-cb4@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb4@test.com', '併堂丁');
        $stuA = $this->createStudent(1, '學生G');
        $stuB = $this->createStudent(1, '學生H');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_two');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：17:00-18:00 A solo 400 + 18:00-19:00 A+B 並堂 (400+50) + 19:00-20:00 B solo 400 = 1250
        $this->assertEquals(1250, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency: tutoring + one_on_one overlap
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_tutoring_plus_regular(): void
    {
        $dir = $this->createDirector('dir-cb5@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb5@test.com', '併堂戊');
        $stuA = $this->createStudent(1, '學生I');
        $stuB = $this->createStudent(1, '學生J');

        $scA = $this->makeStudentClass($stuA, $tid, 7, 'tutoring');
        $scB = $this->makeStudentClass($stuB, $tid, 3, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：max-base 在並堂段取高值（elementary 300 > tutoring 200）。
        // 17:00-18:00 A solo 200 + 18:00-19:00 A+B max=300,(300+50)=350 + 19:00-20:00 B solo 300 = 850
        $this->assertEquals(850, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency: three sessions fully overlap 1h (k=3 students)
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_three_sessions_overlap(): void
    {
        $dir = $this->createDirector('dir-cb6@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb6@test.com', '併堂己');
        $stuA = $this->createStudent(1, '學生K');
        $stuB = $this->createStudent(1, '學生L');
        $stuC = $this->createStudent(1, '學生M');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');
        $scC = $this->makeStudentClass($stuC, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '18:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '19:00');
        $this->makeApprovedLR($scC, $tid, '2026-04-10', '18:00', '19:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // v1.4 tie-break: all 3 same start 18:00 (diff=0，容忍度內) 且契約 2h 全重疊
        // A (primary, lowest lr_id): 400*2h + (3-1)*50*2h = 800 + 200 = 1000
        // B, C (non-primary): 400*2h - 400*2h = 0 each
        // total = 1000
        $this->assertEquals(1000, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // No overlap → concurrency bonus = 0, same as before
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_no_overlap_regression(): void
    {
        $dir = $this->createDirector('dir-cb7@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb7@test.com', '併堂庚');
        $stuA = $this->createStudent(1, '學生N');
        $stuB = $this->createStudent(1, '學生O');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '17:00', '19:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // A: 400*2h = 800, B: 400*2h = 800, no overlap → total = 1600
        $this->assertEquals(1600, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Payroll uses attendance-session times, not evaluation times.
    // ──────────────────────────────────────────
    public function test_concurrency_uses_attendance_session_times_not_evaluation_times(): void
    {
        $dir = $this->createDirector('dir-cb8@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb8@test.com', '併堂辛');
        $stuA = $this->createStudent(1, '學生P');
        $stuB = $this->createStudent(1, '學生Q');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $lrB = $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');
        $lrB->update(['StartTime' => null, 'EndTime' => null]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // 點名堂次仍為 17:00–19:00 / 18:00–20:00，故依其重疊時段計薪。
        // 評量表時間被清空不應改變薪資。
        $this->assertEquals(1250, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Session detail returns concurrency_bonus_amount field
    // ──────────────────────────────────────────
    public function test_session_detail_includes_concurrency_bonus(): void
    {
        $dir = $this->createDirector('dir-cb9@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb9@test.com', '併堂壬');
        $stuA = $this->createStudent(1, '學生R');
        $stuB = $this->createStudent(1, '學生S');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson("/api/v1/finance/parttime-payroll/{$tid}/sessions?month=2026-04&branch_id=1");

        $res->assertOk();
        $sessions = $res->json('sessions');
        $this->assertCount(2, $sessions);

        $bonuses = array_column($sessions, 'concurrency_bonus_amount');
        sort($bonuses);
        // PRD-F (2026-04-18)：A baseline 400×2=800，attributed 400(17-18 solo)+450(18-19 並堂)=850，delta=+50。
        //                     B baseline 400×2=800，attributed 400(19-20 solo)=400，delta=-400。
        $this->assertEquals([-400, 50], $bonuses);

        foreach ($sessions as $s) {
            $this->assertArrayHasKey('concurrency_bonus_amount', $s);
        }
    }

    // ──────────────────────────────────────────
    // One-on-two with both students present (2 LR same slot)
    // ──────────────────────────────────────────
    public function test_one_on_two_both_students_present_concurrency(): void
    {
        $dir = $this->createDirector('dir-hp1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pthp1@test.com', '兼職HP');
        $stuA = $this->createStudent(1, '一對二A');
        $stuB = $this->createStudent(1, '一對二B');

        $scA = $this->makeStudentClass($stuA, $tid, 7, 'one_on_two');
        $scB = $this->makeStudentClass($stuB, $tid, 7, 'one_on_two');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '10:00', '12:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '10:00', '12:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // v1.4 tie-break: 同 start 10:00 (diff=0，容忍度內) 同層 junior 完全重疊 2h
        // A (primary): 350*2h + (2-1)*50*2h = 700 + 100 = 800
        // B (non-primary): 350*2h - 350*2h = 0
        // total = 800
        $this->assertEquals(800, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Branch rule headcount_bonus no longer affects calculation
    // ──────────────────────────────────────────
    public function test_branch_rule_headcount_bonus_ignored(): void
    {
        $dir = $this->createDirector('dir-reg1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptreg1@test.com', '兼職REG');
        $stu = $this->createStudent(1, '回歸生');

        DB::table('payroll_branch_rules')->insert([
            'branch_id' => 1,
            'base_rates' => json_encode(['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200]),
            'headcount_bonus' => 200,
            'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_two');
        $this->makeApprovedLR($sc, $tid, '2026-04-05', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // headcount_bonus=200 is ignored; base 350*2h = 700, no concurrency
        $this->assertEquals(700, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Level dominance: junior beats tutoring, 2h full overlap
    // ──────────────────────────────────────────
    public function test_level_dominance_junior_over_tutoring(): void
    {
        $dir = $this->createDirector('dir-ld1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptld1@test.com', '層級甲');
        $stuA = $this->createStudent(1, '國中層級A');
        $stuB = $this->createStudent(1, '輔導層級B');

        $scA = $this->makeStudentClass($stuA, $tid, 7, 'one_on_two');  // junior, 350/h
        $scB = $this->makeStudentClass($stuB, $tid, 7, 'tutoring');    // tutoring overrides, 200/h

        $this->makeApprovedLR($scA, $tid, '2026-04-15', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-15', '17:00', '19:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // A (junior, weight=3, dominant): 350*2h=700 + (2-1)*50*2h=100 => 800
        // B (tutoring, weight=1, dominated): 200*2h=400 + (-200*2h)=-400 => max(0,0) = 0
        // total = 800
        $this->assertEquals(800, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Same level overlap: both earn concurrency bonus
    // ──────────────────────────────────────────
    public function test_same_level_both_earn_concurrency(): void
    {
        $dir = $this->createDirector('dir-sl1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptsl1@test.com', '同層甲');
        $stuA = $this->createStudent(1, '同層A');
        $stuB = $this->createStudent(1, '同層B');

        $scA = $this->makeStudentClass($stuA, $tid, 8, 'one_on_one');  // junior, 350/h
        $scB = $this->makeStudentClass($stuB, $tid, 8, 'one_on_one');  // junior, 350/h

        $this->makeApprovedLR($scA, $tid, '2026-04-08', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-08', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // Same level (junior, weight=3): both dominant
        // Each: 350*2h=700 + (2-1)*50*2h=100 => 800
        // total = 1600
        $this->assertEquals(800, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Student grade update cascades to StudentClass.GradeID
    // ──────────────────────────────────────────
    public function test_student_grade_update_cascades_to_studentclass(): void
    {
        $dir = $this->createDirector('dir-gc1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptgc1@test.com', '升級甲');
        $stu = $this->createStudent(1, '升級生');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');  // GradeID=7 (J1)
        $this->assertEquals(7, $sc->fresh()->GradeID);

        // Update student grade from J1 to J2
        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson("/api/v1/students/{$stu->id}", ['grade' => 'J2']);

        $res->assertOk();
        $this->assertEquals(8, $sc->fresh()->GradeID);  // J2 → ClassID=8 cascaded
    }

    // ──────────────────────────────────────────
    // Substitute teacher on schedule wins over sign-in TeacherID
    // ──────────────────────────────────────────
    public function test_payroll_attributes_substitute_teacher_when_schedule_differs_from_sign_in(): void
    {
        $dir = $this->createDirector('dir-payroll-sub@test.com', [1]);
        $contractTeacherId = $this->createPartTimeTeacher(1, 'pt-contract@test.com', '合約老師');
        $substituteTeacherId = $this->createPartTimeTeacher(1, 'pt-sub@test.com', '代課老師');
        $stu = $this->createStudent(1, '輔導生');
        $sc = $this->makeStudentClass($stu, $contractTeacherId, 7, 'tutoring');

        $session = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-09-09',
            'StartTime' => '18:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'attended',
            'Note' => '',
        ]);

        $anchorId = DB::table('schedules')->insertGetId([
            'student_id' => $stu->id,
            'teacher_id' => $contractTeacherId,
            'subject' => 'Math',
            'day_of_week' => 2,
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'class_type' => 'tutoring',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-09-09',
            'student_course_id' => $sc->ID,
            'original_schedule_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('schedules')->insert([
            'student_id' => $stu->id,
            'teacher_id' => $substituteTeacherId,
            'subject' => 'Math',
            'day_of_week' => 2,
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'class_type' => 'tutoring',
            'status' => 'scheduled',
            'type' => 'substitute',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-09-09',
            'student_course_id' => $sc->ID,
            'original_schedule_id' => $anchorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Director marked attendance against contract teacher while schedule shows substitute.
        $this->makeAttendanceForSession($sc, $contractTeacherId, $session, '2026-09-09', '18:00', 'present');

        $headers = $this->authHeaders($dir['token']);
        $summary = $this->withHeaders($headers)
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-09&branch_id=1');
        $summary->assertOk();
        $teachers = collect($summary->json('teachers'));
        $this->assertNull($teachers->firstWhere('teacher_id', $contractTeacherId));
        $subRow = $teachers->firstWhere('teacher_id', $substituteTeacherId);
        $this->assertNotNull($subRow);
        $this->assertSame(1, $subRow['session_count']);
        // tutoring 200/h × contracted 2h (SessionDuration=120) = 400
        $this->assertSame(400, $subRow['total_salary']);

        $detail = $this->withHeaders($headers)
            ->getJson("/api/v1/finance/parttime-payroll/{$substituteTeacherId}/sessions?month=2026-09&branch_id=1");
        $detail->assertOk()
            ->assertJsonPath('teacher.teacher_name', '代課老師')
            ->assertJsonPath('sessions.0.student_name', '輔導生');
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    private function createDirector(string $email, array $campusIds): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createSuperAdmin(string $email, array $campusIds): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => 'SuperAdmin', 'PSW' => 'secret',
            'type' => 'S', 'phone' => '0911999999', 'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createPartTimeTeacher(int $campusId, string $email, string $name): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => $name, 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false,
            'employment_type' => 'part_time',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $user->id;
    }

    private function createFullTimeTeacher(int $campusId, string $email, string $name): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => $name, 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922222222', 'MustChangePassword' => false,
            'employment_type' => 'full_time',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $user->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 7,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function makeStudentClass(Student $stu, int $teacherId, int $gradeId, string $classType): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $stu->id, 'TeacherID' => $teacherId, 'GradeID' => $gradeId,
            'SubjectID' => 1, 'ClassType' => $classType, 'by1' => 1,
            'ScheduleMode' => 'count', 'SessionCount' => 10, 'RemainingSessions' => 9,
            'UsedSessions' => 1, 'Rate' => 500, 'Charge' => 5000, 'Pay' => 0, 'Paid' => 0,
            'Period' => 4, 'SessionDuration' => 120, 'TotalHours' => 20,
            'StartDate' => '2026-04-01', 'EndDate' => '2026-06-30',
            'week' => 2, 'time' => '16:00:00', 'Stop' => 0, 'MDate' => now(),
        ]);
    }

    private function makeApprovedLR(StudentClass $sc, int $teacherId, string $date, string $start, string $end): LearningRecord
    {
        return $this->makeLR($sc, $teacherId, $date, $start, $end, 'approved');
    }

    private function makeLR(StudentClass $sc, int $teacherId, string $date, string $start, string $end, string $status): LearningRecord
    {
        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $date,
            'StartTime' => "{$start}:00", 'EndTime' => "{$end}:00",
            'Status' => 'completed', 'Note' => '',
        ]);
        $record = LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId, 'Content' => 'test', 'Subject' => 'Math',
            'Status' => $status, 'SessionDate' => $date,
            'StartTime' => "{$start}:00", 'EndTime' => "{$end}:00",
            'SessionDeducted' => $status === 'approved',
            'ApprovedBy' => $status === 'approved' ? $teacherId : null,
            'ApprovedAt' => $status === 'approved' ? now() : null,
        ]);
        $this->makeAttendanceForSession($sc, $teacherId, $cs, $date, $start, 'present');
        return $record;
    }

    private function makeAttendance(StudentClass $sc, int $teacherId, string $date, string $start, string $end, string $status): StudentSignIn
    {
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $date,
            'StartTime' => "{$start}:00", 'EndTime' => "{$end}:00",
            'Status' => 'scheduled', 'Note' => '',
        ]);

        return $this->makeAttendanceForSession($sc, $teacherId, $session, $date, $start, $status);
    }

    private function makeAttendanceForSession(StudentClass $sc, int $teacherId, ClassSession $session, string $date, string $start, string $status): StudentSignIn
    {
        return StudentSignIn::create([
            'StudentClassID' => $sc->ID,
            'StudentID' => $sc->StudentID,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $session->id,
            'Status' => $status,
            'SignInDT' => "{$date} {$start}:00",
            'SignOutDT' => $status === 'leave' ? "{$date} {$start}:00" : null,
        ]);
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    // ── #4971 行政加給 ─────────────────────────────────────────
    private function bonusUrl(): string
    {
        return '/api/v1/finance/parttime-payroll/admin-bonus';
    }

    public function test_admin_bonus_create_edit_delete_adds_to_total_and_audits(): void
    {
        $dir = $this->createDirector('dir-ab-1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ab1@test.com', '兼職加給');
        $sc = $this->makeStudentClass($this->createStudent(1, '生'), $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '16:00', '18:00');
        $h = $this->authHeaders($dir['token']);
        $body = ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 500, 'reason' => '行政支援'];

        $this->withHeaders($h)->putJson($this->bonusUrl(), $body)->assertOk();
        $t = $this->withHeaders($h)->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1')->json('teachers.0');
        $this->assertSame(1300, $t['total_salary']); // 800 + 500
        $this->assertSame(500, $t['admin_bonus']);
        $csv = $this->withHeaders($h)->get('/api/v1/finance/parttime-payroll/export?month=2026-04&branch_id=1')->streamedContent();
        $this->assertStringContainsString('行政支援', $csv);

        $this->withHeaders($h)->putJson($this->bonusUrl(), array_merge($body, ['amount' => 700]))->assertOk();
        $this->assertSame(700, (int) DB::table('parttime_payroll_admin_bonuses')->value('amount'));
        $this->assertSame(1, DB::table('parttime_payroll_admin_bonuses')->count());

        $this->withHeaders($h)->deleteJson($this->bonusUrl(), ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04'])->assertOk();
        $this->assertSame(0, DB::table('parttime_payroll_admin_bonuses')->count());
        $this->assertEqualsCanonicalizing(
            ['payroll.parttime_admin_bonus.created', 'payroll.parttime_admin_bonus.updated', 'payroll.parttime_admin_bonus.deleted'],
            DB::table('security_audit_events')->where('event_type', 'like', 'payroll.parttime_admin_bonus.%')->pluck('event_type')->all()
        );
    }

    public function test_admin_bonus_can_still_be_removed_after_teacher_becomes_full_time(): void
    {
        $dir = $this->createDirector('dir-ab-9@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ab9@test.com', '轉正職');
        $h = $this->authHeaders($dir['token']);
        $this->withHeaders($h)->putJson($this->bonusUrl(), ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 500, 'reason' => '行政支援'])->assertOk();
        DB::table('User')->where('id', $tid)->update(['employment_type' => 'full_time']);

        // Entering a new bonus is refused, but the existing one can be removed.
        $this->withHeaders($h)->putJson($this->bonusUrl(), ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 600, 'reason' => 'x'])->assertStatus(422);
        $this->withHeaders($h)->deleteJson($this->bonusUrl(), ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04'])->assertOk();
        $this->assertSame(0, DB::table('parttime_payroll_admin_bonuses')->count());
        // Campus scope still applies to removal.
        $this->withHeaders($h)->deleteJson($this->bonusUrl(), ['branch_id' => 2, 'teacher_id' => $tid, 'month' => '2026-04'])->assertStatus(403);
    }

    public function test_admin_bonus_aggregate_view_adds_every_campus_in_scope(): void
    {
        $dir = $this->createDirector('dir-ab-8@test.com', [1, 2]);
        $tid = $this->createPartTimeTeacher(1, 'ab8@test.com', '兩校');
        $only = $this->createPartTimeTeacher(2, 'ab8b@test.com', '只有加給');
        $sc = $this->makeStudentClass($this->createStudent(1, '生'), $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '16:00', '18:00');
        foreach ([[1, $tid, 300], [2, $tid, 200], [2, $only, 400]] as [$campus, $teacher, $amount]) {
            \App\Models\ParttimePayrollAdminBonus::create(['campus_id' => $campus, 'teacher_id' => $teacher, 'month' => '2026-04', 'amount' => $amount, 'reason' => "r{$campus}", 'created_by' => 1]);
        }
        $rows = collect($this->withHeaders($this->authHeaders($dir['token']))->getJson('/api/v1/finance/parttime-payroll?month=2026-04')->assertOk()->json('teachers'))->keyBy('teacher_id');
        $this->assertSame(500, $rows[$tid]['admin_bonus']);
        $this->assertSame(1300, $rows[$tid]['total_salary']); // 800 + 300 + 200
        $this->assertSame(400, $rows[$only]['total_salary']);
    }

    public function test_admin_bonus_other_campus_is_forbidden(): void
    {
        $dir = $this->createDirector('dir-ab-2@test.com', [1]);
        $tid = $this->createPartTimeTeacher(2, 'ab2@test.com', '他校');
        $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson($this->bonusUrl(), ['branch_id' => 2, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 100, 'reason' => 'x'])
            ->assertStatus(403);
    }

    public function test_admin_bonus_requires_reason_and_bounds(): void
    {
        $dir = $this->createDirector('dir-ab-3@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ab3@test.com', '乙');
        $h = $this->authHeaders($dir['token']);
        $base = ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 100];
        $this->withHeaders($h)->putJson($this->bonusUrl(), $base)->assertStatus(422);
        $this->withHeaders($h)->putJson($this->bonusUrl(), $base + ['reason' => '  '])->assertStatus(422);
        $this->withHeaders($h)->putJson($this->bonusUrl(), array_merge($base, ['reason' => 'x', 'amount' => 100001]))->assertStatus(422);
        $this->assertSame(0, DB::table('parttime_payroll_admin_bonuses')->count());
    }

    public function test_admin_bonus_refused_when_month_locked(): void
    {
        $dir = $this->createDirector('dir-ab-4@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ab4@test.com', '丙');
        $h = $this->authHeaders($dir['token']);
        $body = ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 300, 'reason' => '行政'];
        $this->withHeaders($h)->putJson($this->bonusUrl(), $body)->assertOk();
        PayrollMonthStatus::updateOrCreate(['branch_id' => 1, 'month' => '2026-04'], ['status' => 'locked']);

        $this->withHeaders($h)->putJson($this->bonusUrl(), array_merge($body, ['amount' => 900]))->assertStatus(422);
        $this->withHeaders($h)->deleteJson($this->bonusUrl(), ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04'])->assertStatus(422);
        $this->assertSame(300, (int) DB::table('parttime_payroll_admin_bonuses')->value('amount'));
    }

    public function test_admin_bonus_summary_and_export_reject_other_campus_branch_id(): void
    {
        $dir = $this->createDirector('dir-ab-5@test.com', [1]);
        $h = $this->authHeaders($dir['token']);
        DB::table('parttime_payroll_admin_bonuses')->insert([
            'campus_id' => 2, 'teacher_id' => 999, 'month' => '2026-04', 'amount' => 500, 'reason' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withHeaders($h)->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=2')->assertStatus(403);
        $this->withHeaders($h)->get('/api/v1/finance/parttime-payroll/export?month=2026-04&branch_id=2')->assertStatus(403);
        $this->withHeaders($h)->getJson('/api/v1/finance/parttime-payroll/999/sessions?month=2026-04&branch_id=2')->assertStatus(403);
        $this->withHeaders($h)->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 2])->assertStatus(403);
        $this->assertSame(0, DB::table('payroll_month_status')->where('branch_id', 2)->count());
    }

    public function test_admin_bonus_reason_is_formula_safe_in_csv(): void
    {
        $dir = $this->createDirector('dir-ab-6@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ab6@test.com', '丁');
        $h = $this->authHeaders($dir['token']);
        $this->withHeaders($h)->putJson($this->bonusUrl(), ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 1, 'reason' => '=HYPERLINK("x")'])->assertOk();
        $csv = $this->withHeaders($h)->get('/api/v1/finance/parttime-payroll/export?month=2026-04&branch_id=1')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function test_every_text_cell_in_the_payroll_csv_is_formula_safe(): void
    {
        $dir = $this->createDirector('dir-ab-7@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ab7@test.com', '=SUM(A1)');
        $sc = $this->makeStudentClass($this->createStudent(1, '@cmd'), $tid, 9, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-10', '17:00', '19:00');
        $csv = $this->withHeaders($this->authHeaders($dir['token']))->get('/api/v1/finance/parttime-payroll/export?month=2026-04&branch_id=1')->streamedContent();
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->assertStringContainsString("'@cmd", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?[=@]/m', $csv);
    }

    public function test_admin_bonus_cannot_be_set_for_oneself(): void
    {
        $dir = $this->createDirector('dir-ab-7@test.com', [1]);
        $h = $this->authHeaders($dir['token']);
        $b = ['branch_id' => 1, 'teacher_id' => $dir['user_id'], 'month' => '2026-04'];
        $this->withHeaders($h)->putJson($this->bonusUrl(), $b + ['amount' => 100, 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');
        $this->withHeaders($h)->deleteJson($this->bonusUrl(), $b)->assertStatus(422);
        $this->assertSame(0, DB::table('parttime_payroll_admin_bonuses')->count());
    }

    public function test_admin_bonus_write_rolls_back_when_audit_fails(): void
    {
        $dir = $this->createDirector('dir-ab-8@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ab8@test.com', '戊');
        DB::listen(function ($q) {
            if (str_contains($q->sql, 'security_audit_events') && str_starts_with(strtolower($q->sql), 'insert')) {
                throw new \RuntimeException('audit down');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->withHeaders($this->authHeaders($dir['token']))
                ->putJson($this->bonusUrl(), ['branch_id' => 1, 'teacher_id' => $tid, 'month' => '2026-04', 'amount' => 100, 'reason' => 'x']);
            $this->fail('expected audit failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }
        $this->assertSame(0, DB::table('parttime_payroll_admin_bonuses')->count());
    }
}
