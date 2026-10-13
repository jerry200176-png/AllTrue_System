<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Services\NotificationSyncService;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #3376: the director notification must be a schema-valid, idempotent campus row. */
class TuitionReminderDirectorNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Campus,1:User,2:StudentClass,3:Student} */
    private function fixture(): array
    {
        $campus = Campus::factory()->create();
        $director = User::create([
            'LoginName' => 'tuition-rem-'.uniqid('', true).'@example.com', 'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT), 'type' => 'D',
            'phone' => '09'.random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 0, 'Approved' => 1]);
        $s = Student::create(['name' => 'S', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 0,
            'MDate' => now()->subDays(30), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);

        return [$campus, $director, $course, $s];
    }

    private function addCourse(Student $s): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 2, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 3000, 'Paid' => 0,
            'MDate' => now()->subDays(30), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    private function markRead(Notification $n, User $u): void
    {
        NotificationRead::create(['NotificationID' => $n->id, 'UserID' => $u->id, 'ReadAt' => now()]);
    }

    private function aggregate(): Notification
    {
        return Notification::where('SourceType', 'TuitionReminder')->firstOrFail();
    }

    public function test_double_run_yields_one_live_row_per_course(): void
    {
        [$campus] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);

        $rows = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->ResolvedAt);
    }

    public function test_command_then_sync_keeps_one_live_actionable_alert_per_course(): void
    {
        [$campus, , $course, $s] = $this->fixture();
        $second = $this->addCourse($s);
        NotificationSyncService::sync([$campus->id]); // cooldown scenario: per-course rows already exist

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        NotificationSyncService::sync([$campus->id]);
        NotificationSyncService::sync();

        $live = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->pluck('SourceKey')->sort()->values()->all();
        $this->assertSame(
            collect(["tuition:{$campus->id}:{$course->ID}", "tuition:{$campus->id}:{$second->ID}"])->sort()->values()->all(),
            $live
        );
        $this->assertSame(0, Notification::where('SourceType', 'TuitionReminder')->count());
    }

    public function test_paid_course_is_retired_by_the_next_run(): void
    {
        [$campus, , $course] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $course->forceFill(['Paid' => 1])->save();
        NotificationSyncService::sync([$campus->id]);

        $this->assertSame(0, Notification::where('Type', 'tuition')->whereNull('ResolvedAt')->count());
    }

    /** @return array<string, array{0: callable(StudentClass, Student): void}> */
    public static function visibleChanges(): array
    {
        return [
            'title (student name)' => [fn ($c, $s) => $s->forceFill(['name' => 'Renamed'])->save()],
            'body (remaining sessions)' => [fn ($c, $s) => $c->forceFill(['RemainingSessions' => 2])->save()],
            'owed amount' => [fn ($c, $s) => $c->forceFill(['Charge' => 7000])->save()],
            'deep-link payload (student id)' => [function ($c, $s) {
                $other = Student::create(['name' => $s->name, 'CampusID' => $s->CampusID, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
                $c->forceFill(['StudentID' => $other->id])->save();
            }],
        ];
    }

    /** @dataProvider visibleChanges */
    public function test_director_visible_change_is_unread_again(callable $change): void
    {
        [$campus, $director, $course, $s] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);

        $change($course, $s);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(0, NotificationRead::count());
    }

    public function test_unchanged_rerun_keeps_read_and_reopen_is_unread(): void
    {
        [$campus, $director, $course] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(1, NotificationRead::count(), 'unchanged rerun keeps read state');

        $course->forceFill(['Paid' => 1])->save();
        NotificationSyncService::sync([$campus->id]);
        $course->forceFill(['Paid' => 0])->save();
        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(0, NotificationRead::count(), 'reopened alert is unread');
    }

    public function test_severity_only_change_is_unread_again_and_no_change_keeps_read(): void
    {
        [$campus, $director, $course] = $this->fixture();
        NotificationSyncService::sync([$campus->id]);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);

        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(1, NotificationRead::count(), 'nothing changed: read kept');

        $n->forceFill(['Severity' => 'low'])->save(); // only Severity differs from what sync derives
        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(0, NotificationRead::count(), 'severity-only change: unread again');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function tamperedFields(): array
    {
        return [
            'Title' => ['Title', 'stale title'],
            'Body' => ['Body', 'stale body'],
            'Severity' => ['Severity', 'low'],
            'Payload.outstanding' => ['Payload', 'outstanding'],
        ];
    }

    /**
     * Isolated read-reset: the per-course notice and its read row are pre-created directly (no command).
     * Exactly one stored field differs from what sync derives.
     *
     * @dataProvider tamperedFields
     */
    public function test_sync_resets_read_when_one_stored_field_differs(string $field, string $value): void
    {
        [$campus, $director, $course] = $this->fixture();
        NotificationSyncService::sync([$campus->id]);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);
        if ($field === 'Payload') {
            $n->Payload = array_merge($n->Payload, [$value => 1]);
        } else {
            $n->{$field} = $value;
        }
        $n->save();

        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(0, NotificationRead::count());
    }

    public function test_transferred_student_alert_follows_current_campus_only(): void
    {
        [$campus, , $course, $s] = $this->fixture();
        NotificationSyncService::sync();
        $other = Campus::factory()->create();
        $s->forceFill(['CampusID' => $other->id])->save();
        NotificationSyncService::sync();

        $live = Notification::where('Type', 'tuition')->whereNull('ResolvedAt')->pluck('SourceKey')->all();
        $this->assertContains("tuition:{$other->id}:{$course->ID}", $live);
        $this->assertNotContains("tuition:{$campus->id}:{$course->ID}", $live);
    }

    /** A user-scoped sync never writes another campus; the command retires the transferred notice even once paid. */
    public function test_command_retires_a_transferred_students_old_campus_notice_without_touching_other_debts(): void
    {
        [$campus, , $course, $s] = $this->fixture();
        $unrelated = $this->addCourse(Student::create([
            'name' => '同校學生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]));
        NotificationSyncService::sync([$campus->id]);
        $other = Campus::factory()->create();
        $s->forceFill(['CampusID' => $other->id])->save();
        $course->forceFill(['Paid' => 1])->save(); // the debt no longer qualifies anywhere

        NotificationSyncService::sync([$other->id]);
        $open = fn () => Notification::where('Type', 'tuition')->whereNull('ResolvedAt')->pluck('SourceKey')->all();
        $this->assertContains("tuition:{$campus->id}:{$course->ID}", $open(), 'a scoped sync leaves the other campus alone');

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertNotContains("tuition:{$campus->id}:{$course->ID}", $open(), 'old campus no longer shows the transferred debt');
        $this->assertContains("tuition:{$campus->id}:{$unrelated->ID}", $open(), 'other old-campus debts are untouched');
    }

    /** @return array<string, array{0: string}> */
    public static function scheduleModes(): array
    {
        return ['date-mode, past cutoff, no invoice' => ['date'], 'count-mode, no invoice' => ['count']];
    }

    /**
     * The command reminds parents of every effectively-unpaid course; the director must have exactly one alert for it.
     *
     * @dataProvider scheduleModes
     */
    public function test_every_course_the_command_reminds_has_a_single_director_alert(string $mode): void
    {
        [$campus, , $course] = $this->fixture();
        $course->forceFill(['ScheduleMode' => $mode, 'settlement_day' => (int) now()->day])->save();

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);

        $rows = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->get();
        $this->assertCount(1, $rows);
        $this->assertSame("tuition:{$campus->id}:{$course->ID}", $rows[0]->SourceKey);
    }

    public function test_count_mode_course_with_overdue_invoice_has_one_notice_for_the_debt(): void
    {
        [$campus, , $course, $s] = $this->fixture();
        $inv = \App\Models\Invoice::create([
            'StudentID' => $s->id, 'StudentClassID' => $course->ID, 'IssueDate' => now()->subDays(10)->toDateString(),
            'DueDate' => now()->subDays(2)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        NotificationSyncService::sync([$campus->id]);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);

        $live = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->pluck('SourceKey')->all();
        $this->assertSame(["invoice_overdue:{$campus->id}:{$inv->id}"], $live);
        $this->assertNull(Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->whereNull('ResolvedAt')->first());

        $this->artisan('tuition:send-reminders')->assertExitCode(0); // rerun: same stable key
        $this->assertSame($live, Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->pluck('SourceKey')->all());

        $course->forceFill(['Paid' => 1])->save();
        $inv->forceFill(['Status' => 'paid', 'PaidAmount' => 5000])->save();
        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(0, Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->count(), 'settled debt retires');
    }

    /** @return array<string, array{0: ?int, 1: bool}> settlement_day offset from 2026-10-15 => expected alert */
    public static function monthlyWindow(): array
    {
        return [
            'no settlement_day' => [null, false],
            'due in 10 days (outside 0-4)' => [25, false],
            'due in 5 days (day 5 excluded)' => [20, false],
            'due in 4 days' => [19, true],
            'due today' => [15, true],
            'already past this month' => [10, true],
        ];
    }

    /** @dataProvider monthlyWindow */
    public function test_date_mode_notice_follows_the_monthly_billing_window(?int $settlementDay, bool $expected): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-15 09:00:00');
        [$campus, , $course] = $this->fixture();
        $course->forceFill(['ScheduleMode' => 'date', 'settlement_day' => $settlementDay])->save();

        NotificationSyncService::sync([$campus->id]);

        $this->assertSame($expected ? 1 : 0, Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->whereNull('ResolvedAt')->count());
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_course_notice_without_invoice_carries_a_consumer_supported_course_key(): void
    {
        [$campus, , $course] = $this->fixture();
        NotificationSyncService::sync([$campus->id]);

        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->assertSame((int) $course->ID, (int) ($n->Payload['student_class_id'] ?? 0));
    }

    public function test_overdue_partial_invoice_notice_wins_and_keeps_payment_details(): void
    {
        [$campus, , $course, $s] = $this->fixture();
        $inv = \App\Models\Invoice::create([
            'StudentID' => $s->id, 'StudentClassID' => $course->ID, 'IssueDate' => now()->subDays(20)->toDateString(),
            // Stored columns are stale on purpose (#3885 review): the Payment rows are the truth.
            'DueDate' => now()->subDays(9)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        \App\Models\Payment::create(['InvoiceID' => $inv->id, 'Amount' => 2500, 'PaidAt' => now()->subDays(5), 'Method' => 'cash', 'Note' => '']);
        \App\Models\Payment::create(['InvoiceID' => $inv->id, 'Amount' => -500, 'PaidAt' => now()->subDays(4), 'Method' => 'cash', 'Note' => '']);
        NotificationSyncService::sync([$campus->id]);

        $live = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->get();
        $this->assertCount(1, $live, 'one notice for the debt');
        $n = $live->first();
        $this->assertSame("invoice_overdue:{$campus->id}:{$inv->id}", $n->SourceKey);
        $this->assertSame((int) $course->ID, (int) ($n->Payload['student_class_id'] ?? 0));
        $this->assertSame(2000, (int) ($n->Payload['paid_amount'] ?? -1));
        $this->assertSame('partial', $n->Payload['status'] ?? null);
        $this->assertSame(9, (int) ($n->Payload['overdue_days'] ?? 0));
        $this->assertStringContainsString('已繳 2000', (string) $n->Body);
    }

    /** #3885 review: the winning invoice notice shows the canonical projection's total, not the stored column. */
    public function test_invoice_notice_uses_the_canonical_invoice_total(): void
    {
        [$campus, , $course, $s] = $this->fixture();
        $inv = \App\Models\Invoice::create([
            'StudentID' => $s->id, 'StudentClassID' => $course->ID, 'IssueDate' => now()->subDays(20)->toDateString(),
            'DueDate' => now()->subDays(9)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        $real = app(\App\Services\InvoiceAmountReconciliationService::class);
        $this->mock(\App\Services\InvoiceAmountReconciliationService::class, function ($m) use ($real, $inv) {
            $m->shouldReceive('preloadSessions')->andReturnUsing(fn ($ids) => $real->preloadSessions($ids));
            $m->shouldReceive('forgetPreloadedSessions')->andReturnUsing(fn () => $real->forgetPreloadedSessions());
            $m->shouldReceive('resolve')->andReturnUsing(fn ($invoice, $course = null) => (int) $invoice->id === (int) $inv->id
                ? ['total_amount' => 3600, 'applied_amount' => 0, 'outstanding_amount' => 3600] + $real->resolve($invoice, $course)
                : $real->resolve($invoice, $course));
        });
        NotificationSyncService::sync([$campus->id]);

        $n = Notification::where('SourceKey', "invoice_overdue:{$campus->id}:{$inv->id}")->firstOrFail();
        $this->assertSame(3600, (int) ($n->Payload['total_amount'] ?? -1));
        $this->assertStringContainsString('應繳 3600', (string) $n->Body);
    }

    public function test_suppressed_then_reopened_course_notice_is_unread_again(): void
    {
        [$campus, $director, $course, $s] = $this->fixture();
        // The course notice exists and was read (no overdue invoice yet)
        NotificationSyncService::sync([$campus->id]);
        $courseN = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($courseN, $director);
        // An overdue invoice appears: the invoice notice wins, the course notice is suppressed (resolved)
        $inv = \App\Models\Invoice::create([
            'StudentID' => $s->id, 'StudentClassID' => $course->ID, 'IssueDate' => now()->subDays(10)->toDateString(),
            'DueDate' => now()->subDays(2)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        NotificationSyncService::sync([$campus->id]);
        $this->assertNotNull($courseN->fresh()->ResolvedAt);
        // The invoice is voided while the course still owes: the course notice reopens, unread
        $inv->forceFill(['Status' => 'void'])->save();
        NotificationSyncService::sync([$campus->id]);

        $this->assertNull($courseN->fresh()->ResolvedAt);
        $this->assertSame(0, NotificationRead::where('NotificationID', $courseN->id)->count());
    }

    /** @return array<string, array{0: int, 1: int, 2: bool}> settlement_day, open invoice due offset (days from 2026-10-15) => expected alert */
    public static function monthlyInvoiceWindow(): array
    {
        return [
            'invoice due in 2 days, settlement_day 10 days away' => [25, 2, true],
            'invoice due in 10 days, settlement_day already past' => [10, 10, false],
            'invoice due in 5 days (day 5 excluded)' => [15, 5, false],
            'invoice overdue, settlement_day 10 days away' => [25, -3, true],
            'invoice due in 2 days but no valid settlement_day' => [0, 2, false],
        ];
    }

    /** @dataProvider monthlyInvoiceWindow */
    public function test_date_mode_window_uses_the_earliest_open_invoice_due_date(int $settlementDay, int $dueOffset, bool $expected): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-15 09:00:00');
        [$campus, , $course, $s] = $this->fixture();
        $course->forceFill(['ScheduleMode' => 'date', 'settlement_day' => $settlementDay])->save();
        \App\Models\Invoice::create([
            'StudentID' => $s->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01',
            'DueDate' => now()->addDays($dueOffset)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        // A later open invoice must not move the window.
        \App\Models\Invoice::create([
            'StudentID' => $s->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01',
            'DueDate' => now()->addDays($dueOffset + 31)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);

        NotificationSyncService::sync([$campus->id]);

        $live = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->count();
        $this->assertSame($expected ? 1 : 0, $live);
        \Illuminate\Support\Carbon::setTestNow();
    }
}
