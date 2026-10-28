<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Billing\ContractMoneyVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #3868 (Founder A): super_admin-only 用編號找合約. Synthetic data only. */
final class StudentClassLookupTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/student-classes/lookup';

    private int $seq = 0;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::factory()->create();
    }

    public function test_super_admin_gets_readable_rows_with_the_existing_money_numbers_for_count_and_monthly(): void
    {
        $teacher = $this->user('T');
        $count = $this->course(['Charge' => 8800, 'Rate' => 900, 'SessionCount' => 8, 'TeacherID' => $teacher->id, 'EndDate' => '2027-01-31']);
        $monthly = $this->course(['ScheduleMode' => 'date', 'Charge' => 5000, 'Rate' => 700, 'SessionCount' => null, 'Stop' => 1]);
        $same = $this->course(['Charge' => 7200, 'Rate' => 900, 'SessionCount' => 8]);
        Invoice::create(['StudentID' => $count->StudentID, 'StudentClassID' => $count->ID, 'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-09']);

        $res = $this->getJson(self::URL . "?ids={$count->ID},{$monthly->ID},{$same->ID}", $this->as($this->user('S')))->assertOk();

        $rows = collect($res->json('data'))->keyBy('id');
        $verdicts = app(ContractMoneyVerdict::class)->forCourses([$count->fresh(), $monthly->fresh(), $same->fresh()]);

        $c = $rows[$count->ID];
        $this->assertSame(
            ['id', 'found', 'campus_id', 'campus_name', 'student_id', 'student_name', 'subject', 'teacher_name', 'schedule_mode', 'start_date', 'end_date',
                'enabled', 'charge', 'rate', 'sessions', 'rate_x_sessions', 'has_invoice', 'money_note'],
            array_keys($c),
        );
        $this->assertSame([true, $this->campus->id, $this->campus->name, 'Synthetic Student', $teacher->Name, 'count', '2026-09-01', '2027-01-31', true],
            [$c['found'], $c['campus_id'], $c['campus_name'], $c['student_name'], $c['teacher_name'], $c['schedule_mode'], $c['start_date'], $c['end_date'], $c['enabled']]);
        // Same numbers as the helpers the ledger uses: StudentClass::currentContractCharge, Rate x SessionCount, ContractMoneyVerdict.
        $this->assertSame([$count->fresh()->currentContractCharge(), 900.0, 8, 7200, true], [$c['charge'], (float) $c['rate'], $c['sessions'], $c['rate_x_sessions'], $c['has_invoice']]);
        $this->assertSame(8800, $c['charge']);
        $this->assertSame('unpaid', $verdicts[$count->ID]->status);
        $this->assertSame('未繳；總費用與單價×堂數不同', $c['money_note']);

        $m = $rows[$monthly->ID]; // monthly: no single total, no rate x sessions; stopped = not enabled
        $this->assertSame([null, 700.0, null, null, false, false, 'date'], [$m['charge'], (float) $m['rate'], $m['sessions'], $m['rate_x_sessions'], $m['enabled'], $m['has_invoice'], $m['schedule_mode']]);
        $this->assertNull($monthly->fresh()->currentContractCharge());
        $this->assertStringNotContainsString('單價×堂數不同', $m['money_note']);
        $this->assertStringContainsString(['paid' => '已收', 'free' => '免費／贈送', 'partial' => '繳了一部分', 'unpaid' => '未繳', 'unbilled' => '尚未開帳單', 'review_required' => '需核對'][$verdicts[$monthly->ID]->status], $m['money_note']);

        $this->assertSame([7200, 7200, false, '尚未開帳單'], [$rows[$same->ID]['charge'], $rows[$same->ID]['rate_x_sessions'], $rows[$same->ID]['has_invoice'], $rows[$same->ID]['money_note']]);
    }

    public function test_only_super_admin_may_look_up_contracts(): void
    {
        $course = $this->course();
        $url = self::URL . "?ids={$course->ID}";

        $this->getJson($url)->assertUnauthorized();
        foreach (['A', 'D', 'T'] as $type) {
            $this->getJson($url, $this->as($this->user($type)))->assertForbidden();
        }
        $this->assertSame(0, DB::table('security_audit_events')->count()); // refused callers read nothing and leave no row
    }

    /** @dataProvider badIds */
    public function test_bad_ids_are_422(string $query): void
    {
        $this->getJson(self::URL . $query, $this->as($this->user('S')))->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->assertSame(0, DB::table('security_audit_events')->count());
    }

    /** @return array<string, array{string}> */
    public static function badIds(): array
    {
        return [
            'missing' => [''], 'empty' => ['?ids='], 'letters' => ['?ids=12,abc'], 'decimal' => ['?ids=1,2.5'], 'negative' => ['?ids=-3'],
            'zero' => ['?ids=0'], 'blank item' => ['?ids=1,,2'], 'array' => ['?ids[]=1&ids[]=2'], 'too big' => ['?ids=99999999999'],
            'fifty one' => ['?ids=' . implode(',', range(1, 51))],
        ];
    }

    public function test_fifty_ids_are_allowed_and_unknown_ids_come_back_not_found_in_order_without_duplicates(): void
    {
        $course = $this->course();
        $this->getJson(self::URL . '?ids=' . implode(',', range(1000, 1049)), $this->as($this->user('S')))->assertOk()->assertJsonCount(50, 'data');

        $res = $this->getJson(self::URL . "?ids=987654, {$course->ID},987654", $this->as($this->user('S')))->assertOk();

        $this->assertSame([['id' => 987654, 'found' => false], $course->ID, true], [$res->json('data.0'), $res->json('data.1.id'), $res->json('data.1.found')]);
        $this->assertCount(2, $res->json('data'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_the_only_write_is_one_audit_row_per_request_without_names(): void
    {
        $admin = $this->user('S');
        $course = $this->course();
        $before = ['StudentClass' => DB::table('StudentClass')->get()->all(), 'Student' => DB::table('Student')->get()->all(), 'Invoice' => DB::table('Invoice')->count()];
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (!preg_match('/^\s*(select|show|set|use)\b/i', $q->sql)) {
                $writes[] = preg_replace('/\s+/', ' ', substr($q->sql, 0, 60));
            }
        });

        $this->getJson(self::URL . "?ids={$course->ID},424242", $this->as($admin))->assertOk();

        $this->assertCount(1, $writes);
        $this->assertStringContainsString('security_audit_events', $writes[0]);
        $this->assertEquals($before, ['StudentClass' => DB::table('StudentClass')->get()->all(), 'Student' => DB::table('Student')->get()->all(), 'Invoice' => DB::table('Invoice')->count()]);
        $row = DB::table('security_audit_events')->where('event_type', 'student_class_lookup.read')->first();
        $this->assertSame(1, DB::table('security_audit_events')->count());
        $this->assertSame([SecurityAuditEvent::ref('user', $admin->id), SecurityAuditEvent::ref('student_class_batch', "{$course->ID},424242"), 'success'], [$row->actor_ref, $row->subject_ref, $row->outcome]);
        $this->assertSame(2, json_decode($row->metadata, true)['row_count']);
        $this->assertStringNotContainsString('Synthetic', json_encode($row));
    }

    /** @param array<string, mixed> $overrides */
    private function course(array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 'Synthetic Student', 'CampusID' => $this->campus->id, 'ClassID' => 1, 'SchoolName' => 'School', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0900000000']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1,
            'Period' => 4, 'StartDate' => '2026-09-01', 'TotalHours' => 16, 'Memo' => '',
            'Charge' => 8800, 'Paid' => 0, 'Rate' => 900, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 90, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'standard_lesson_minutes' => 90,
        ], $overrides));
    }

    private function user(string $type): User
    {
        $n = ++$this->seq;

        return User::create(['LoginName' => "lookup{$n}@test.com", 'Name' => "Synthetic Teacher {$n}", 'PSW' => 'secret', 'type' => $type, 'phone' => (string) (910000000 + $n)]);
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        return ['X-User-Id' => (string) $user->id, 'Accept' => 'application/json'];
    }
}
