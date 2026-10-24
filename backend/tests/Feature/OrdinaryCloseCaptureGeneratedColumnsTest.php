<?php

namespace Tests\Feature;

use App\Services\Billing\OrdinaryCloseCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrdinaryCloseCaptureGeneratedColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** Generated-column DDL is not allowed inside RefreshDatabase's transaction. */
    protected array $connectionsToTransact = [];

    public function test_capture_requires_a_caller_owned_transaction(): void
    {
        $this->expectException(\RuntimeException::class);
        (new OrdinaryCloseCapture())->captureLocked(88036);
    }

    public function test_post_write_digest_uses_database_generated_active_slot_value(): void
    {
        $addedFlag = !Schema::hasColumn('ClassSession', 'ActiveSlotFlag');
        if ($addedFlag) DB::statement("ALTER TABLE ClassSession ADD COLUMN ActiveSlotFlag TINYINT GENERATED ALWAYS AS (CASE WHEN Status = 'cancelled' THEN NULL ELSE 1 END) STORED");
        $studentId = 88036;
        try {
            DB::table('Student')->insert(['id' => $studentId, 'name' => 'Generated capture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
            $course = (int) DB::table('StudentClass')->insertGetId([
                'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 1,
                'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 0, 'ClassType' => 'one_on_one',
                'StartDate' => now()->subMonth(), 'SessionCount' => 1, 'SessionDuration' => 60, 'RemainingSessions' => 0,
                'UsedSessions' => 1, 'Stop' => 0, 'ScheduleMode' => 'count', 'MDate' => now(),
            ]);
            $session = (int) DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $course, 'SessionDate' => '2026-10-29', 'StartTime' => '10:00:00', 'EndTime' => '11:00:00',
                'Status' => 'scheduled', 'Note' => 'tag', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $service = new OrdinaryCloseCapture();
            $capture = DB::transaction(fn () => $service->captureLocked($course));
            $this->assertSame($this->rowDigest('ClassSession', (array) DB::table('ClassSession')->where('id', $session)->first()), $capture['sessions'][$session]['row_digest']);
            $uuid = (string) Str::uuid();
            DB::beginTransaction();
            DB::table('StudentClass')->where('ID', $course)->update([
                'Stop' => 1, 'closed_reason' => 'completed', 'settlement_snapshot' => json_encode([
                    'kind' => 'ordinary_close_v1', 'close_uuid' => $uuid, 'version' => 1, 'invalidated' => false,
                ]), 'MDate' => now(),
            ]);
            DB::table('ClassSession')->where('id', $session)->update(['Status' => 'cancelled', 'Note' => 'tag [暫停取消]', 'updated_at' => now()]);
            $partial = $service->describeCloseLocked($capture, $uuid, 'completed', true, false, '2026-10-24');
            $row = DB::table('ClassSession')->where('id', $session)->first();
            $this->assertNull($row->ActiveSlotFlag);
            $this->assertSame($this->rowDigest('ClassSession', (array) $row), $partial['cancelled_sessions'][0]['after_row_digest']);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
            DB::table('ClassSession')->where('StudentClassID', DB::table('StudentClass')->where('StudentID', $studentId)->value('ID') ?? 0)->delete();
            DB::table('StudentClass')->where('StudentID', $studentId)->delete();
            DB::table('Student')->where('id', $studentId)->delete();
            if ($addedFlag && Schema::hasColumn('ClassSession', 'ActiveSlotFlag')) DB::statement('ALTER TABLE ClassSession DROP COLUMN ActiveSlotFlag');
        }
    }

    private function rowDigest(string $table, array $row): string
    {
        ksort($row, SORT_STRING);
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) $key = (string) base64_decode(substr($key, 7), true);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', 'alltrue-ordinary-close-capture-v1|'.$table.'|'.$json, $key);
    }
}
