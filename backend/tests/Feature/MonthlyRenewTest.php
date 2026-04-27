<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * b4：月結制課程續約機制。
 *
 * 修復前行為：purchaseBatch 對月結課（ScheduleMode='date'）也會新建一筆堂數制課程，
 * 破壞月結計費語意，且舊月結課不被結案，造成重複課程列。
 *
 * 修復後行為：
 * 1. renewMonthly 端點：延長原月結課程的 EndDate，不新建課程，但補齊未來預排堂次供課程管理詳情顯示。
 * 2. purchaseBatch 對月結課回傳 422，防止誤用。
 * 3. 堂數制 purchaseBatch 流程完全不受影響。
 */
class MonthlyRenewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_renew_monthly_extends_end_date_without_creating_new_course_and_creates_future_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'week'             => 2,
            'time'             => '18:00:00',
            'SessionDuration'  => 120,
            'Paid'             => 1,
        ]);

        $newEndDate = '2026-06-30';

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => $newEndDate,
        ]);

        $res->assertOk()
            ->assertJsonPath('end_date', $newEndDate)
            ->assertJsonPath('course.schedule_mode', 'date')
            ->assertJsonPath('course.id', (int) $course->ID);

        $course->refresh();

        // EndDate 應被更新
        $this->assertStringStartsWith($newEndDate, (string) $course->EndDate);
        // 月結欄位保持不變
        $this->assertSame('date', (string) $course->ScheduleMode);
        $this->assertSame(15, (int) $course->settlement_day);
        $this->assertSame(8, (int) $course->monthly_sessions);
        // 不應被結案
        $this->assertSame(0, (int) $course->Stop);

        // 不應新建 StudentClass
        $this->assertSame(
            1,
            StudentClass::where('StudentID', $student->id)
                ->where('SubjectID', 1)
                ->where('Stop', 0)
                ->count(),
            '月結續約不應新建 StudentClass 記錄'
        );

        $this->assertSame(
            [
                '2026-04-28',
                '2026-05-05',
                '2026-05-12',
                '2026-05-19',
                '2026-05-26',
                '2026-06-02',
                '2026-06-09',
                '2026-06-16',
                '2026-06-23',
                '2026-06-30',
            ],
            $this->scheduledSessionDates($course),
            '月結續約後，課程管理詳情應能讀到固定時段的未來預排堂次'
        );

        // 同一續約請求重送不可重複建立堂次
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => $newEndDate,
        ])->assertOk();

        $this->assertSame(
            [
                '2026-04-28',
                '2026-05-05',
                '2026-05-12',
                '2026-05-19',
                '2026-05-26',
                '2026-06-02',
                '2026-06-09',
                '2026-06-16',
                '2026-06-23',
                '2026-06-30',
            ],
            $this->scheduledSessionDates($course),
            '月結續約補建堂次必須是 idempotent，避免詳情預排課程重複'
        );
    }

    public function test_updating_monthly_course_schedule_creates_fixed_future_sessions_for_detail_view(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-edit@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-05-31',
            'week'             => 2,
            'time'             => '18:00:00',
            'SessionDuration'  => 120,
            'Paid'             => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'duration_hours'   => 2,
            'payment_type'     => 'monthly',
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'days_of_week'     => [4],
            'start_time'       => '17:00',
            'day_time_slots'   => [['day' => 4, 'start_time' => '17:00']],
            'first_class_date' => '2026-04-01',
        ]);

        $res->assertOk();

        $this->assertSame(
            ['2026-04-30', '2026-05-07', '2026-05-14', '2026-05-21', '2026-05-28'],
            $this->scheduledSessionDates($course),
            '編輯月結固定時段後，課程管理詳情應顯示該月週期性預排堂次'
        );

        $this->assertSame(
            ['17:00:00'],
            ClassSession::where('StudentClassID', $course->ID)
                ->distinct()
                ->orderBy('StartTime')
                ->pluck('StartTime')
                ->all()
        );
    }

    public function test_legacy_monthly_course_without_end_date_still_returns_current_month_fixed_dates_for_detail_view(): void
    {
        $token = $this->createDirectorToken([1], 'director-legacy-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 8,
            'RemainingSessions' => 0,
            'settlement_day'   => 31,
            'monthly_sessions' => null,
            'StartDate'        => '2026-04-01',
            'EndDate'          => null,
            'week'             => 1,
            'time'             => '17:00:00',
            'week1'            => 4,
            'time1'            => '17:00:00',
            'duration1'        => 120,
            'SessionDuration'  => 120,
            'Paid'             => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate'    => '2026-04-06',
            'StartTime'      => '17:00:00',
            'EndTime'        => '19:00:00',
            'Status'         => 'scheduled',
            'Note'           => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates?branch_id=1', [
            'branch_id'   => 1,
            'range_start' => '2026-04-01',
            'range_end'   => '2026-04-30',
            'courses'     => [[
                'id'               => (int) $course->ID,
                'first_class_date' => '2026-04-01',
                'sessions_purchased' => 0,
                'days_of_week'     => [1, 4],
            ]],
        ]);

        $res->assertOk();

        $this->assertSame(
            [
                '2026-04-02',
                '2026-04-06',
                '2026-04-09',
                '2026-04-13',
                '2026-04-16',
                '2026-04-20',
                '2026-04-23',
                '2026-04-27',
                '2026-04-30',
            ],
            $res->json((string) $course->ID),
            'legacy 月結課即使 EndDate 缺失且只有部分 ClassSession，詳情仍應推算本月固定時段日期'
        );
    }

    public function test_director_can_materialize_projected_monthly_session_once_for_editing(): void
    {
        $token = $this->createDirectorToken([1], 'director-materialize-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 8,
            'RemainingSessions' => 0,
            'StartDate'        => '2026-04-01',
            'EndDate'          => null,
            'week'             => 1,
            'time'             => '17:00:00',
            'week1'            => 4,
            'time1'            => '17:00:00',
            'duration1'        => 120,
            'SessionDuration'  => 120,
        ]);

        $payload = [
            'student_class_id' => (int) $course->ID,
            'session_date'     => '2026-04-02',
            'start_time'       => '17:00',
            'branch_id'        => 1,
        ];

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', $payload);

        $first->assertOk()
            ->assertJsonPath('created', true)
            ->assertJsonPath('session.student_class_id', (int) $course->ID)
            ->assertJsonPath('session.session_date', '2026-04-02')
            ->assertJsonPath('session.start_time', '17:00')
            ->assertJsonPath('session.end_time', '19:00')
            ->assertJsonPath('session.status', 'scheduled');

        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', $payload);

        $second->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('session.id', $first->json('session.id'));

        $this->assertSame(
            1,
            ClassSession::where('StudentClassID', $course->ID)
                ->whereDate('SessionDate', '2026-04-02')
                ->where('StartTime', '17:00:00')
                ->count(),
            'materialize projected session must be idempotent'
        );
    }

    public function test_materializing_projected_session_rejects_inactive_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-materialize-inactive@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate'    => '2026-04-01',
            'week'         => 4,
            'time'         => '17:00:00',
            'Stop'         => 1,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', [
            'student_class_id' => (int) $course->ID,
            'session_date'     => '2026-04-02',
            'start_time'       => '17:00',
            'branch_id'        => 1,
        ])->assertStatus(422)
            ->assertJsonPath('message', '課程已結案或停用，不能新增堂次');
    }

    public function test_renew_monthly_rejects_session_mode_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-reject@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 2,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2099-12-31',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_renew_monthly_rejects_past_date(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-past@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'settlement_day'   => 10,
            'monthly_sessions' => 4,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2020-01-01',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_purchase_batch_rejects_monthly_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-pb-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 20,
            'monthly_sessions' => 6,
            'EndDate'          => '2026-05-31',
            'Rate'             => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions'   => 8,
            'start_date' => '2026-06-01',
            'mode'       => 'new_purchase',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);

        // 舊課程未變更
        $course->refresh();
        $this->assertSame('date', (string) $course->ScheduleMode);
        $this->assertSame(0, (int) $course->Stop);

        // 沒有新 StudentClass 被建立
        $this->assertSame(
            1,
            StudentClass::where('StudentID', $student->id)->count(),
            'purchaseBatch 對月結課應被拒絕，不應新建任何課程'
        );
    }

    // ── FR-002 / FR-003：renew-monthly 建立逐期帳單並重置 Paid ──────────────

    public function test_renew_monthly_creates_invoice_and_resets_paid(): void
    {
        $token = $this->createDirectorToken([1], 'director-invoice-create@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'Paid'             => 1,
            'Charge'           => 4800,
            'Rate'             => 600,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-05-31',
        ]);

        $res->assertOk();

        $course->refresh();
        // FR-003：Paid 重置為 0
        $this->assertSame(0, (int) $course->Paid, '月結續約後 Paid 應重置為 0');
        $this->assertNull($course->PayDate, '月結續約後 PayDate 應清空');

        // FR-002：建立新期 Invoice
        $this->assertDatabaseHas('Invoice', [
            'StudentClassID' => $course->ID,
            'billing_period' => '2026-05',
            'Status'         => 'unpaid',
        ]);

        $invoice = Invoice::where('StudentClassID', $course->ID)
            ->where('billing_period', '2026-05')
            ->first();
        $this->assertNotNull($invoice);
        $this->assertSame(4800, (int) $invoice->TotalAmount, 'TotalAmount 應從 Charge 取值');

        // FR-009：同步建立 InvoiceItem
        $this->assertDatabaseHas('InvoiceItem', [
            'InvoiceID' => $invoice->id,
        ]);
    }

    public function test_renew_monthly_invoice_creation_is_idempotent(): void
    {
        $token = $this->createDirectorToken([1], 'director-invoice-idempotent@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'   => 'date',
            'settlement_day' => 15,
            'EndDate'        => '2026-04-30',
            'Paid'           => 1,
            'Charge'         => 4800,
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$course->ID}/renew-monthly",
            ['end_date' => '2026-05-31']
        )->assertOk();

        $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$course->ID}/renew-monthly",
            ['end_date' => '2026-05-31']
        )->assertOk();

        // 不應建立兩筆相同 billing_period 的 Invoice
        $this->assertSame(
            1,
            Invoice::where('StudentClassID', $course->ID)
                ->where('billing_period', '2026-05')
                ->count(),
            '重複 renew-monthly 不應重複建立 Invoice'
        );
    }

    public function test_legacy_monthly_course_without_charge_creates_zero_invoice(): void
    {
        $token = $this->createDirectorToken([1], 'director-invoice-legacy@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'   => 'date',
            'settlement_day' => 15,
            'EndDate'        => '2026-04-30',
            'Paid'           => 0,
            'Charge'         => 0,
            'Rate'           => 0,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-05-31',
        ])->assertOk();

        // 即使金額為 0 也應建立 Invoice（主任手動核帳時再填金額）
        $this->assertDatabaseHas('Invoice', [
            'StudentClassID' => $course->ID,
            'billing_period' => '2026-05',
            'Status'         => 'unpaid',
            'TotalAmount'    => 0,
        ]);
    }

    // ── FR-006：directorRecord 優先找當月 billing_period Invoice ─────────────

    public function test_director_record_uses_current_billing_period_invoice(): void
    {
        $token = $this->createDirectorToken([1], 'director-record-period@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'   => 'date',
            'settlement_day' => 15,
            'Paid'           => 0,
            'Charge'         => 4800,
        ]);

        // 預先建立當月未繳帳單（模擬 renew-monthly 已建立）
        $currentPeriod = now()->format('Y-m');
        $invoice = Invoice::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate'      => now()->toDateString(),
            'TotalAmount'    => 4800,
            'PaidAmount'     => 0,
            'Status'         => 'unpaid',
            'Note'           => '',
            'billing_period' => $currentPeriod,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => (int) $course->ID,
            'payment_date'     => now()->toDateString(),
            'payment_method'   => 'cash',
            'amount'           => 4800,
        ]);

        $res->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->Status, '當月 Invoice 應被標為 paid');
        $this->assertSame(4800, (int) $invoice->PaidAmount);

        $course->refresh();
        $this->assertSame(1, (int) $course->Paid, '核帳後 StudentClass.Paid 應為 1');
    }

    public function test_director_record_accepts_zero_amount_for_free_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-record-zero@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'count',
            'ClassType'    => 'tutoring',
            'Paid'         => 0,
            'Charge'       => 0,
            'Rate'         => 0,
        ]);

        $invoice = Invoice::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate'      => now()->toDateString(),
            'TotalAmount'    => 0,
            'PaidAmount'     => 0,
            'Status'         => 'unpaid',
            'Note'           => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => (int) $course->ID,
            'payment_date'     => now()->toDateString(),
            'payment_method'   => 'cash',
            'amount'           => 0,
            'note'             => '免費課程結算',
        ]);

        $res->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->Status, '0 元 Invoice 應可核帳為 paid');
        $this->assertSame(0, (int) $invoice->PaidAmount);

        $course->refresh();
        $this->assertSame(1, (int) $course->Paid, '0 元課程核帳後 StudentClass.Paid 應為 1');

        $this->assertDatabaseHas('Payment', [
            'InvoiceID' => $invoice->id,
            'Amount'    => 0,
            'Method'    => 'cash',
        ]);
        $this->assertDatabaseHas('payment_reports', [
            'StudentClassID'  => $course->ID,
            'InvoiceID'       => $invoice->id,
            'reported_amount' => 0,
            'status'          => 'confirmed',
        ]);
    }

    public function test_purchase_batch_still_works_for_session_mode(): void
    {
        $token = $this->createDirectorToken([1], 'director-pb-session@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'UsedSessions'      => 7,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'Charge'            => 4000,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions'   => 6,
            'start_date' => '2026-04-07',
            'mode'       => 'new_purchase',
        ]);

        $res->assertCreated()
            ->assertJsonPath('mode', 'new_purchase')
            ->assertJsonPath('new_course.session_count', 6);

        // 確認真的有新課程建立（堂數制不受影響）
        $this->assertSame(
            2,
            StudentClass::where('StudentID', $student->id)->count(),
            '堂數制 purchase-batch 應正常新建課程'
        );
    }

    public function test_purchase_batch_rejects_duplicate_active_session_batch(): void
    {
        $token = $this->createDirectorToken([1], 'director-pb-duplicate@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);
        $duplicate = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 6,
            'RemainingSessions' => 6,
            'Paid'              => 0,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'StartDate'         => '2026-05-05',
            'EndDate'           => '2026-06-09',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions'   => 6,
            'start_date' => '2026-05-05',
            'mode'       => 'new_purchase',
        ])->assertStatus(409)
            ->assertJsonPath('duplicate_course.id', (int) $duplicate->ID);

        $this->assertSame(2, StudentClass::where('StudentID', $student->id)->count());
    }

    public function test_renewal_preview_for_monthly_course_is_read_only_and_shows_invoice_impact(): void
    {
        $token = $this->createDirectorToken([1], 'director-preview-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'Charge'           => 4800,
            'Paid'             => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode'     => 'renew_monthly',
            'end_date' => '2026-05-31',
        ]);

        $res->assertOk()
            ->assertJsonPath('mode', 'renew_monthly')
            ->assertJsonPath('severity', 'ok')
            ->assertJsonPath('proposed_course.current_end_date', '2026-04-30')
            ->assertJsonPath('proposed_course.end_date', '2026-05-31')
            ->assertJsonPath('proposed_course.paid', 0)
            ->assertJsonPath('billing.amount_due', 4800)
            ->assertJsonPath('billing.invoice.billing_period', '2026-05')
            ->assertJsonPath('billing.invoice.will_create', true);

        $course->refresh();
        $this->assertSame('2026-04-30', substr((string) $course->EndDate, 0, 10));
        $this->assertSame(1, (int) $course->Paid);
        $this->assertSame(0, Invoice::where('StudentClassID', $course->ID)->count());
    }

    public function test_renewal_confirm_for_monthly_course_requires_fresh_preview_state(): void
    {
        $token = $this->createDirectorToken([1], 'director-confirm-stale@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'Charge'           => 4800,
            'Paid'             => 1,
        ]);

        $preview = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode'     => 'renew_monthly',
            'end_date' => '2026-05-31',
        ])->json();

        $course->Paid = 0;
        $course->save();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $preview['preview_id'],
            'state_hash' => $preview['state_hash'],
            'mode'       => 'renew_monthly',
            'payload'    => ['end_date' => '2026-05-31'],
        ])->assertStatus(409)
            ->assertJsonPath('message', '課程狀態已變更，請重新預覽後再確認。');

        $course->refresh();
        $this->assertSame('2026-04-30', substr((string) $course->EndDate, 0, 10));
        $this->assertSame(0, Invoice::where('StudentClassID', $course->ID)->count());
    }

    public function test_renewal_confirm_for_session_course_creates_unpaid_new_batch_from_preview(): void
    {
        $token = $this->createDirectorToken([1], 'director-preview-batch@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'UsedSessions'      => 7,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'Charge'            => 4000,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $preview = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
            'mode'       => 'purchase_batch',
            'sessions'   => 6,
            'start_date' => '2026-05-05',
        ])->assertOk()
            ->assertJsonPath('severity', 'ok')
            ->assertJsonPath('billing.amount_due', 3000)
            ->assertJsonPath('schedule.created_sessions', 6)
            ->json();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-confirm", [
            'preview_id' => $preview['preview_id'],
            'state_hash' => $preview['state_hash'],
            'mode'       => 'purchase_batch',
            'payload'    => [
                'sessions'   => 6,
                'start_date' => '2026-05-05',
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('mode', 'purchase_batch')
            ->assertJsonPath('new_course.session_count', 6)
            ->assertJsonPath('new_course.paid', 0)
            ->assertJsonPath('schedule.created_sessions', 6);

        $this->assertSame(2, StudentClass::where('StudentID', $student->id)->count());
        $this->assertSame(6, ClassSession::where('StudentClassID', $res->json('new_course.id'))->count());
    }

    public function test_renewal_confirm_rejects_reusing_same_purchase_batch_preview(): void
    {
        $token = $this->createDirectorToken([1], 'director-confirm-duplicate@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'UsedSessions'      => 7,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'Charge'            => 4000,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $preview = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
            'mode'       => 'purchase_batch',
            'sessions'   => 6,
            'start_date' => '2026-05-05',
        ])->assertOk()
            ->json();

        $payload = [
            'preview_id' => $preview['preview_id'],
            'state_hash' => $preview['state_hash'],
            'mode'       => 'purchase_batch',
            'payload'    => [
                'sessions'   => 6,
                'start_date' => '2026-05-05',
            ],
        ];

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-confirm", $payload)
            ->assertCreated();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-confirm", $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', '課程狀態已變更，請重新預覽後再確認。');

        $this->assertSame(2, StudentClass::where('StudentID', $student->id)->count());
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name'      => '主任測試',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name'         => '月結續約學生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID'        => $studentId,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => 99,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => now()->toDateString(),
            'EndDate'          => null,
            'TotalHours'       => 20,
            'Charge'           => 0,
            'Paid'             => 0,
            'Rate'             => 0,
            'RoomID'           => 'R1',
            'MDate'            => now(),
            'Stop'             => 0,
            'ScheduleMode'     => 'count',
            'SessionCount'     => 8,
            'SessionDuration'  => 60,
            'RemainingSessions' => 8,
            'ClassType'        => 'one_on_one',
            'UsedSessions'     => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }

    /**
     * @return array<int, string>
     */
    private function scheduledSessionDates(StudentClass $course): array
    {
        return ClassSession::where('StudentClassID', $course->ID)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->pluck('SessionDate')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();
    }
}
