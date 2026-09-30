<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\TeacherAttendanceMonth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * FR-001 (月報匯出 v1.2): GET /api/v1/teacher-attendance/export-monthly
 *
 * 防再犯紀錄 §TEST-001 ─ 所有 DB insert 均核對過 NOT NULL 欄位。
 * v1.2：月報改版為每位老師獨立 Sheet（per-teacher format）。
 */
class TeacherMonthlyExportTest extends TestCase
{
    use RefreshDatabase;

    // ── AC-1: 主任可成功下載 XLSX，HTTP 200 且 Content-Type 正確 ──────────────

    public function test_director_can_export_monthly_xlsx(): void
    {
        [$token, $campusId] = $this->scaffold('director');

        DB::table('TeacherSingIn')->insert([
            'TeacherID' => 1,
            'CampusID'  => $campusId,
            'SignInDT'  => now()->format('Y-m-d 08:00:00'),
            'SignOutDT' => now()->format('Y-m-d 17:00:00'),
            'MDT'       => now(),
        ]);

        $yearMonth = now()->format('Y-m');
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => '*/*'])
            ->get("/api/v1/teacher-attendance/export-monthly?year_month={$yearMonth}");

        $res->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('Content-Type', ''),
            '回應應為 XLSX Content-Type'
        );
    }

    // ── AC-2: year_month 格式錯誤 → 422 ──────────────────────────────────────

    public function test_invalid_year_month_returns_422(): void
    {
        [$token] = $this->scaffold('director');

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->get('/api/v1/teacher-attendance/export-monthly?year_month=2026-99');

        $res->assertUnprocessable();
    }

    // ── AC-3: 一般老師無法呼叫（middleware role:director,super_admin）─────────

    public function test_teacher_role_is_forbidden(): void
    {
        [$token] = $this->scaffold('teacher');

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->get('/api/v1/teacher-attendance/export-monthly?year_month=' . now()->format('Y-m'));

        $res->assertStatus(403);
    }

    // ── AC-4: 未攜帶 token → 401 ─────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $res = $this->withHeaders(['Accept' => 'application/json'])
            ->get('/api/v1/teacher-attendance/export-monthly?year_month=' . now()->format('Y-m'));

        $res->assertUnauthorized();
    }

    // ── AC-5: 有兩位老師的記錄 → XLSX 內含至少 2 個工作表 ────────────────────

    public function test_export_contains_one_sheet_per_teacher(): void
    {
        [$token, $campusId] = $this->scaffold('director');

        $yearMonth = now()->format('Y-m');
        $date      = now()->format('Y-m-d');

        DB::table('TeacherSingIn')->insert([
            ['TeacherID' => 10, 'CampusID' => $campusId,
             'SignInDT' => "{$date} 09:00:00", 'SignOutDT' => "{$date} 18:00:00", 'MDT' => now()],
            ['TeacherID' => 20, 'CampusID' => $campusId,
             'SignInDT' => "{$date} 13:00:00", 'SignOutDT' => "{$date} 21:00:00", 'MDT' => now()],
        ]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => '*/*'])
            ->get("/api/v1/teacher-attendance/export-monthly?year_month={$yearMonth}");

        $res->assertOk();
        // Verify it's an XLSX (multi-sheet ZIP format)
        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('Content-Type', '')
        );
    }

    // ── AC-6: xlsx 內容：摘要 / 老師 sheet（24h、工時、只刷一次、合計）/ 修正紀錄 ─────

    public function test_export_cells_follow_daily_rules(): void
    {
        [$token, $campusId, $directorId] = $this->scaffold('director');
        $teacher = User::create([
            'LoginName' => 'monthly-cells-' . uniqid() . '@example.com', 'Name' => '潘老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0900000002', 'MustChangePassword' => false,
        ]);
        $base = ['TeacherID' => $teacher->id, 'CampusID' => $campusId, 'MDT' => now(), 'Source' => 'rfid', 'Status' => 'normal'];
        DB::table('TeacherSingIn')->insert([
            $base + ['SignInDT' => '2026-08-01 09:59:24', 'SignOutDT' => '2026-08-01 17:53:20', 'Memo' => null],
            $base + ['SignInDT' => '2026-08-03 21:31:15', 'SignOutDT' => '2026-08-03 23:59:00', 'Memo' => TeacherAttendanceMonth::AUTO_CLOSE_MEMO],
        ]);
        $signinId = DB::table('TeacherSingIn')->where('SignInDT', '2026-08-01 09:59:24')->value('id');
        DB::table('teacher_signin_adjustments')->insert([
            'teacher_signin_id' => $signinId, 'adjusted_by_user_id' => $directorId, 'adjust_reason' => '忘記刷卡',
            'original_signin_dt' => '2026-08-01 10:30:00', 'original_signout_dt' => null,
            'new_signin_dt' => '2026-08-01 09:59:24', 'new_signout_dt' => '2026-08-01 17:53:20', 'created_at' => '2026-08-02 10:00:00',
        ]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => '*/*'])
            ->get('/api/v1/teacher-attendance/export-monthly?year_month=2026-08');
        $res->assertOk();

        $book = IOFactory::load($res->baseResponse->getFile()->getPathname());

        $this->assertSame(['摘要', '潘老師', '修正紀錄'], $book->getSheetNames());

        $summary = $book->getSheetByName('摘要');
        $this->assertSame(['潘老師', 2, 7.9, 1, 1, 0], [
            $summary->getCell('A3')->getValue(), $summary->getCell('B3')->getValue(), $summary->getCell('C3')->getValue(),
            $summary->getCell('D3')->getValue(), $summary->getCell('E3')->getValue(), $summary->getCell('F3')->getValue(),
        ]);

        $sheet = $book->getSheetByName('潘老師');
        $this->assertSame('09:59', $sheet->getCell('D3')->getValue());
        $this->assertSame('系統補登', $sheet->getCell('E6')->getValue());
        // 右側：08-01 有上下班、08-03 只刷一次
        $this->assertSame(['2026-08-01(六)', '09:59', '17:53', 7.9, '已修正'], [
            $sheet->getCell('G3')->getValue(), $sheet->getCell('I3')->getValue(), $sheet->getCell('J3')->getValue(),
            $sheet->getCell('K3')->getValue(), $sheet->getCell('L3')->getValue(),
        ]);
        $this->assertSame([null, null, '只刷一次 21:31'], [
            $sheet->getCell('I5')->getValue(), $sheet->getCell('J5')->getValue(), $sheet->getCell('L5')->getValue(),
        ]);
        $this->assertSame('合計', $sheet->getCell('G34')->getValue());
        $this->assertSame('出勤 2 天、只刷一次 1 天、修正 1 天', $sheet->getCell('L34')->getValue());

        $adj = $book->getSheetByName('修正紀錄');
        $this->assertSame(['2026-08-01', '潘老師', '08-01 10:30 → 08-01 09:59', '忘記刷卡'], [
            $adj->getCell('A3')->getValue(), $adj->getCell('B3')->getValue(), $adj->getCell('C3')->getValue(), $adj->getCell('G3')->getValue(),
        ]);
    }

    // ── AC-7: 月檢視 API：老師只看得到自己 ─────────────────────────────────────

    public function test_monthly_api_teacher_sees_only_self(): void
    {
        [$token, $campusId, $teacherId] = $this->scaffold('teacher');
        DB::table('TeacherSingIn')->insert([
            ['TeacherID' => $teacherId, 'CampusID' => $campusId, 'SignInDT' => '2026-08-01 09:00:00', 'SignOutDT' => '2026-08-01 12:00:00', 'MDT' => now()],
            ['TeacherID' => 999999, 'CampusID' => $campusId, 'SignInDT' => '2026-08-01 09:00:00', 'SignOutDT' => '2026-08-01 18:00:00', 'MDT' => now()],
        ]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/teacher-attendance/monthly?year_month=2026-08&teacher_id=999999');

        $res->assertOk()
            ->assertJsonCount(1, 'teachers')
            ->assertJsonPath('teachers.0.teacher_id', $teacherId)
            ->assertJsonPath('teachers.0.totals.minutes', 180)
            ->assertJsonPath('teachers.0.days.0.sign_out', '12:00');
    }

    public function test_monthly_api_director_sees_campus_teachers(): void
    {
        [$token, $campusId] = $this->scaffold('director');
        DB::table('TeacherSingIn')->insert([
            ['TeacherID' => 10, 'CampusID' => $campusId, 'SignInDT' => '2026-08-01 09:00:00', 'SignOutDT' => null, 'MDT' => now()],
            ['TeacherID' => 20, 'CampusID' => 424242, 'SignInDT' => '2026-08-01 09:00:00', 'SignOutDT' => null, 'MDT' => now()],
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/teacher-attendance/monthly?year_month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'teachers')
            ->assertJsonPath('teachers.0.teacher_id', 10)
            ->assertJsonPath('teachers.0.totals.anomaly_days', 1);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function scaffold(string $role): array
    {
        Campus::firstOrCreate(
            ['id' => 1],
            ['name' => '測試分校', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
             'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '',
             'TeachLIFFID' => '', 'TeachLIFF_URL' => '']
        );

        $type = $role === 'teacher' ? 'T' : 'A';
        $email = "monthly-export-{$role}-" . uniqid() . '@example.com';
        $user = User::create([
            'LoginName'          => $email,
            'Name'               => '測試用戶',
            'PSW'                => 'secret',
            'type'               => $type,
            'phone'              => '0900000001',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID'   => $user->id,
            'Admin'    => $role === 'director' ? 1 : 0,
            'Approved' => 1,
        ]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return [$raw, 1, $user->id];
    }
}
