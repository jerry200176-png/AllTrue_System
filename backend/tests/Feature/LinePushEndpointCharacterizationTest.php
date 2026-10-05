<?php

namespace Tests\Feature;

use App\Console\Commands\SendTuitionReminders;
use App\Models\Campus;
use App\Models\ScheduleDiscrepancy;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLineBinding;
use App\Services\NotificationLineDispatcher;
use App\Services\ScheduleDiscrepancyNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Characterization: every caller of the LINE push endpoint (https://api.line.me/v2/bot/message/push)
 * sends exactly this URL / Bearer token / JSON body today. Locks the wire format before the callers
 * are routed through one delivery module (arch-line-delivery). Swipe-photo is in SwipePhotoTest.
 */
class LinePushEndpointCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const PUSH = 'https://api.line.me/v2/bot/message/push';

    private function campus(string $token = 'tok-1'): Campus
    {
        $c = Campus::factory()->create();
        DB::table('Campus')->where('id', $c->id)->update(['messaging_channel_token' => $token]);

        return $c->fresh();
    }

    private function student(int $campusId): Student
    {
        return Student::create([
            'name' => '推播生', 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function bind(int $studentId, int $campusId, string $lineUserId): StudentLineBinding
    {
        return StudentLineBinding::create([
            'student_id' => $studentId, 'line_user_id' => $lineUserId, 'campus_id' => $campusId,
            'bound_at' => now(), 'verified_at' => now(), 'verification_method' => 'contact_phone',
        ]);
    }

    /** @return array{0:string,1:string,2:array} [url, bearer, body] of the only request sent. */
    private function onlyRequest(): array
    {
        Http::assertSentCount(1);
        $req = Http::recorded()->first()[0];

        return [$req->url(), $req->header('Authorization')[0] ?? '', json_decode($req->body(), true)];
    }

    public function test_notification_dispatcher_wire_format(): void
    {
        Http::fake();
        $campus = $this->campus('tok-disp');
        $uid = DB::table('User')->insertGetId([
            'LoginName' => 'd-' . uniqid() . '@t.com', 'Name' => 'S', 'PSW' => bcrypt('x'),
            'type' => 'A', 'status' => 'active', 'LineID' => 'Ustaff',
        ]);
        DB::table('UserCampus')->insert(['UserID' => $uid, 'CampusID' => $campus->id, 'Approved' => 1]);
        $n = \App\Models\Notification::create([
            'CampusID' => $campus->id, 'Type' => 'x', 'Title' => '標題', 'Body' => '內文',
            'Severity' => 'high', 'SourceKey' => 'k-' . uniqid(),
        ]);

        app(NotificationLineDispatcher::class)->dispatch($n);

        [$url, $auth, $body] = $this->onlyRequest();
        $this->assertSame(self::PUSH, $url);
        $this->assertSame('Bearer tok-disp', $auth);
        $this->assertSame(['to' => 'Ustaff', 'messages' => [['type' => 'text', 'text' => "[AllTrue] 標題\n內文"]]], $body);
    }

    public function test_dispatcher_swallows_transport_failure(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('boom'));
        $campus = $this->campus();
        $uid = DB::table('User')->insertGetId([
            'LoginName' => 'd-' . uniqid() . '@t.com', 'Name' => 'S', 'PSW' => bcrypt('x'),
            'type' => 'A', 'status' => 'active', 'LineID' => 'Ustaff',
        ]);
        DB::table('UserCampus')->insert(['UserID' => $uid, 'CampusID' => $campus->id, 'Approved' => 1]);
        $n = \App\Models\Notification::create([
            'CampusID' => $campus->id, 'Type' => 'x', 'Title' => 't', 'Severity' => 'high', 'SourceKey' => 'k-' . uniqid(),
        ]);

        app(NotificationLineDispatcher::class)->dispatch($n);
        $this->addToAssertionCount(1); // no throw
    }

    private function discrepancy(int $branchId): ScheduleDiscrepancy
    {
        return ScheduleDiscrepancy::create([
            'class_session_id' => null, 'reporter_id' => 1, 'branch_id' => $branchId,
            'discrepancy_type' => 'wrong_time', 'session_date' => '2026-10-05', 'subject' => '國文',
            'student_name' => '小明', 'time_range' => '10:00-12:00', 'notes' => 'n',
            'status' => ScheduleDiscrepancy::STATUS_PENDING,
        ]);
    }

    public function test_schedule_discrepancy_wire_format_and_4xx_is_not_retried(): void
    {
        Http::fake([self::PUSH => Http::response(['message' => 'bad'], 400)]);
        $campus = $this->campus('tok-disc');
        DB::table('Campus')->where('id', $campus->id)->update(['staff_line_group_id' => 'Cgroup']);
        $d = $this->discrepancy($campus->id);

        ScheduleDiscrepancyNotifier::notify($d);

        [$url, $auth, $body] = $this->onlyRequest(); // 400 => exactly one attempt
        $this->assertSame(self::PUSH, $url);
        $this->assertSame('Bearer tok-disc', $auth);
        $this->assertSame('Cgroup', $body['to']);
        $this->assertStringContainsString('→ 請至系統處理', $body['messages'][0]['text']);
    }

    public function test_feedback_parent_push_wire_format_and_audit(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        Http::fake([self::PUSH => Http::response([], 200)]);
        $campus = $this->campus('tok-fb');
        $s = $this->student($campus->id);
        $b = $this->bind($s->id, $campus->id, 'Uparent');
        $fb = \App\Models\LearningRecordFeedback::create([
            'learning_record_id' => 1, 'student_id' => $s->id, 'student_class_id' => 1, 'class_session_id' => null,
            'teacher_id' => 1, 'campus_id' => $campus->id, 'content' => 'c',
        ]);

        app(\App\Services\FeedbackPushNotifier::class)->notifyStaffReplied($fb);

        [$url, $auth, $body] = $this->onlyRequest();
        $this->assertSame(self::PUSH, $url);
        $this->assertSame('Bearer tok-fb', $auth);
        $this->assertSame('Uparent', $body['to']);
        $this->assertSame(
            "親愛的家長您好，\n\n老師回覆了 推播生 的學習回饋，歡迎至家長系統查看。\n\n如不想再收到此類通知，可於家長系統設定中關閉。",
            $body['messages'][0]['text']
        );
        $this->assertSame(1, count($body['messages']));
        $this->assertAuditEvent('learning_feedback', 'success', 'delivered');
    }

    public function test_feedback_failed_push_records_failure_audit_and_no_push_log(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        Http::fake([self::PUSH => Http::response('err', 500)]);
        $campus = $this->campus();
        $s = $this->student($campus->id);
        $this->bind($s->id, $campus->id, 'Uparent');
        $fb = \App\Models\LearningRecordFeedback::create([
            'learning_record_id' => 1, 'student_id' => $s->id, 'student_class_id' => 1, 'class_session_id' => null,
            'teacher_id' => 1, 'campus_id' => $campus->id, 'content' => 'c',
        ]);

        app(\App\Services\FeedbackPushNotifier::class)->notifyStaffReplied($fb);

        $this->assertAuditEvent('learning_feedback', 'failure', 'failed');
        $this->assertDatabaseMissing('feedback_push_log', ['feedback_id' => $fb->id, 'direction' => 'to_parent']);
    }

    public function test_tuition_reminder_wire_format_and_audit(): void
    {
        Http::fake([self::PUSH => Http::response([], 200)]);
        $campus = $this->campus('tok-tui');
        $s = $this->student($campus->id);
        $this->bind($s->id, $campus->id, 'Uparent');
        $this->bind($s->id, $campus->id, 'Uparent2');
        StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 0,
            'MDate' => now()->subDays(30), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);

        $this->artisan('tuition:send-reminders')->assertExitCode(0);

        Http::assertSentCount(2);
        $tos = [];
        Http::assertSent(function ($req) use (&$tos) {
            $tos[] = $req['to'];

            return $req->url() === self::PUSH && $req->header('Authorization')[0] === 'Bearer tok-tui'
                && $req['messages'] === [['type' => 'text', 'text' =>
                    "親愛的家長您好，\n\n提醒您：推播生 同學的「課程」課程尚未完成繳費。\n\n請盡速聯繫補習班完成繳費，以確保課程正常進行。\n\n如已繳費請忽略此訊息，謝謝！"]];
        });
        sort($tos);
        $this->assertSame(['Uparent', 'Uparent2'], $tos);
        $this->assertSame(2, DB::table('security_audit_events')->where('outcome', 'success')->count());
    }

    public function test_tuition_dry_run_sends_nothing(): void
    {
        Http::fake();
        $this->artisan('tuition:send-reminders', ['--dry-run' => true])->assertExitCode(0);
        Http::assertNothingSent();
    }

    private function webhook(Campus $campus, array $event)
    {
        $secret = 'sec';
        DB::table('Campus')->where('id', $campus->id)->update(['messaging_channel_secret' => $secret]);
        $body = json_encode(['events' => [$event]]);
        $sig = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return $this->call('POST', "/api/v1/line/webhook/{$campus->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => $sig,
        ], $body);
    }

    public function test_webhook_follow_unbound_pushes_text_welcome(): void
    {
        Http::fake([self::PUSH => Http::response([], 200)]);
        $campus = $this->campus('tok-wh');

        $this->webhook($campus, ['type' => 'follow', 'source' => ['userId' => 'Unew']])->assertOk();

        [$url, $auth, $body] = $this->onlyRequest();
        $this->assertSame(self::PUSH, $url);
        $this->assertSame('Bearer tok-wh', $auth);
        $this->assertSame('Unew', $body['to']);
        $this->assertSame('text', $body['messages'][0]['type']);
        $this->assertStringContainsString('請輸入「綁定 學生姓名 家長手機」', $body['messages'][0]['text']);
    }

    public function test_webhook_follow_bound_pushes_flex_welcome_back(): void
    {
        Http::fake([self::PUSH => Http::response([], 200)]);
        $campus = $this->campus('tok-wh');
        $s = $this->student($campus->id);
        $this->bind($s->id, $campus->id, 'Ubound');

        $this->webhook($campus, ['type' => 'follow', 'source' => ['userId' => 'Ubound']])->assertOk();

        [$url, $auth, $body] = $this->onlyRequest();
        $this->assertSame(self::PUSH, $url);
        $this->assertSame('Bearer tok-wh', $auth);
        $this->assertSame('Ubound', $body['to']);
        $this->assertSame('flex', $body['messages'][0]['type']);
        $this->assertSame('歡迎回來！', $body['messages'][0]['contents']['header']['contents'][0]['text']);
        $this->assertSame('查看學習狀況', $body['messages'][0]['contents']['footer']['contents'][0]['action']['label']);
    }

    public function test_only_line_push_module_knows_the_push_endpoint(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')
                && str_contains(file_get_contents($f->getPathname()), 'message/push')) {
                $hits[] = $f->getFilename();
            }
        }
        $this->assertSame(['LinePush.php'], $hits);
    }

    public function test_parent_bindings_are_verified_and_same_campus_only(): void
    {
        $a = $this->campus();
        $b = $this->campus();
        $s = $this->student($a->id);
        $this->bind($s->id, $a->id, 'Uok');
        $this->bind($s->id, $b->id, 'Uother-campus');
        StudentLineBinding::create(['student_id' => $s->id, 'line_user_id' => 'Uunverified', 'campus_id' => $a->id, 'bound_at' => now()]);

        $ids = app(\App\Services\Line\ParentLinePush::class)->bindings($s->id, $a->id)->pluck('line_user_id')->all();

        $this->assertSame(['Uok'], $ids);
    }

    private function assertAuditEvent(string $type, string $outcome, string $status): void
    {
        $row = DB::table('security_audit_events')->where('event_type', 'notification.delivery')->first();
        $this->assertNotNull($row);
        $this->assertSame($outcome, $row->outcome);
        $meta = json_decode($row->metadata, true);
        $this->assertSame($type, $meta['notification_type']);
        $this->assertSame($status, $meta['delivery_status']);
        $this->assertSame('line_push', $meta['method']);
    }
}
