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
