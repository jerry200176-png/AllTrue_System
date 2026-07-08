<?php

namespace Tests\Feature;

use App\Exports\StudentClassesExport;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema-drift regression: StudentClass.RoomID 已於 2026-06-30 在 production 被
 * migration 移除（batch 107/108），程式碼不得再讀寫該欄位，否則 SQL 42S22 直接 500。
 * 本測試把「CI schema == production schema」鎖進回歸：任何人重新引入 RoomID
 * 讀寫（query builder insert / explicit select / validation 寫入）都會在這裡爆。
 */
class StudentClassRoomIdSchemaDriftTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_id_legacy_column_is_dropped(): void
    {
        $this->assertFalse(
            Schema::hasColumn('StudentClass', 'RoomID'),
            'StudentClass.RoomID 應已被 2026_06_30 migration 移除（與 production 對齊）'
        );
        $this->assertTrue(
            Schema::hasColumn('StudentClass', 'room_id'),
            'room_id（rooms FK）是唯一合法的教室欄位'
        );
    }

    public function test_legacy_room_id_key_in_create_payload_is_discarded_not_fatal(): void
    {
        $studentClass = StudentClass::create([
            'StudentID'         => 1,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => 1,
            'by1'               => 1,
            'TotalHours'        => 10,
            'StartDate'         => now(),
            'RemainingSessions' => 5,
            'SessionCount'      => 5,
            // 舊呼叫端可能仍夾帶 RoomID key；不在 fillable 內必須被靜默丟棄而非 SQL 錯誤
            'RoomID'            => '1',
        ]);

        $this->assertNotNull($studentClass->ID);
        $this->assertDatabaseHas('StudentClass', ['ID' => $studentClass->ID]);
    }

    public function test_student_classes_export_query_matches_live_schema(): void
    {
        StudentClass::create([
            'StudentID'         => 1,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => 1,
            'by1'               => 1,
            'TotalHours'        => 10,
            'StartDate'         => now(),
            'RemainingSessions' => 5,
            'SessionCount'      => 5,
        ]);

        $export = new StudentClassesExport();

        // 明確欄位 SELECT 含已移除欄位時這裡會丟 QueryException（匯出 500 的回歸）
        $collection = $export->collection();

        $this->assertGreaterThan(0, $collection->count());
        $this->assertSame(
            count($export->headings()),
            count($collection->first()->getAttributes()),
            'Export SELECT 欄位數必須與 headings 對齊，避免 CSV 欄位錯位'
        );
    }
}
