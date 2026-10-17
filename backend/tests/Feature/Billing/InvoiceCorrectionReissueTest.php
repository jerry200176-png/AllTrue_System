<?php
namespace Tests\Feature\Billing;
use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class InvoiceCorrectionReissueTest extends TestCase
{
    use RefreshDatabase;
    protected function refreshTestDatabase()
    {
        $this->artisan('migrate:fresh');
        config(['billing.receipt_correction_reissue_enabled' => true]);
        if (str_starts_with($this->getName(false), 'test_4132_') && !\Illuminate\Support\Facades\Schema::hasColumn('invoice_corrections', 'kind')) {
            \Illuminate\Support\Facades\Schema::table('invoice_corrections', fn ($t) => $t->string('kind', 32)->nullable()->default('receipt_reissue'));
        }
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
        $this->beginDatabaseTransaction();
    }

    private const KEY = '6f1c2b7e-3a4d-4e5f-8a9b-0c1d2e3f4a5b';
    private int $userId = 0;
    /** @dataProvider nonReceiptKinds */
    public function test_4132_fee_or_unknown_kind_cannot_be_replayed_as_a_pending_report(?string $kind): void
    {
        [$token, $course, $oldId] = $this->ready(8800);
        $replacementId = $this->invoice($course, 7200);
        $reportId = $kind === 'receipt_reissue' ? null : DB::table('payment_reports')->insertGetId([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'InvoiceID' => $replacementId,
            'reported_by_name' => 'Synthetic fixture', 'payment_date' => '2026-09-01', 'payment_method' => 'cash',
            'reported_amount' => 7200, 'status' => 'pending', 'report_token_hash' => hash('sha256', uniqid()),
            'token_expires_at' => now()->addDay()]);
        DB::table('invoice_corrections')->insert(['kind' => $kind, 'original_invoice_id' => $oldId,
            'replacement_invoice_id' => $replacementId, 'student_class_id' => $course->ID,
            'payment_report_id' => $reportId, 'amount' => 7200, 'reason' => '原約定 7200',
            'idempotency_key' => self::KEY, 'operator_user_id' => $this->userId, 'created_at' => now()]);
        $before = DB::table('Invoice')->orderBy('id')->get()->toArray();
        $beforeReports = DB::table('payment_reports')->orderBy('id')->get()->toArray();
        $this->record($token, $course, $oldId)->assertStatus(409)->assertJsonPath('code', 'correction_replay_incompatible');
        $this->assertEquals($before, DB::table('Invoice')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeReports, DB::table('payment_reports')->orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::table('Payment')->count());
        $this->assertSame(1, DB::table('invoice_corrections')->count());
    }

    public static function nonReceiptKinds(): array
    {
        return [['agreed_fee'], ['agreed_fee_unbilled'], ['unknown'], ['receipt_reissue']];
    }

    public function test_4132_explicit_receipt_kind_replays_its_existing_report(): void
    {
        [$token, $course, $oldId] = $this->ready();
        $first = $this->record($token, $course, $oldId, ['note' => '0'])->assertOk();
        $this->record($token, $course, $oldId, ['note' => '0'])->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('report_id', $first->json('report_id'))->assertJsonPath('invoice_id', $first->json('invoice_id'));
        $this->assertSame([1, 0], [DB::table('payment_reports')->count(), DB::table('Payment')->count()]);
    }

    public function test_correction_issues_a_linked_bill_and_confirm_settles_it(): void
    {
        [$token, $course, $oldId] = $this->ready(8800, '開錯金額，原約定 7200');
        $res = $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('status', 'pending');
        $newId = (int) $res->json('invoice_id');
        $oldNo = 'INV-202609-' . str_pad((string) $oldId, 6, '0', STR_PAD_LEFT);
        $this->assertStringContainsString("[更正自 #{$oldNo}／操作者：user_id={$this->userId}／原因：原約定 7200]", $this->note($newId));
        $oldNote = $this->note($oldId);
        $this->assertStringContainsString('[void: 開錯金額，原約定 7200; user_id=' . $this->userId, $oldNote);
        $this->assertMatchesRegularExpression('/\[replaced_by: #INV-\d+-' . str_pad((string) $newId, 6, '0', STR_PAD_LEFT) . "; user_id={$this->userId}; at=/", $oldNote);
        $this->assertSame(7200, (int) DB::table('Invoice')->where('id', $newId)->value('TotalAmount'));
        $this->assertSame($newId, (int) DB::table('payment_reports')->where('id', $res->json('report_id'))->value('InvoiceID'));
        $link = DB::table('invoice_corrections')->where('original_invoice_id', $oldId)->first();
        $this->assertSame([$newId, (int) $res->json('report_id'), 7200, '原約定 7200', self::KEY, $this->userId], [(int) $link->replacement_invoice_id,
            (int) $link->payment_report_id, (int) $link->amount, $link->reason, $link->idempotency_key, (int) $link->operator_user_id]);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->count());
        $this->withToken($token)->putJson('/api/v1/payment-reports/' . $res->json('report_id') . '/confirm')->assertOk();
        $this->assertSame('paid', DB::table('Invoice')->where('id', $newId)->value('Status'));
        $this->assertSame(2, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
    }

    public function test_a_double_submit_returns_the_same_bill_and_a_new_attempt_or_reason_gets_409(): void
    {
        [$token, $course, $oldId] = $this->ready(8800);
        $first = $this->record($token, $course, $oldId)->assertOk();
        $retry = $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($first->json('invoice_id'), $retry->json('invoice_id'));
        $this->assertSame($first->json('report_id'), $retry->json('report_id'));
        $this->record($token, $course, $oldId, ['correction_reason' => '另一個原因'])
            ->assertStatus(409)->assertJsonPath('code', 'replaces_invoice_already_replaced')->assertJsonPath('replacement_invoice_id', $first->json('invoice_id'));
        $this->record($token, $course, $oldId, ['idempotency_key' => '0b7d8c9e-1f2a-4b3c-9d4e-5f6a7b8c9d0e'])->assertStatus(409);
        $this->record($token, $course, $oldId, ['amount' => 7000])->assertStatus(409);
        $this->assertSame(2, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
        $this->assertSame(1, DB::table('payment_reports')->where('StudentClassID', $course->ID)->count());
        $this->assertSame(1, substr_count($this->note($oldId), 'replaced_by'));
        $this->assertSame(1, DB::table('invoice_corrections')->where('original_invoice_id', $oldId)->count());
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('invoice_id', $first->json('invoice_id'));
        $this->record($token, $course, $oldId, ['idempotency_key' => '0b7d8c9e-1f2a-4b3c-9d4e-5f6a7b8c9d0e'])->assertStatus(409);
    }

    public function test_the_database_refuses_a_second_correction_of_the_same_bill(): void
    {
        [$token, $course, $oldId] = $this->ready(8800);
        $first = $this->record($token, $course, $oldId)->assertOk();
        $other = $this->invoice($course, 7200);
        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            DB::table('invoice_corrections')->insert(['original_invoice_id' => $oldId, 'replacement_invoice_id' => $other, 'student_class_id' => $course->ID,
                'amount' => 7200, 'reason' => '另一個請求', 'idempotency_key' => '0b7d8c9e-1f2a-4b3c-9d4e-5f6a7b8c9d0e', 'created_at' => now()]);
        } finally {
            $this->assertSame((int) $first->json('invoice_id'), (int) DB::table('invoice_corrections')->where('original_invoice_id', $oldId)->value('replacement_invoice_id'));
        }
    }

    public function test_teacher_is_forbidden_and_other_campus_or_missing_bills_are_not_found(): void
    {
        $director = $this->token([1]); $course = $this->course($this->student());
        $oldId = $this->voidedInvoice($director, $course, 8800);
        $this->record($this->token([1], 'T'), $course, $oldId)->assertForbidden();
        $this->record($this->token([2]), $course, $oldId)->assertNotFound();
        $this->record($director, $course, 99999999)->assertNotFound()->assertJsonPath('code', 'replaces_invoice_not_found');
        $this->assertSame(1, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, DB::table('payment_reports')->count());
    }

    public function test_a_bill_that_is_not_void_or_of_another_course_or_student_is_refused(): void
    {
        $token = $this->token([1]);
        $student = $this->student();
        $course = $this->course($student);
        $open = $this->invoice($this->course($student), 8800);
        $otherCourse = $this->voidedInvoice($token, $this->course($student), 8800);
        $otherStudent = $this->voidedInvoice($token, $this->course($this->student()), 8800);
        $this->record($token, $course, $open)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_mismatch');
        $this->record($token, $course, $otherCourse)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_mismatch');
        $this->record($token, $course, $otherStudent)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_mismatch');
        $sameCourseOpen = $this->invoice($course, 8800);
        $this->record($token, $course, $sameCourseOpen)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_not_void');
        $this->assertSame(0, DB::table('payment_reports')->count());
        $this->assertSame(0, DB::table('invoice_corrections')->count());
    }

    public function test_reason_and_key_are_required(): void
    {
        [$token, $course, $oldId] = $this->ready(8800);
        $this->record($token, $course, $oldId, ['correction_reason' => null])->assertStatus(422)->assertJsonValidationErrors('correction_reason');
        $this->record($token, $course, $oldId, ['idempotency_key' => null])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->record($token, $course, $oldId, ['correction_reason' => '／／／'])->assertStatus(422)->assertJsonPath('code', 'correction_reason_invalid');
    }

    public function test_tags_typed_into_reasons_cannot_fake_a_replacement_or_the_operator(): void
    {
        [$token, $course] = [$this->token([1]), $this->course($this->student())];
        $decoy = $this->voidedInvoice($token, $course, 7200);
        $oldId = $this->voidedInvoice($token, $course, 8800, "開錯 [replaced_by: #INV-202609-{$decoy}; user_id=1; at=2026-01-01]");
        $this->assertStringNotContainsString('[replaced_by', $this->note($oldId));
        $newId = (int) $this->record($token, $course, $oldId, ['correction_reason' => '原約定／操作者：user_id=1] [更正自 #INV-1'])
            ->assertOk()->json('invoice_id');
        $note = $this->note($newId);
        $this->assertSame(1, substr_count($note, '操作者'));
        $this->assertSame(1, substr_count($note, '['));
        $this->assertStringContainsString("／操作者：user_id={$this->userId}／原因：", $note);
    }

    public function test_a_long_note_keeps_the_void_tag_whole_and_trims_older_text(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->invoice($course, 8800, str_repeat('家長約定說明', 25));
        $this->withToken($token)->postJson("/api/v1/invoices/{$oldId}/void", ['reason' => str_repeat('開錯金額', 10)])->assertOk();
        preg_match('/\[void: [^\]]+\]/u', $this->note($oldId), $voidTag);
        $newId = (int) $this->record($token, $course, $oldId)->assertOk()->json('invoice_id');
        $note = $this->note($oldId);
        $this->assertLessThanOrEqual(255, mb_strlen($note));
        $this->assertStringStartsWith('家長約定說明', $note);
        $this->assertStringContainsString($voidTag[0], $note);
        $this->assertMatchesRegularExpression('/\[replaced_by: #INV-\d+-' . str_pad((string) $newId, 6, '0', STR_PAD_LEFT) . '; user_id=\d+; at=[^\]]+\]$/u', $note);
    }

    public function test_an_older_void_reason_with_brackets_is_kept_whole(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $legacyTag = '[void: 開錯 [註：家長] 金額 [見附件]; user_id=5; at=2026-09-01 10:00:00]';
        $oldId = $this->invoice($course, 8800, str_repeat('舊備註', 50) . ' ' . $legacyTag);
        DB::table('Invoice')->where('id', $oldId)->update(['Status' => 'void']);
        $this->record($token, $course, $oldId)->assertOk();
        $note = $this->note($oldId);
        $this->assertLessThanOrEqual(255, mb_strlen($note));
        $this->assertStringContainsString($legacyTag, $note);
        $this->assertStringStartsWith('舊備註', $note);
        $this->assertStringContainsString('[replaced_by: #INV-', $note);
    }

    public function test_a_long_void_reason_falls_back_to_a_short_tag_then_to_the_audit_rows(): void
    {
        [$token, $course, $oldId] = $this->ready(8800, str_repeat('開錯金額說明', 24) . '一');
        $before = $this->note($oldId);
        $res = $this->record($token, $course, $oldId)->assertOk();
        [$newId, $correctionId] = [(int) $res->json('invoice_id'), (int) $res->json('correction_id')];
        $note = $this->note($oldId);
        $this->assertLessThanOrEqual(255, mb_strlen($note));
        $this->assertStringStartsWith($before, $note);
        $this->assertStringEndsWith("[更正為 #{$newId} 更正紀錄 #{$correctionId}]", $note);
        $other = $this->course($this->student());
        $fullId = $this->voidedInvoice($token, $other, 8800, str_repeat('開錯金額', 50));
        $fullBefore = $this->note($fullId);
        $otherNew = (int) $this->record($token, $other, $fullId)->assertOk()->json('invoice_id');
        $this->assertSame($fullBefore, $this->note($fullId));
        $this->assertStringContainsString('更正自 #INV-', $this->note($otherNew));
        $this->assertSame($otherNew, (int) DB::table('invoice_corrections')->where('original_invoice_id', $fullId)->value('replacement_invoice_id'));
        $this->record($token, $other, $fullId)->assertOk()->assertJsonPath('invoice_id', $otherNew);
    }

    public function test_guards_monthly_package_conflict_open_bill_pending_amount_and_paid_course(): void
    {
        $token = $this->token([1]);
        $student = $this->student();
        $monthly = $this->course($student, ['ScheduleMode' => 'date']);
        $this->record($token, $monthly, $this->voidedInvoice($token, $monthly, 8800))->assertStatus(422)->assertJsonPath('code', 'correction_reissue_count_only');
        $member = $this->course($student, ['PackageID' => 1]);
        $this->record($token, $member, $this->voidedInvoice($token, $member, 8800))->assertStatus(422)->assertJsonPath('code', 'correction_reissue_count_only');
        $course = $this->course($student);
        $oldId = $this->voidedInvoice($token, $course, 8800);
        $this->record($token, $course, $oldId, ['invoice_id' => $oldId])->assertStatus(422)->assertJsonPath('code', 'correction_reissue_conflict');
        $this->record($token, $course, $oldId, ['amount' => 0, 'free_gift' => true])->assertStatus(422)->assertJsonPath('code', 'correction_reissue_conflict');
        $this->record($token, $course, $oldId, ['amount' => 0])->assertStatus(422)->assertJsonPath('code', 'correction_amount_invalid');
        $open = $this->invoice($course, 8800);
        $this->record($token, $course, $oldId)->assertStatus(422)->assertJsonPath('code', 'open_invoice_exists');
        DB::table('Invoice')->where('id', $open)->update(['Status' => 'void']);
        $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => 7200,
        ])->assertOk();
        $this->record($token, $course, $oldId)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
        $paid = $this->course($student, ['Paid' => 1]);
        $paidOld = $this->voidedInvoice($token, $paid, 8800);
        $paidBill = $this->invoice($paid, 7200);
        DB::table('Invoice')->where('id', $paidBill)->update(['Status' => 'paid', 'PaidAmount' => 7200]);
        DB::table('Payment')->insert(['InvoiceID' => $paidBill, 'Amount' => 7200, 'PaidAt' => now(), 'Method' => 'cash']);
        $this->record($token, $paid, $paidOld)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
        $this->assertSame(0, DB::table('invoice_corrections')->count());
        $this->assertSame(0, DB::table('Invoice')->where('Note', 'like', '%更正自%')->count());
    }

    public function test_4132_disabled_capability_rejects_writes_and_replays(): void
    {
        [$token, $course, $old] = $this->ready(8000);
        config(['billing.receipt_correction_reissue_enabled' => false]);
        $this->record($token, $course, $old)->assertNotFound()->assertJsonPath('code', 'correction_reissue_disabled');
        $this->assertDatabaseCount('invoice_corrections', 0);
        config(['billing.receipt_correction_reissue_enabled' => true]);
        $this->record($token, $course, $old)->assertOk();
        config(['billing.receipt_correction_reissue_enabled' => false]);
        $this->record($token, $course, $old)->assertNotFound()->assertJsonPath('code', 'correction_reissue_disabled');
        $this->assertDatabaseCount('invoice_corrections', 1);
    }

    public function test_4132_pending_report_locked_by_confirmation_does_not_deadlock_reissue(): void
    {
        [$token, $course, $old] = $this->ready(8000);
        DB::table('payment_reports')->insert(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'reported_by_name' => 'Synthetic', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reported_amount' => 7200, 'status' => 'pending', 'report_token_hash' => hash('sha256', 'lock-fixture'),
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::commit();
        config(['database.connections.correction_lock_probe' => config('database.connections.' . DB::getDefaultConnection())]);
        $other = DB::connection('correction_lock_probe');
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            $other->beginTransaction();
            $other->table('payment_reports')->where('StudentClassID', $course->ID)->lockForUpdate()->first();
            $this->record($token, $course, $old)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
            $this->assertDatabaseCount('invoice_corrections', 0);
        } finally {
            $other->rollBack();
            DB::purge('correction_lock_probe');
            $this->artisan('migrate:fresh');
            DB::beginTransaction();
        }
    }

    public function test_4132_replay_rejects_changed_payment_details_and_missing_report(): void
    {
        [$token, $course, $old] = $this->ready(8000);
        $first = $this->record($token, $course, $old)->assertOk()->json();
        foreach ([['payment_date' => '2026-09-01'], ['payment_method' => 'transfer', 'account_last5' => '12345'],
            ['account_last5' => '54321'], ['note' => 'changed request']] as $changed) {
            $this->record($token, $course, $old, $changed)->assertStatus(409)->assertJsonPath('code', 'correction_replay_incompatible');
        }
        DB::table('payment_reports')->where('id', $first['report_id'])->delete();
        $this->record($token, $course, $old)->assertStatus(409)->assertJsonPath('code', 'correction_replay_incompatible');
        $this->assertDatabaseCount('invoice_corrections', 1);
    }

    public function test_4132_fresh_campus_authorization_blocks_write_and_replay(): void
    {
        $token = $this->token([1]);
        foreach ([false, true] as $replay) {
            $student = $this->student();
            $course = $this->course($student);
            $old = $this->voidedInvoice($token, $course, 8000);
            if ($replay) {
                $this->record($token, $course, $old)->assertOk();
            }
            $before = DB::table('invoice_corrections')->count();
            $moved = false;
            DB::listen(function ($query) use ($student, &$moved) {
                if (!$moved && str_starts_with(strtolower($query->sql), 'select')
                    && str_contains($query->sql, '`Student`.`id` in (' . $student->id . ')')) {
                    $moved = true;
                    DB::table('Student')->where('id', $student->id)->update(['CampusID' => 2]);
                }
            });
            $this->record($token, $course, $old)->assertNotFound();
            $this->assertTrue($moved);
            $this->assertSame($before, DB::table('invoice_corrections')->count());
        }
    }

    public function test_4132_correction_does_not_leak_cross_campus_tutoring_or_zero_fee_classification(): void
    {
        $token = $this->token([1]);
        foreach ([['ClassType' => 'tutoring', 'Charge' => 7200], ['ClassType' => 'one_on_one', 'Charge' => 0]] as $over) {
            $foreign = $this->course($this->student(['CampusID' => 2]), $over);
            // Seed a voided invoice row without exercising director void on a foreign campus.
            $oldId = $this->invoice($foreign, 7200);
            DB::table('Invoice')->where('id', $oldId)->update(['Status' => 'void']);
            config(['billing.receipt_correction_reissue_enabled' => false]);
            $this->record($token, $foreign, $oldId)->assertNotFound()->assertJsonPath('code', 'correction_reissue_disabled');
            config(['billing.receipt_correction_reissue_enabled' => true]);
            $this->record($token, $foreign, $oldId)->assertNotFound()->assertJsonPath('code', 'replaces_invoice_not_found');
            $this->assertSame(0, DB::table('invoice_corrections')->count());
            $this->assertSame(0, DB::table('payment_reports')->count());
        }
        // Same-campus tutoring still refused — but only after campus auth inside the correction path.
        $localTutoring = $this->course($this->student(), ['ClassType' => 'tutoring']);
        $localOld = $this->voidedInvoice($token, $localTutoring, 7200);
        $this->record($token, $localTutoring, $localOld)->assertStatus(422)
            ->assertJsonPath('code', 'tutoring_no_payment_obligation');
    }

    public function test_4132_confirm_rejects_correction_after_course_joins_package(): void
    {
        $token = $this->token([1]);
        $student = $this->student();
        $course = $this->course($student);
        $oldId = $this->voidedInvoice($token, $course, 8800);
        $res = $this->record($token, $course, $oldId)->assertOk();
        $reportId = (int) $res->json('report_id');
        $this->assertSame(1, DB::table('invoice_corrections')->where('payment_report_id', $reportId)->count());
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => 'joined-after-correction',
            'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8, 'used_sessions' => 0,
            'rate' => 900, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $course->update(['PackageID' => $pkg->id, 'PackageTotalSessions' => 8, 'PackageName' => $pkg->name]);
        $this->withToken($token)->putJson('/api/v1/payment-reports/' . $reportId . '/confirm')
            ->assertStatus(422)->assertJsonPath('code', 'correction_reissue_joined_package');
        $this->assertSame('pending', DB::table('payment_reports')->where('id', $reportId)->value('status'));
        $this->assertSame(0, (int) DB::table('StudentClass')->where('ID', $course->ID)->value('Paid'));
        $this->assertFalse((bool) DB::table('course_packages')->where('id', $pkg->id)->value('paid'));
    }

    /** @param array<string, mixed> $over */
    private function record(string $token, StudentClass $course, int $oldId, array $over = [])
    {
        return $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', array_filter(array_merge([
            'student_class_id' => $course->ID, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => 7200,
            'replaces_invoice_id' => $oldId, 'correction_reason' => '原約定 7200', 'idempotency_key' => self::KEY,
        ], $over), fn ($v) => $v !== null));
    }

    private function voidedInvoice(string $token, StudentClass $course, int $amount, string $reason = '開錯金額'): int
    {
        $id = $this->invoice($course, $amount);
        $this->withToken($token)->postJson("/api/v1/invoices/{$id}/void", ['reason' => $reason])->assertOk();
        return $id;
    }

    private function invoice(StudentClass $course, int $amount, string $note = ''): int
    {
        $id = (int) DB::table('Invoice')->insertGetId(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'billing_period' => '2026-09', 'Note' => $note, 'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-10',
            'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $id, 'StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => $amount]);
        return $id;
    }

    private function note(int $id): string
    {
        return (string) DB::table('Invoice')->where('id', $id)->value('Note');
    }

    /** @param array<string, mixed> $courseOver @return array{0: string, 1: StudentClass, 2: int} */
    private function ready(int $voidAmount = 8800, string $reason = '開錯金額', array $courseOver = [], ?Student $student = null): array
    {
        $token = $this->token([1]);
        $course = $this->course($student ?? $this->student(), $courseOver);
        return [$token, $course, $this->voidedInvoice($token, $course, $voidAmount, $reason)];
    }

    /** @param list<int> $campusIds */
    private function token(array $campusIds, string $type = 'D'): string
    {
        $user = User::create(['LoginName' => 'd3-' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => $type, 'phone' => '0900000000']);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        if ($type === 'D' && $campusIds === [1]) {
            $this->userId = (int) $user->id;
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    /** @param array<string, mixed> $over */
    private function student(array $over = []): Student
    {
        return Student::create(array_merge(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => ''], $over));
    }

    /** @param array<string, mixed> $over */
    private function course(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create(array_merge(['StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 7200,
            'Paid' => 0, 'Rate' => 900, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0], $over));
    }
}
