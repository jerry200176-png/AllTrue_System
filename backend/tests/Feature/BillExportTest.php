<?php

namespace Tests\Feature;

use App\Exports\BillListExport;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Http\Controllers\BillExportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** 帳務中心 Excel export (in-app #520 / #5099): same rows and checks as /alerts/tuition, only the rows the list shows. */
class BillExportTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $campusIds, string $type = 'A'): string
    {
        $user = User::create(['LoginName' => 'u' . uniqid() . '@example.com', 'Name' => '測試', 'PSW' => 'secret', 'type' => $type, 'phone' => 912345678, 'MustChangePassword' => false]);
        foreach ($campusIds as $c) {
            UserCampus::create(['CampusID' => $c, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function owing(string $name, int $campus = 1): StudentClass
    {
        $s = Student::create(['name' => $name, 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4, 'StartDate' => now(), 'TotalHours' => 20,
            'Charge' => 5000, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    private function export(string $token, array $body)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->postJson('/api/v1/accounting/bills/export', $body);
    }

    private function body(array $rows, array $extra = []): array
    {
        return $extra + ['branch_id' => 1, 'filter' => 'owing', 'search' => '', 'rows' => $rows];
    }

    public function test_exports_only_the_rows_the_list_shows_with_plain_headers_and_audit(): void
    {
        Campus::firstOrCreate(['id' => 1], ['name' => '測試', 'code' => 'daan', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '']);
        $shown = $this->owing('顯示甲');
        $this->owing('被篩掉乙');
        Excel::fake();

        $this->export($this->token([1]), $this->body([['id' => $shown->ID, 'status' => '未繳', 'period' => '剩 5 堂', 'due' => '—', 'owed' => 5000]], ['search' => '顯示']))->assertOk();

        Excel::assertDownloaded('帳務-daan-' . now('Asia/Taipei')->format('Ymd') . '.xlsx', function (BillListExport $e) {
            $this->assertSame(['學生', '科目', '期間／剩幾堂', '狀態', '到期／逾期', '應收', '已收', '未收'], $e->headings());
            $this->assertCount(1, $e->array());
            $this->assertSame('顯示甲', $e->array()[0][0]);
            $this->assertSame(5000, $e->array()[0][7]);

            return true;
        });
        $row = DB::table('security_audit_events')->where('event_type', 'billing.export')->first();
        $meta = json_decode($row->metadata, true);
        $this->assertSame(1, $meta['row_count']);
        $this->assertSame('owing', $meta['filter']);
        $this->assertTrue($meta['has_search']);
        $this->assertStringNotContainsString('顯示', (string) $row->metadata);
    }

    public function test_formula_looking_text_is_escaped(): void
    {
        $this->assertSame("'=HYPERLINK(1)", BillExportController::safeCell('=HYPERLINK(1)'));
        $this->assertSame("'-1", BillExportController::safeCell('-1'));
        $this->assertSame('王小明', BillExportController::safeCell('王小明'));
        $this->assertSame(5000, BillExportController::safeCell(5000));
    }

    public function test_a_row_of_another_campus_is_never_exported(): void
    {
        $other = $this->owing('他校丙', 2);
        Excel::fake();

        $this->export($this->token([1]), $this->body([['id' => $other->ID, 'status' => '未繳', 'owed' => 5000]]))->assertNotFound();
    }

    public function test_teacher_and_other_campus_are_refused(): void
    {
        $row = $this->owing('甲');
        $body = $this->body([['id' => $row->ID]]);

        $this->export($this->token([1], 'T'), $body)->assertForbidden();
        $this->export($this->token([2]), $body)->assertForbidden();
    }

    public function test_over_the_cap_gets_a_clear_message(): void
    {
        $rows = array_fill(0, BillExportController::CAP + 1, ['id' => 1]);

        $res = $this->export($this->token([1]), $this->body($rows))->assertStatus(422);
        $this->assertStringContainsString('先用篩選縮小範圍', $res->json('message'));
    }
}
