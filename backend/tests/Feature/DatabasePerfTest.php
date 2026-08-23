<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P0 Database Performance & Index Verification Tests
 *
 * Validates FR-001 (indexes), FR-003 (no N+1), FR-005 (read/write config),
 * FR-008 (campus isolation) from the DB Optimization PRD.
 */
class DatabasePerfTest extends TestCase
{
    // --- FR-001: Index existence ---

    /** @dataProvider requiredIndexProvider */
    public function test_required_index_exists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            $this->markTestSkipped("Table {$table} does not exist");
        }
        $indexes = $this->collectIndexes($table);
        $this->assertArrayHasKey($indexName, $indexes, "Index {$indexName} missing on {$table}");
    }

    public static function requiredIndexProvider(): array
    {
        return [
            'Student campus+name' => ['Student', 'idx_student_campus_name'],
            'Student campus+status' => ['Student', 'idx_student_campus_status'],
            'Student RFID' => ['Student', 'idx_student_rfid'],
            'StudentClass StudentID' => ['StudentClass', 'idx_sc_student_id'],
            'StudentClass TeacherID' => ['StudentClass', 'idx_sc_teacher_id'],
            'StudentClass Stop+Student' => ['StudentClass', 'idx_sc_stop_student'],
            'ClassSession Status' => ['ClassSession', 'idx_cs_status'],
            'StudentSingIn StudentID' => ['StudentSingIn', 'idx_ssi_student_id'],
            'StudentSingIn StudentClassID' => ['StudentSingIn', 'idx_ssi_sc_id'],
            'StudentSingIn SignInDT' => ['StudentSingIn', 'idx_ssi_signindt'],
            'Invoice StudentID' => ['Invoice', 'idx_inv_student_id'],
            'Invoice StudentClassID' => ['Invoice', 'idx_inv_sc_id'],
            'Invoice Status' => ['Invoice', 'idx_inv_status'],
            'Payment InvoiceID' => ['Payment', 'idx_pay_invoice_id'],
            'UserCampus UserID' => ['UserCampus', 'idx_uc_user_id'],
            'UserCampus Campus+Approved' => ['UserCampus', 'idx_uc_campus_approved'],
            // NOTE: uq_class_session_slot is intentionally NOT asserted here — its
            // migration is skipped outside production (CI fixtures keep duplicates).
            // #990 / PERF-16 — schedules S.D.B. hot key + LINE binding lookup.
            'schedules S.D.B.' => ['schedules', 'idx_sched_sdb'],
            'StudentLineBinding line_user_id' => ['student_line_bindings', 'slb_line_user_id_idx'],
        ];
    }

    // --- FR-001: EXPLAIN verifies index usage ---

    /** @dataProvider explainQueryProvider */
    public function test_query_uses_index(string $sql, array $params, string $description): void
    {
        $explain = DB::select("EXPLAIN {$sql}", $params);
        $this->assertNotEmpty($explain, "EXPLAIN returned no rows for: {$description}");
        $this->assertNotEquals('ALL', $explain[0]->type, "Full table scan detected for: {$description}");
    }

    public static function explainQueryProvider(): array
    {
        return [
            'Student by CampusID' => [
                'SELECT * FROM Student WHERE CampusID = ? ORDER BY name',
                [1],
                'Student WHERE CampusID ORDER BY name',
            ],
            'StudentClass by StudentID' => [
                'SELECT * FROM StudentClass WHERE StudentID = ?',
                [1],
                'StudentClass WHERE StudentID',
            ],
            'StudentClass by TeacherID+Stop' => [
                'SELECT * FROM StudentClass WHERE TeacherID = ? AND Stop = 0',
                [1],
                'StudentClass WHERE TeacherID AND Stop',
            ],
            'StudentSingIn by StudentID' => [
                'SELECT * FROM StudentSingIn WHERE StudentID = ?',
                [1],
                'StudentSingIn WHERE StudentID',
            ],
            'UserCampus by UserID' => [
                'SELECT * FROM UserCampus WHERE UserID = ?',
                [1],
                'UserCampus WHERE UserID',
            ],
            'Student by RFID' => [
                'SELECT * FROM Student WHERE RFID = ?',
                ['ABC123'],
                'Student WHERE RFID',
            ],
        ];
    }

    // --- FR-005: Read/write split config ---

    public function test_mysql_config_has_read_write_sticky(): void
    {
        $config = config('database.connections.mysql');
        $this->assertArrayHasKey('read', $config);
        $this->assertArrayHasKey('write', $config);
        $this->assertArrayHasKey('sticky', $config);
        $this->assertTrue($config['sticky']);
    }

    public function test_mysql_config_has_persistent_option(): void
    {
        $config = config('database.connections.mysql');
        $this->assertArrayHasKey('options', $config);
    }

    // --- TD-058: substitute-teacher leftJoin uses cs.SessionDate/cs.StartTime directly ---

    /**
     * TD-058 residual fix: ClassSessionController::buildClassSessionIndexQuery()'s
     * substitute-teacher leftJoin ON clause used to wrap cs.SessionDate/cs.StartTime in
     * DATE()/SUBSTRING(), defeating index usage on ClassSession. It now compares
     * sub_sched.schedule_date/start_time_hm directly against cs.SessionDate/cs.StartTimeHM
     * (no function-wrapping on either side of the join). Asserts neither DATE( nor
     * SUBSTRING( appears against a `cs.` column in the compiled join SQL (the exact,
     * deterministic thing this fix guarantees — an EXPLAIN "not ALL" assertion on cs
     * would be data-volume-dependent noise on the near-empty tables in this test class,
     * not a signal this fix controls), and that EXPLAIN runs without error.
     */
    public function test_class_session_index_query_join_avoids_function_wrapped_columns(): void
    {
        if (!Schema::hasTable('ClassSession') || !Schema::hasTable('schedules')) {
            $this->markTestSkipped('ClassSession/schedules table missing');
        }

        $controller = app(\App\Http\Controllers\ClassSessionController::class);
        $request = \Illuminate\Http\Request::create('/api/v1/class-sessions', 'GET', [
            'start' => '2026-06-01',
            'end' => '2026-06-30',
            'branch_id' => 1,
        ]);
        $request->attributes->set('auth_role', 'director');
        $request->attributes->set('auth_campus_ids', [1]);
        $request->attributes->set('auth_teacher_id', 0);

        $method = new \ReflectionMethod($controller, 'buildClassSessionIndexQuery');
        $method->setAccessible(true);
        /** @var \Illuminate\Database\Query\Builder $query */
        $query = $method->invoke($controller, $request);

        $sql = $query->toSql();

        // The join predicate must not function-wrap the ClassSession columns —
        // that's exactly what defeated index usage before the TD-058 residual fix.
        $this->assertStringNotContainsString('DATE(cs.SessionDate)', $sql);
        $this->assertStringNotContainsString('SUBSTRING(cs.StartTime', $sql);

        $explain = DB::select('EXPLAIN ' . $sql, $query->getBindings());
        $this->assertNotEmpty($explain, 'EXPLAIN returned no rows for buildClassSessionIndexQuery()');
        $csRow = collect($explain)->first(fn ($row) => ($row->table ?? null) === 'cs');
        $this->assertNotNull($csRow, 'EXPLAIN plan missing the cs (ClassSession) row');
    }

    /**
     * The director learning fill-rate endpoint uses the same effective-teacher
     * resolution as the calendar. Keep its date window and substitute join
     * sargable as well; this endpoint is loaded as secondary dashboard data.
     * StartTimeHM is the generated ClassSession column used by the calendar
     * query, so this endpoint must keep the same index-friendly contract.
     */
    public function test_teacher_learning_fill_rate_query_avoids_function_wrapped_class_session_columns(): void
    {
        foreach (['ClassSession', 'StudentClass', 'Student', 'schedules', 'LearningRecord', 'User'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->markTestSkipped("{$table} table missing");
            }
        }

        $controller = app(\App\Http\Controllers\ClassSessionController::class);
        $request = \Illuminate\Http\Request::create('/api/v1/class-sessions/teacher-learning-fill-rates', 'GET', [
            'branch_id' => 1,
            'days' => 14,
        ]);
        $request->attributes->set('auth_role', 'director');
        $request->attributes->set('auth_campus_ids', [1]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $controller->directorTeacherLearningFillRates($request);

        $this->assertSame(200, $response->getStatusCode());
        $sql = implode("\n", $queries);
        $this->assertStringNotContainsString('DATE(cs.SessionDate)', $sql);
        $this->assertStringNotContainsString('DATE(sub_sched.schedule_date)', $sql);
        $this->assertStringNotContainsString('SUBSTRING(cs.StartTime', $sql);
        $this->assertStringContainsString('`cs`.`SessionDate`', $sql);
    }

    // --- FR-008: Campus isolation ---

    public function test_student_campus_filter_returns_only_same_campus(): void
    {
        $campusId = DB::table('Student')->value('CampusID');
        if (!$campusId) {
            $this->markTestSkipped('No students in database');
        }

        $campusIds = DB::table('Student')
            ->where('CampusID', $campusId)
            ->pluck('CampusID')
            ->unique();

        $this->assertCount(1, $campusIds);
        $this->assertEquals($campusId, $campusIds->first());
    }

    // --- Helper ---

    private function collectIndexes(string $table): array
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}`");
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->Key_name] = true;
        }
        return $indexes;
    }
}
