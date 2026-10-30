<?php

namespace Tests\Feature;

use App\Services\Billing\OrdinaryCloseActivityCensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class OrdinaryCloseActivityCensusTest extends TestCase
{
    use RefreshDatabase;

    public function test_census_keeps_all_owned_activity_sources_private_and_table_namespaced(): void
    {
        [$student, $course] = $this->owner();
        $session = $this->makeLessonSession($course);
        $keeperSession = $this->makeLessonSession($course);
        $otherStudent = $this->student(2);
        $otherCourse = $this->course($otherStudent);
        $otherSession = $this->makeLessonSession($otherCourse);

        $ledger = DB::table('session_deduction_ledger')->insertGetId([
            'student_class_id' => $course, 'class_session_id' => $session, 'event_type' => 'deduct',
            'source' => 'attendance', 'note' => 'PRIVATE_DEDUCTION_NOTE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('StudentSingIn')->insert([
            'StudentClassID' => $course, 'StudentID' => $student, 'SignInDT' => now(),
            'ClassSessionID' => $session, 'Status' => 'voided', 'Memo' => 'PRIVATE_SIGNIN_NOTE',
        ]);
        DB::table('StudentSingIn')->insert([
            'StudentClassID' => $course, 'StudentID' => $student, 'SignInDT' => now(),
            'ClassSessionID' => null, 'Status' => 'leave', 'Memo' => 'PRIVATE_ORPHAN_NOTE',
        ]);
        $preservedRecord = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $course, 'ClassSessionID' => $session, 'StudentID' => $student,
            'TeacherID' => 1, 'Content' => 'PRIVATE_LEARNING_CONTENT', 'Status' => 'voided',
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            DB::table('LearningRecord')->insert([
                'StudentClassID' => $course, 'ClassSessionID' => 0, 'StudentID' => $student,
                'TeacherID' => 1, 'Content' => 'PRIVATE_UNBOUND_CONTENT', 'Status' => 'pending',
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
        $keeperRecord = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $course, 'ClassSessionID' => $keeperSession, 'StudentID' => $student,
            'TeacherID' => 1, 'Content' => 'PRIVATE_KEEPER_CONTENT', 'Status' => 'approved',
        ]);
        $correction = DB::table('session_corrections')->insertGetId([
            'session_id' => $session, 'replaced_by_session_id' => $keeperSession,
            'correction_reason' => 'duplicate', 'decision_reference' => 'synthetic-test', 'decided_at' => now(),
            'previous_status' => 'attended', 'new_status' => 'superseded',
            'preserved_learning_record_id' => $preservedRecord, 'keeper_learning_record_id' => $keeperRecord,
            'snapshot_before' => json_encode(['private' => 'PRIVATE_SNAPSHOT']),
        ]);
        $cancelCorrection = DB::table('session_corrections')->insertGetId([
            'session_id' => $keeperSession, 'replaced_by_session_id' => null,
            'correction_reason' => 'cancel', 'decision_reference' => 'synthetic-cancel', 'decided_at' => now(),
            'previous_status' => 'attended', 'new_status' => 'cancelled',
        ]);
        $schedule = DB::table('schedules')->insertGetId([
            'student_id' => $student, 'student_course_id' => $course, 'branch_id' => 1,
            'day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'superseded',
            'type' => 'normal', 'deduction' => 1,
        ]);
        $scheduleChild = DB::table('schedules')->insertGetId([
            'student_id' => $student, 'student_course_id' => $course, 'branch_id' => 1,
            'day_of_week' => 3, 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'leave',
            'type' => 'normal', 'deduction' => 0, 'original_schedule_id' => $schedule,
        ]);
        $group = DB::table('course_contract_groups')->insertGetId([
            'student_id' => $student, 'campus_id' => 1, 'subject_id' => 1, 'label' => 'PRIVATE_GROUP_LABEL',
        ]);
        DB::table('course_contract_group_members')->insert([
            'group_id' => $group, 'student_class_id' => $course, 'relation_type' => 'original',
            'decision_reason' => 'PRIVATE_GROUP_REASON',
        ]);
        $successor = $this->course($student, ['Stop' => 1, 'SubjectID' => 9]);
        DB::table('course_contract_group_members')->insert([
            'group_id' => $group, 'student_class_id' => $successor, 'relation_type' => 'renewal',
            'unlinked_at' => now(), 'decision_reason' => 'PRIVATE_UNLINK_REASON',
        ]);

        $before = [];
        foreach (['StudentClass', 'ClassSession', 'session_deduction_ledger', 'StudentSingIn', 'LearningRecord',
            'schedules', 'session_corrections', 'course_contract_groups', 'course_contract_group_members'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        DB::beginTransaction();
        try {
            $result = app(OrdinaryCloseActivityCensus::class)->captureLocked($course, $student);
            $this->assertSame([$schedule, $scheduleChild], $result['id_sets']['schedules']);
            $this->assertSame([$ledger], $result['id_sets']['deduction_events']);
            $this->assertSame([$correction, $cancelCorrection], $result['id_sets']['session_corrections']);
            $this->assertSame([$successor], $result['id_sets']['successor_courses']);
            $this->assertCount(2, $result['id_sets']['group_memberships']);
            $this->assertCount(2, $result['id_sets']['sign_ins']);
            $this->assertCount(3, $result['id_sets']['learning_records']);
            $this->assertSame([$session, $keeperSession], $result['context_ids']['session_ids']);
            $this->assertNotContains($otherSession, $result['context_ids']['session_ids']);
            $this->assertSame('partial_activity', $result['manifest_scope']);
            $this->assertArrayNotHasKey('guard_digest', $result);
            $this->assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
            $raw = DB::table('session_deduction_ledger')->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
            $canonical = json_encode([
                'domain' => 'alltrue-ordinary-close-activity-v1|', 'table' => 'session_deduction_ledger',
                'role' => 'deduction_events', 'columns' => array_keys($raw[0]), 'rows' => $raw,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $key = base64_decode(substr((string) config('app.key'), 7), true);
            $this->assertSame(hash_hmac('sha256', $canonical, $key), $result['table_digests']['deduction_events']);
            $this->assertNotSame(hash('sha256', $canonical), $result['table_digests']['deduction_events']);
            foreach ($before as $table => $count) $this->assertSame($count, DB::table($table)->count(), $table.' is read-only');
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        DB::table('session_corrections')->where('id', $correction)
            ->update(['keeper_learning_record_id' => $preservedRecord]);
        try {
            $this->capture($course, $student);
            $this->fail('same-course learning record on the wrong endpoint was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity correction learning binding unsupported.', $e->getMessage());
        }
        DB::table('session_corrections')->where('id', $correction)
            ->update(['keeper_learning_record_id' => $keeperRecord]);
        $scheduleDigest = $result['table_digests']['schedules'];
        DB::table('schedules')->where('id', $scheduleChild)->update(['original_schedule_id' => null]);
        $changedSchedule = $this->capture($course, $student);
        $this->assertNotSame($scheduleDigest, $changedSchedule['table_digests']['schedules']);
        $digest = $result['table_digests']['session_corrections'];
        DB::table('session_corrections')->where('id', $correction)->update(['rolled_back_at' => now()]);
        $changed = $this->capture($course, $student);
        $this->assertNotSame($digest, $changed['table_digests']['session_corrections']);
    }

    public function test_reverse_ledger_event_changes_digest_even_when_net_effect_is_zero(): void
    {
        [$student, $course] = $this->owner();
        $session = $this->makeLessonSession($course);
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $course, 'class_session_id' => $session, 'event_type' => 'deduct',
            'source' => 'attendance', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $this->capture($course, $student);
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $course, 'class_session_id' => $session, 'event_type' => 'reverse',
            'source' => 'retro_leave', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $second = $this->capture($course, $student);
        $this->assertNotSame($first['table_digests']['deduction_events'], $second['table_digests']['deduction_events']);
        $this->assertCount(2, $second['id_sets']['deduction_events']);
    }

    public function test_foreign_session_link_and_schedule_cycle_fail_closed(): void
    {
        [$student, $course] = $this->owner();
        $foreignStudent = $this->student(2);
        $foreignCourse = $this->course($foreignStudent);
        $foreignSession = $this->makeLessonSession($foreignCourse);
        DB::table('StudentSingIn')->insert([
            'StudentClassID' => $course, 'StudentID' => $student, 'SignInDT' => now(),
            'ClassSessionID' => $foreignSession, 'Status' => 'present',
        ]);
        try {
            $this->capture($course, $student);
            $this->fail('foreign session link was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity session link unsupported.', $e->getMessage());
        }

        DB::table('StudentSingIn')->delete();
        DB::table('StudentSingIn')->insert([
            'StudentClassID' => $course, 'StudentID' => $foreignStudent, 'SignInDT' => now(),
            'ClassSessionID' => null, 'Status' => 'present',
        ]);
        try {
            $this->capture($course, $student);
            $this->fail('foreign student attendance row was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity attendance binding changed.', $e->getMessage());
        }
        DB::table('StudentSingIn')->delete();
        $ownedSession = $this->makeLessonSession($course);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $course, 'ClassSessionID' => $ownedSession, 'StudentID' => $foreignStudent,
            'TeacherID' => 1, 'Content' => 'PRIVATE_CONTRADICTORY_RECORD', 'Status' => 'pending',
        ]);
        try {
            $this->capture($course, $student);
            $this->fail('foreign student learning record was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity learning binding changed.', $e->getMessage());
        }
        DB::table('LearningRecord')->delete();
        $a = DB::table('schedules')->insertGetId([
            'student_id' => $student, 'student_course_id' => $course, 'branch_id' => 1,
            'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:00', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 1,
        ]);
        $b = DB::table('schedules')->insertGetId([
            'student_id' => $student, 'student_course_id' => $course, 'branch_id' => 1,
            'day_of_week' => 2, 'start_time' => '12:00', 'end_time' => '13:00', 'status' => 'leave',
            'type' => 'normal', 'deduction' => 1, 'original_schedule_id' => $a,
        ]);
        DB::table('schedules')->where('id', $a)->update(['original_schedule_id' => $b]);
        try {
            $this->capture($course, $student);
            $this->fail('cyclic schedule lineage was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity schedule cycle.', $e->getMessage());
        }
        DB::table('schedules')->delete();
        $anchor = DB::table('schedules')->insertGetId([
            'student_id' => $foreignStudent, 'student_course_id' => $foreignCourse, 'branch_id' => 1,
            'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:00', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 1,
        ]);
        DB::table('schedules')->insert([
            'student_id' => $student, 'student_course_id' => $course, 'branch_id' => 1,
            'day_of_week' => 2, 'start_time' => '12:00', 'end_time' => '13:00', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 1, 'original_schedule_id' => $anchor,
        ]);
        try {
            $this->capture($course, $student);
            $this->fail('foreign schedule anchor was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity schedule lineage unsupported.', $e->getMessage());
        }
    }

    public function test_group_with_foreign_member_and_global_fact_budget_are_refused(): void
    {
        [$student, $course] = $this->owner();
        $foreign = $this->course($this->student(2));
        $group = DB::table('course_contract_groups')->insertGetId(['student_id' => $student, 'campus_id' => 1]);
        DB::table('course_contract_group_members')->insert([
            ['group_id' => $group, 'student_class_id' => $course, 'relation_type' => 'original'],
            ['group_id' => $group, 'student_class_id' => $foreign, 'relation_type' => 'parallel'],
        ]);
        try {
            $this->capture($course, $student);
            $this->fail('foreign group member was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity group member unsupported.', $e->getMessage());
        }
        DB::table('course_contract_group_members')->delete();
        DB::table('course_contract_groups')->delete();

        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $course, 'class_session_id' => null, 'event_type' => 'reverse',
            'source' => 'retro_leave', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('schedules')->insert([
            'student_id' => $student, 'student_course_id' => $course, 'branch_id' => 1,
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 1,
        ]);
        $this->assertSame(2, $this->capture($course, $student, 2)['fact_count']);
        DB::beginTransaction();
        try {
            try {
                app(OrdinaryCloseActivityCensus::class)->captureLocked($course, $student, 1);
                $this->fail('over-budget census was accepted');
            } catch (\RuntimeException $e) {
                $this->assertSame('Ordinary-close activity fact budget exceeded.', $e->getMessage());
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_requires_transaction(): void
    {
        [$student, $course] = $this->owner();
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);
        try {
            app(OrdinaryCloseActivityCensus::class)->captureLocked($course, $student);
            $this->fail('transactionless capture was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity census unavailable.', $e->getMessage());
        }
    }

    public function test_database_query_failure_is_rethrown_without_sql_details(): void
    {
        [$student, $course] = $this->owner();
        DB::shouldReceive('transactionLevel')->once()->andThrow(
            new QueryException('mysql', 'select * from Student where Phone = ?', ['PRIVATE_PHONE'], new PDOException('PRIVATE_SQL_DETAIL'))
        );
        try {
            app(OrdinaryCloseActivityCensus::class)->captureLocked($course, $student);
            $this->fail('schema failure was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity source unavailable.', $e->getMessage());
            $this->assertStringNotContainsString('PRIVATE_', $e->getMessage());
        }
    }

    public function test_core_owned_session_context_does_not_spend_activity_fact_budget(): void
    {
        [$student, $course] = $this->owner();
        $session = $this->makeLessonSession($course);
        $result = $this->capture($course, $student, 0);
        $this->assertSame([$session], $result['context_ids']['session_ids']);
        $this->assertSame(0, $result['fact_count']);
    }

    public function test_unrepresentable_bigint_ids_refuse_without_aliasing_rows(): void
    {
        [$student, $course] = $this->owner();
        $database = str_replace('`', '``', DB::connection()->getDatabaseName());
        DB::statement('CREATE TEMPORARY TABLE `StudentSingIn` AS SELECT * FROM '
            .'(SELECT * FROM `'.$database.'`.`StudentSingIn`) AS `source_row` WHERE 1 = 0');
        try {
            foreach (['9223372036854775808', '9223372036854775809'] as $id) {
                DB::table('StudentSingIn')->insert([
                    'id' => $id, 'StudentClassID' => $course, 'StudentID' => $student,
                    'SignInDT' => now(), 'MDT' => now(), 'ClassSessionID' => null, 'Status' => 'present',
                    'CampusID' => 1, 'PersonType' => 'student', 'SessionDeducted' => 0,
                ]);
            }
            try {
                $this->capture($course, $student);
                $this->fail('unrepresentable BIGINT identities were normalized');
            } catch (\RuntimeException $e) {
                $this->assertSame('Ordinary-close activity identity unsupported.', $e->getMessage());
            }
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `StudentSingIn`');
        }
    }

    public function test_unrelated_student_activity_is_not_in_the_manifest(): void
    {
        [$student, $course] = $this->owner();
        $foreign = $this->course($this->student(2));
        DB::table('schedules')->insert([
            'student_id' => $student + 1000, 'student_course_id' => $foreign, 'branch_id' => 1,
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 1,
        ]);
        $result = $this->capture($course, $student);
        $this->assertSame([], $result['id_sets']['schedules']);
        $this->assertContains('session_corrections', $result['captured_sources']);
        $this->assertSame([], $result['id_sets']['session_corrections']);
    }

    public function test_trial_conversion_cycles_are_refused_after_a_valid_baseline(): void
    {
        [$student, $course] = $this->owner();
        $successor = $this->course($student, ['Stop' => 1, 'SubjectID' => 9]);
        $baseline = $this->capture($course, $student);
        $this->assertSame([$successor], $baseline['id_sets']['successor_courses']);
        DB::table('StudentClass')->where('ID', $course)->update(['trial_converted_to_id' => $successor]);
        DB::table('StudentClass')->where('ID', $successor)->update(['trial_converted_to_id' => $course]);
        try {
            $this->capture($course, $student);
            $this->fail('cyclic continuation pointers were accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ordinary-close activity continuation cycle.', $e->getMessage());
        }
    }

    private function capture(int $course, int $student, int $budget = 1000): array
    {
        DB::beginTransaction();
        try {
            $result = app(OrdinaryCloseActivityCensus::class)->captureLocked($course, $student, $budget);
            DB::commit();
            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function owner(): array
    {
        $student = $this->student(1);
        return [$student, $this->course($student)];
    }
    private function student(int $campus): int
    {
        return (int) DB::table('Student')->insertGetId(['name' => 'Census', 'CampusID' => $campus, 'ClassID' => 1]);
    }
    private function course(int $student, array $extra = []): int
    {
        return (int) DB::table('StudentClass')->insertGetId(array_merge([
            'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1,
            'Period' => 4, 'StartDate' => '2026-01-01 00:00:00', 'TotalHours' => 0, 'room_id' => null,
            'ClassType' => 'one_on_one', 'Stop' => 0, 'ScheduleMode' => 'count',
        ], $extra));
    }
    private function makeLessonSession(int $course): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $course, 'SessionDate' => '2026-10-01', 'StartTime' => '09:00:00',
            'EndTime' => '10:00:00', 'Status' => 'attended', 'Note' => 'PRIVATE_SESSION_NOTE',
        ]);
    }
}
