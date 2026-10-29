<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\SiblingContractTransfer;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\ContractMoneyVerdict;
use App\Services\Billing\SiblingContractTransferService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** In-app #412 / #4153: remaining sessions to a sibling, pro-rata of what was actually paid. */
class SiblingContractTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_split_is_exact_and_rounds_half_up(): void
    {
        $this->assertSame(['kept' => 6000, 'moved' => 6000], SiblingContractTransferService::split(12000, 8, 4));
        $this->assertSame(['kept' => 6667, 'moved' => 3333], SiblingContractTransferService::split(10000, 3, 1));
        $this->assertSame(['kept' => 3, 'moved' => 2], SiblingContractTransferService::split(5, 2, 1)); // 2.5 -> 3 kept
        foreach ([1, 7, 99, 1000, 12345] as $paid) {
            for ($n = 2; $n <= 12; $n++) {
                for ($moving = 1; $moving < $n; $moving++) {
                    $s = SiblingContractTransferService::split($paid, $n, $moving);
                    $this->assertSame($paid, $s['kept'] + $s['moved']); // never creates or loses value
                    $this->assertSame((int) round($paid * ($n - $moving) / $n, 0, PHP_ROUND_HALF_UP), $s['kept']);
                    $this->assertGreaterThanOrEqual(0, $s['moved']);
                }
            }
        }
    }

    public function test_preview_returns_plan_and_writes_nothing(): void
    {
        [$token, $src, $sibling] = $this->setUpFamily();
        $this->transfer($token, $src, $sibling, ['preview' => true])->assertOk()
            ->assertJsonPath('plan.remaining_sessions', 4)->assertJsonPath('plan.moved_sessions', 4)
            ->assertJsonPath('plan.paid_amount', 12000)->assertJsonPath('plan.amount', 6000)
            ->assertJsonPath('plan.source_keeps_amount', 6000)->assertJsonPath('plan.family_link', true);

        $this->assertSame(0, SiblingContractTransfer::count());
        $this->assertSame(0, Payment::whereIn('Method', ['transfer_out', 'transfer_in'])->count());
        $this->assertSame(8, (int) $src->fresh()->SessionCount);
        $this->assertSame(0, StudentClass::where('StudentID', $sibling->id)->count());
    }

    public function test_transfer_moves_remaining_sessions_and_keeps_ledger_and_payment_pair_consistent(): void
    {
        [$token, $src, $sibling, $invoice] = $this->setUpFamily();
        $res = $this->transfer($token, $src, $sibling)->assertCreated()
            ->assertJsonPath('replayed', false)->assertJsonPath('transfer.amount', 6000)->assertJsonPath('transfer.moved_sessions', 4);
        $newId = (int) $res->json('transfer.target_course_id');

        $new = StudentClass::find($newId);
        $this->assertSame([$sibling->id, 4, 6000, 1, 'manual_occurrence'], [(int) $new->StudentID, (int) $new->SessionCount, (int) $new->Charge, (int) $new->Paid, $new->scheduling_policy]);
        $this->assertSame([4, 6000], [(int) $src->fresh()->SessionCount, (int) $src->fresh()->Charge]);
        // The four unmarked future lessons left with the sessions; the four attended ones stay.
        $this->assertSame(4, DB::table('ClassSession')->where('StudentClassID', $src->ID)->where('Status', 'attended')->count());
        $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $src->ID)->where('Status', 'scheduled')->count());

        $srcInv = Invoice::find($invoice->id);
        $newInv = Invoice::where('StudentClassID', $newId)->first();
        $this->assertSame([6000, 6000], [(int) $srcInv->TotalAmount, (int) $srcInv->PaidAmount]);
        $this->assertSame([6000, 6000, 'paid', $sibling->id], [(int) $newInv->TotalAmount, (int) $newInv->PaidAmount, $newInv->Status, (int) $newInv->StudentID]);
        $this->assertSame(6000, (int) InvoiceItem::where('InvoiceID', $newInv->id)->sum('Amount'));
        $out = Payment::where('InvoiceID', $srcInv->id)->where('Method', 'transfer_out')->first();
        $in = Payment::where('InvoiceID', $newInv->id)->where('Method', 'transfer_in')->first();
        $this->assertSame([-6000, 6000], [(int) $out->Amount, (int) $in->Amount]);
        // The original receipt is untouched and every ledger nets to its contract; the pair nets to zero.
        $this->assertSame(12000, (int) Payment::where('Method', 'cash')->sum('Amount'));
        $this->assertSame(6000, (int) Payment::where('InvoiceID', $srcInv->id)->sum('Amount'));
        $this->assertSame(6000, (int) Payment::where('InvoiceID', $newInv->id)->sum('Amount'));
        $this->assertSame(0, (int) Payment::whereIn('Method', ['transfer_out', 'transfer_in'])->sum('Amount'));

        $link = SiblingContractTransfer::first();
        $this->assertSame([$src->ID, $newId, $src->StudentID, $sibling->id, 8, 4, 12000, 6000, (int) $out->id, (int) $in->id, 1],
            [(int) $link->source_student_class_id, (int) $link->target_student_class_id, (int) $link->source_student_id, (int) $link->target_student_id,
                (int) $link->purchased_sessions, (int) $link->moved_sessions, (int) $link->paid_amount, (int) $link->amount,
                (int) $link->transfer_out_payment_id, (int) $link->transfer_in_payment_id, (int) $link->campus_id]);
        $this->assertNotSame(0, (int) $link->created_by);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'student_class.sibling_transfer']);

        $verdict = app(ContractMoneyVerdict::class);
        foreach ([$src->fresh(), $new] as $course) {
            $v = $verdict->forCourse($course);
            $this->assertSame(['paid', 6000, 0], [$v->status, $v->applied, $v->outstanding]);
        }
    }

    public function test_partial_move_and_rounding_split_the_paid_amount_exactly(): void
    {
        [$token, $src, $sibling] = $this->setUpFamily();
        $this->transfer($token, $src, $sibling, ['sessions' => 2])->assertCreated()->assertJsonPath('transfer.amount', 3000);
        $this->assertSame([6, 9000], [(int) $src->fresh()->SessionCount, (int) $src->fresh()->Charge]);

        // 3 sessions at 3333.33 = 10000 paid; moving 1 of the 2 remaining keeps 6667 and moves 3333.
        [$token2, $src2, $sibling2] = $this->setUpFamily(count: 3, used: 1, charge: 10000, rate: 3333.33);
        $this->transfer($token2, $src2, $sibling2, ['sessions' => 1])->assertCreated()->assertJsonPath('transfer.amount', 3333);
        $this->assertSame(6667, (int) Invoice::where('StudentClassID', $src2->ID)->value('PaidAmount'));
        $this->assertSame(0, (int) Payment::whereIn('Method', ['transfer_out', 'transfer_in'])->sum('Amount'));
    }

    public function test_refuses_cross_campus_stopped_and_over_remaining_without_writing(): void
    {
        [$token, $src, , , $stranger] = $this->setUpFamily(withStranger: true);
        $this->transfer($token, $src, $stranger)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_cross_campus');

        [$token, $src, $sibling] = $this->setUpFamily();
        $this->transfer($token, $src, $sibling, ['sessions' => 5])->assertStatus(422)
            ->assertJsonPath('code', 'sibling_transfer_over_remaining')->assertJsonPath('remaining_sessions', 4);
        $src->forceFill(['Stop' => 1])->save();
        $this->transfer($token, $src, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_source_closed');
        $src->forceFill(['Stop' => 0, 'closed_reason' => 'usage_settled'])->save();
        $this->transfer($token, $src, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_source_closed');

        $this->assertSame(0, SiblingContractTransfer::count());
        $this->assertSame(0, Payment::whereIn('Method', ['transfer_out', 'transfer_in'])->count());
        $this->assertSame(8, (int) $src->fresh()->SessionCount);
    }

    public function test_refuses_plain_scope_violations_and_a_missing_family_link(): void
    {
        [$token, $src, $sibling] = $this->setUpFamily(familyLink: false);
        $this->transfer($token, $src, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_no_family_link');
        $this->transfer($token, $src, $sibling, ['preview' => true])->assertOk()->assertJsonPath('plan.family_link', false);
        $this->transfer($token, $src, $sibling, ['acknowledge_no_family_link' => true])->assertCreated();

        [$token, $src, $sibling] = $this->setUpFamily();
        $this->transfer($token, $src, $src->student)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_bad_target');
        $src->forceFill(['Disconunt' => 500])->save();
        $this->transfer($token, $src, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_paid_not_simple');
        $src->forceFill(['Disconunt' => 0, 'PackageID' => 7])->save();
        $this->transfer($token, $src, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_package_forbidden');
        $src->forceFill(['PackageID' => null, 'deduction_basis' => 'actual_duration'])->save();
        $this->transfer($token, $src, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_actual_duration');
        $src->forceFill(['deduction_basis' => 'fixed_session'])->save();
        Payment::create(['InvoiceID' => Invoice::where('StudentClassID', $src->ID)->value('id'), 'Amount' => -100, 'PaidAt' => '2026-09-05', 'Method' => 'void', 'Note' => '']);
        $this->transfer($token, $src, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_paid_not_simple');

        [$token, $unpaid, $sibling] = $this->setUpFamily(paid: false);
        $this->transfer($token, $unpaid, $sibling)->assertStatus(422)->assertJsonPath('code', 'sibling_transfer_paid_not_simple');
    }

    public function test_retry_with_the_same_key_replays_and_one_source_transfers_once(): void
    {
        [$token, $src, $sibling, , , $other] = $this->setUpFamily(withOtherSibling: true);
        $first = $this->transfer($token, $src, $sibling, ['idempotency_key' => 'key-aaaaaaaa'])->assertCreated();
        $again = $this->transfer($token, $src, $sibling, ['idempotency_key' => 'key-aaaaaaaa'])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($first->json('transfer.id'), $again->json('transfer.id'));
        $this->assertSame(1, SiblingContractTransfer::count());
        $this->assertSame(1, StudentClass::where('StudentID', $sibling->id)->count());
        $this->assertSame(2, Payment::whereIn('Method', ['transfer_out', 'transfer_in'])->count());

        $this->transfer($token, $src, $other, ['idempotency_key' => 'key-aaaaaaaa'])->assertStatus(409)->assertJsonPath('code', 'sibling_transfer_key_reused');
        $this->transfer($token, $src, $other, ['idempotency_key' => 'key-bbbbbbbb'])->assertStatus(409)->assertJsonPath('code', 'sibling_transfer_already_done');
        $this->assertSame(1, SiblingContractTransfer::count());
        $this->assertSame(0, StudentClass::where('StudentID', $other->id)->count());
    }

    public function test_only_a_director_of_the_campus_may_transfer(): void
    {
        [$token, $src, $sibling] = $this->setUpFamily();
        $this->postJson("/api/v1/student-classes/{$src->ID}/sibling-transfer", $this->body($sibling))->assertStatus(401);
        $this->transfer($this->token('T', 1), $src, $sibling)->assertStatus(403); // teacher
        $this->transfer($this->token('A', 2), $src, $sibling)->assertStatus(403); // director of another campus
        $this->assertSame(0, SiblingContractTransfer::count());
        $this->transfer($token, $src, $sibling)->assertCreated();
    }

    // ---- fixtures ----

    private function transfer(string $token, StudentClass $src, Student $target, array $extra = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/student-classes/{$src->ID}/sibling-transfer", array_merge($this->body($target), $extra));
    }

    private function body(Student $target): array
    {
        return ['target_student_id' => $target->id, 'reason' => '手足轉讓', 'idempotency_key' => 'key-' . uniqid()];
    }

    /** @return array{0:string, 1:StudentClass, 2:Student, 3:Invoice, 4:?Student, 5:?Student} */
    private function setUpFamily(int $count = 8, int $used = 4, int $charge = 12000, float $rate = 1500, bool $paid = true,
        bool $familyLink = true, bool $withStranger = false, bool $withOtherSibling = false): array
    {
        $phone = $familyLink ? '0911000' . random_int(100, 999) : '';
        $mk = fn (int $campus, string $parentPhone) => Student::create([
            'name' => '手足測試-' . uniqid(), 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(),
            'Notify_Token' => '', 'parent_phone' => $parentPhone,
        ]);
        $student = $mk(1, $phone);
        $sibling = $mk(1, $phone);
        $src = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => (int) \App\Services\FrontendSubjectIdResolver::resolve('English'),
            'TeacherID' => $this->makeTeacher(), 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-02', 'TotalHours' => $count * 2,
            'Charge' => $charge, 'Pay' => $paid ? $charge : 0, 'Paid' => $paid ? 1 : 0, 'PayDate' => $paid ? '2026-09-01' : null,
            'Rate' => $rate, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => $count, 'SessionDuration' => 120, 'RemainingSessions' => $count - $used, 'UsedSessions' => $used,
            'ClassType' => 'one_on_one', 'week' => 3, 'time' => '13:00:00',
        ]);
        for ($i = 0; $i < $used; $i++) {
            DB::table('ClassSession')->insert(['StudentClassID' => $src->ID, 'SessionDate' => Carbon::parse('2026-09-02')->addWeeks($i)->toDateString(),
                'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'attended']);
        }
        for ($i = 0; $i < $count - $used; $i++) {
            DB::table('ClassSession')->insert(['StudentClassID' => $src->ID, 'SessionDate' => Carbon::parse('2026-09-30')->addWeeks($i)->toDateString(),
                'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled']);
        }
        $invoice = null;
        if ($paid) {
            $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $src->ID, 'IssueDate' => '2026-09-01',
                'TotalAmount' => $charge, 'PaidAmount' => $charge, 'Status' => 'paid', 'Note' => '']);
            InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $src->ID, 'Description' => "英文 {$count} 堂", 'Amount' => $charge]);
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $charge, 'PaidAt' => '2026-09-01', 'Method' => 'cash', 'Note' => '']);
        }

        return [$this->token('A', 1), $src, $sibling, $invoice, $withStranger ? $mk(2, $phone) : null, $withOtherSibling ? $mk(1, $phone) : null];
    }

    private function makeTeacher(): int
    {
        $u = User::create(['LoginName' => 'sib-teacher-' . uniqid() . '@test.com', 'Name' => '手足老師', 'PSW' => 'secret', 'type' => 'T', 'phone' => '0922222222']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => 0, 'Approved' => 1]);

        return (int) $u->id;
    }

    /** type A = director (Admin=1 on the campus), T = teacher. */
    private function token(string $type, int $campusId): string
    {
        $user = User::create(['LoginName' => 'sib-' . strtolower($type) . '-' . uniqid() . '@test.com', 'Name' => '手足測試帳號', 'PSW' => 'secret', 'type' => $type, 'phone' => '0912345678']);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
