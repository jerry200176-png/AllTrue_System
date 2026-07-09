<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 驗收 BackfillLegacyPayments Artisan Command 的行為：
 *
 *   1. --dry-run 不寫入 DB
 *   2. 正式執行冪等（第二次執行 0 backfilled）
 *   3. Backfill 後 receipt endpoint 回傳 is_backfilled=true
 *   4. Charge=0 課程不被補建
 */
class BackfillLegacyPaymentsTest extends TestCase
{
    use RefreshDatabase;

    // ── Test 1: Dry-run 不寫入 DB ────────────────────────────────────

    public function test_dry_run_does_not_write_to_db(): void
    {
        $student = $this->createStudent(1);
        $this->createLegacyPaidClass($student->id, ['Charge' => 8800, 'PayDate' => '2025-06-01']);

        $beforeCount = PaymentReport::count();

        $exitCode = Artisan::call('payments:backfill-legacy', ['--dry-run' => true]);
        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('[DRY-RUN]', $output);
        $this->assertStringContainsString('Would backfill: 1 records', $output);

        $this->assertEquals($beforeCount, PaymentReport::count(), 'Dry-run must not write any PaymentReport');
        $this->assertEquals(0, Invoice::count(), 'Dry-run must not write any Invoice');
        $this->assertEquals(0, Payment::count(), 'Dry-run must not write any Payment');
    }

    // ── Test 2: 冪等性（第二次執行 0 backfilled）────────────────────

    public function test_idempotency_second_run_skips_all(): void
    {
        $student = $this->createStudent(1);
        $this->createLegacyPaidClass($student->id, ['Charge' => 9600, 'PayDate' => '2025-07-15']);

        // 第一次執行
        $exit1 = Artisan::call('payments:backfill-legacy');
        $this->assertSame(0, $exit1);
        $this->assertStringContainsString('Backfill complete: 1 backfilled, 0 skipped', Artisan::output());

        $firstCount = PaymentReport::whereNotNull('backfill_note')->count();
        $this->assertEquals(1, $firstCount);

        // 第二次執行（冪等驗證）
        $exit2 = Artisan::call('payments:backfill-legacy');
        $this->assertSame(0, $exit2);
        $this->assertStringContainsString('Backfill complete: 0 backfilled, 0 skipped', Artisan::output());

        // DB 記錄數不增加
        $this->assertEquals(1, PaymentReport::whereNotNull('backfill_note')->count());
    }

    // ── Test 3: is_backfilled=true 出現在 receipt endpoint ─────────

    public function test_backfilled_report_receipt_returns_is_backfilled_true(): void
    {
        $student = $this->createStudent(1);
        $sc = $this->createLegacyPaidClass($student->id, ['Charge' => 13200, 'PayDate' => '2025-09-01']);

        $this->artisan('payments:backfill-legacy')->assertExitCode(0);

        $report = PaymentReport::where('StudentClassID', $sc->ID)
            ->where('status', 'confirmed')
            ->firstOrFail();

        $this->assertNotNull($report->backfill_note);
        $this->assertStringContainsString('PayDate', $report->backfill_note);

        $token = $this->createDirectorToken([1]);
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/payment-reports/{$report->id}/receipt");

        $res->assertOk();
        $this->assertTrue($res->json('is_backfilled'), 'is_backfilled should be true for backfilled report');
        $this->assertNotNull($res->json('backfill_note'), 'backfill_note should be present');
    }

    // ── Test 4: Charge=0 課程不被補建 ────────────────────────────────

    public function test_charge_zero_class_is_not_backfilled(): void
    {
        $student = $this->createStudent(1);
        $this->createLegacyPaidClass($student->id, ['Charge' => 0, 'PayDate' => '2025-08-01']);

        $exitCode = Artisan::call('payments:backfill-legacy');
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Backfill complete: 0 backfilled, 0 skipped', Artisan::output());

        $this->assertEquals(0, PaymentReport::count(), 'Charge=0 course must not be backfilled');
    }

    // ── Test 5: 既有 confirmed report 不被覆蓋 ────────────────────

    public function test_existing_confirmed_report_is_not_overwritten(): void
    {
        $student = $this->createStudent(1);
        $sc = $this->createLegacyPaidClass($student->id, ['Charge' => 8000, 'PayDate' => '2025-05-01']);

        // 預先建立一筆 confirmed report（模擬正常收據）
        PaymentReport::create([
            'StudentID'        => $student->id,
            'StudentClassID'   => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date'     => '2025-05-01',
            'payment_method'   => 'cash',
            'reported_amount'  => 8000,
            'status'           => 'confirmed',
            'confirmed_at'     => Carbon::now(),
            'report_token_hash' => hash('sha256', 'existing-' . $sc->ID),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $existingUpdatedAt = PaymentReport::where('StudentClassID', $sc->ID)->value('updated_at');

        $exitCode = Artisan::call('payments:backfill-legacy');
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Backfill complete: 0 backfilled, 0 skipped', Artisan::output());

        $this->assertEquals(1, PaymentReport::where('StudentClassID', $sc->ID)->count(), 'Must not create a second report for an already-confirmed class');
        $this->assertNull(PaymentReport::where('StudentClassID', $sc->ID)->value('backfill_note'), 'Existing report backfill_note must remain null');
    }

    // ── Test 6: payDate 來源為 StartDate（無 PayDate）────────────────

    public function test_uses_start_date_when_pay_date_is_null(): void
    {
        $student = $this->createStudent(1);
        $sc = $this->createLegacyPaidClass($student->id, [
            'Charge'    => 7000,
            'PayDate'   => null,
            'StartDate' => '2025-03-01',
        ]);

        $this->artisan('payments:backfill-legacy')->assertExitCode(0);

        $report = PaymentReport::where('StudentClassID', $sc->ID)->firstOrFail();
        $this->assertStringContainsString('StartDate', $report->backfill_note);
        $this->assertStringContainsString('2025-03-01', $report->backfill_note);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name'          => '補建測試' . uniqid(),
            'CampusID'      => $campusId,
            'ClassID'       => 1,
            'enable'        => 1,
            'Phone'         => '0912000000',
            'MDT'           => now(),
            'Notify_Token'  => '',
        ]);
    }

    private function createLegacyPaidClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID'        => $studentId,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => 99,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => '2025-01-01',
            'EndDate'          => null,
            'TotalHours'       => 20,
            'Memo'             => null,
            'Charge'           => 8800,
            'Pay'              => 0,
            'PayDate'          => null,
            'Paid'             => 1,
            'Disconunt'        => null,
            'Rate'             => null,
            'LearnTimeID'      => null,
            'MDate'            => now(),
            'Stop'             => 0,
            'ScheduleMode'     => 'count',
            'SessionCount'     => 8,
            'SessionDuration'  => 120,
            'RemainingSessions' => 5,
            'ClassType'        => 'one_on_one',
            'UsedSessions'     => 0,
        ], $overrides));
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'backfill_dir_' . uniqid() . '@test.com',
            'Name'      => 'BackfillDirector',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 912345678,
        ]);

        foreach ($campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }

        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $raw,
            'expires_at' => Carbon::now()->addDay(),
        ]);

        return $raw;
    }
}
