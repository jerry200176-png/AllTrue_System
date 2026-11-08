<?php

namespace Tests\Feature;

use App\Exports\BillListExport;
use App\Models\AuthToken;
use App\Services\Billing\BillRowText;
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

        $this->export($this->token([1]), $this->body([['id' => $shown->ID]], ['search' => '顯示']))->assertOk();

        Excel::assertDownloaded('帳務-daan-' . now('Asia/Taipei')->format('Ymd') . '.xlsx', function (BillListExport $e) {
            $this->assertSame(['學生', '科目', '期間／剩幾堂', '狀態', '到期／逾期', '應收', '已收', '未收'], $e->headings());
            $this->assertCount(1, $e->array());
            $this->assertSame('顯示甲', $e->array()[0][0]);
            $this->assertSame('要開帳單', $e->array()[0][3]);
            $this->assertSame(0, $e->array()[0][7]);
            $this->assertSame($e->array()[0][6] + $e->array()[0][7], $e->array()[0][5]); // 應收 = 已收 + 未收

            return true;
        });
        $row = DB::table('security_audit_events')->where('event_type', 'billing.export')->first();
        $meta = json_decode($row->metadata, true);
        $this->assertSame(1, $meta['row_count']);
        $this->assertSame('owing', $meta['filter']);
        $this->assertTrue($meta['has_search']);
        $this->assertStringNotContainsString('顯示', (string) $row->metadata);
    }

    public function test_filter_metadata_preserves_ui_enums_and_masks_unknown_strings_without_changing_export(): void
    {
        Campus::firstOrCreate(['id' => 1], ['name' => '合成分校', 'code' => 'fixture', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '']);
        Campus::query()->whereKey(1)->update(['code' => 'fixture']);
        $shown = $this->owing('合成測試');
        $token = $this->token([1]);
        $before = $shown->fresh()->getAttributes();
        $cells = $headers = $responseHeaders = $responseHeaderNames = null;
        Excel::fake();

        foreach (['todo' => 'todo', 'confirm' => 'confirm', 'owing' => 'owing', 'issue' => 'issue', 'review' => 'review',
            'paid' => 'paid', 'waived' => 'waived', 'all' => 'all', 'overdue' => 'overdue',
            'audit@example.test' => 'other', '未定義篩選' => 'other', 'future-filter' => 'other', 'OWING' => 'other'] as $filter => $expected) {
            $count = DB::table('security_audit_events')->where('event_type', 'billing.export')->count();
            $res = $this->export($token, $this->body([['id' => $shown->ID]], ['filter' => $filter, 'search' => '合成']))->assertOk();
            $actualHeaders = $res->headers->all();
            $responseHeaderNames ??= array_keys($actualHeaders);
            $this->assertSame($responseHeaderNames, array_keys($actualHeaders));
            // Request IDs, timing, Date and remaining rate-limit capacity vary per request.
            foreach (['date', 'x-trace-id', 'x-response-time', 'server-timing', 'x-ratelimit-remaining', 'x-request-id'] as $dynamic) {
                unset($actualHeaders[$dynamic]);
            }
            $responseHeaders ??= $actualHeaders;
            $this->assertSame($responseHeaders, $actualHeaders);
            // The fake stores the latest download; compare the actual export object for each request.
            Excel::assertDownloaded('帳務-fixture-' . now('Asia/Taipei')->format('Ymd') . '.xlsx', function (BillListExport $e) use (&$cells, &$headers) {
                $cells ??= $e->array();
                $headers ??= $e->headings();
                $this->assertSame($cells, $e->array());
                $this->assertSame($headers, $e->headings());
                $this->assertSame($e->array()[0][6] + $e->array()[0][7], $e->array()[0][5]);

                return true;
            });
            $events = DB::table('security_audit_events')->where('event_type', 'billing.export');
            $this->assertSame($count + 1, $events->count());
            $meta = json_decode($events->orderByDesc('id')->first()->metadata, true);
            $this->assertSame($expected, $meta['filter']);
            $this->assertTrue($meta['has_search']);
            $this->assertSame(1, $meta['row_count']);
            $this->assertStringNotContainsString('合成', json_encode($meta, JSON_UNESCAPED_UNICODE));
            $this->assertSame($before, $shown->fresh()->getAttributes());
        }
    }

    public function test_filter_validation_still_requires_a_string_of_at_most_twenty_characters(): void
    {
        $shown = $this->owing('合成驗證');
        $token = $this->token([1]);
        foreach ([null, [], str_repeat('x', 21)] as $filter) {
            $this->export($token, $this->body([['id' => $shown->ID]], ['filter' => $filter]))
                ->assertUnprocessable()->assertJsonValidationErrors('filter');
        }
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'billing.export')->count());
    }

    public function test_text_cells_are_literal_strings_and_numbers_stay_numeric(): void
    {
        $xlsx = Excel::raw(new BillListExport([['=1+1', '@SUM(A1)', '剩 5 堂', '未繳', '—', 5000, 0, 5000]]), \Maatwebsite\Excel\Excel::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'bx');
        file_put_contents($path, $xlsx);
        $zip = new \ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $strings = $zip->getFromName('xl/sharedStrings.xml');
        unlink($path);

        // Text is a shared/inline string cell (t="s"/"inlineStr"), never a formula (<f>); the amounts are plain numbers.
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('=1+1', $strings);
        $this->assertStringContainsString('@SUM(A1)', $strings);
        $this->assertMatchesRegularExpression('#<c r="H2"(?: s="\d+")?><v>5000</v></c>#', $sheet);
        $this->assertMatchesRegularExpression('#<c r="G2"[^>]*><v>0</v></c>#', $sheet);
    }

    public function test_real_download_has_xlsx_headers_and_round_trips_only_the_requested_rows(): void
    {
        Campus::firstOrCreate(['id' => 1], ['name' => '合成分校', 'code' => 'fixture', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '']);
        Campus::query()->whereKey(1)->update(['code' => 'fixture']);
        $shown = $this->owing('=1+1');
        $this->owing('不在匯出範圍');
        $before = $shown->fresh()->getAttributes();

        // Exercise the actual HTTP response and XLSX writer, without Excel::fake().
        $filename = '帳務-fixture-' . now('Asia/Taipei')->format('Ymd') . '.xlsx';
        $response = $this->export($this->token([1]), $this->body([['id' => $shown->ID]]))
            ->assertOk()
            ->assertDownload()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // Laravel's filename assertion reads the ASCII fallback; browsers use the RFC5987 UTF-8 filename.
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode($filename), $response->headers->get('Content-Disposition'));
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        $path = $response->baseResponse->getFile()->getPathname();
        $binder = \PhpOffice\PhpSpreadsheet\Cell\Cell::getValueBinder();
        $workbook = null;
        try {
            // The writer leaves its text-safe binder installed; read the saved file like a separate consumer.
            \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder());
            $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame(1, $workbook->getSheetCount());
            $sheet = $workbook->getSheet(0);
            $this->assertSame(['學生', '科目', '期間／剩幾堂', '狀態', '到期／逾期', '應收', '已收', '未收'], $sheet->rangeToArray('A1:H1')[0]);
            $this->assertSame(2, $sheet->getHighestDataRow());
            $this->assertSame('=1+1', $sheet->getCell('A2')->getValue());
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC, $sheet->getCell('G2')->getDataType());
            $this->assertSame(0, $sheet->getCell('G2')->getValue());
            $this->assertSame($sheet->getCell('G2')->getValue() + $sheet->getCell('H2')->getValue(), $sheet->getCell('F2')->getValue());
            $this->assertSame($before, $shown->fresh()->getAttributes());
            $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'billing.export')->count());
        } finally {
            $workbook?->disconnectWorksheets();
            \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder($binder);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** Same fixtures as frontend/src/lib/__tests__/billRowsParity.test.js: the PHP cells equal what billRows.js shows. */
    public function test_cells_match_the_list_rules_on_the_shared_fixtures(): void
    {
        $fx = json_decode(file_get_contents(__DIR__ . '/../fixtures/bill_rows_parity.json'), true);
        foreach ($fx['cases'] as $c) {
            $this->assertSame($c['expect']['kind'], BillRowText::describe($c['row'], $fx['today'])['kind'], 'kind of #' . $c['row']['id']);
            $got = BillRowText::describe($c['row'], $fx['today']);
            $this->assertEquals($c['expect'], $got, 'row #' . $c['row']['id']);
        }
    }

    public function test_a_row_of_another_campus_is_never_exported(): void
    {
        $other = $this->owing('他校丙', 2);
        Excel::fake();

        $this->export($this->token([1]), $this->body([['id' => $other->ID]]))->assertNotFound();
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
