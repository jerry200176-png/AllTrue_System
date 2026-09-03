<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\LearningRecordFeedback;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLineBinding;
use App\Services\FeedbackPushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Multi-parent LINE binding consistency (no ParentIdentity / no parent_phone_2):
 * - same student may have two verified LINE bindings
 * - notification prefs are per-binding (dad opt-out must not flip mom)
 * - important LINE pushes fan-out to all verified bindings
 * - legacy Student.LineID is not canonical / not overwritten on bind
 */
class MultiParentLineBindingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function campusWithToken(string $token = 'MULTI-PARENT-TOKEN'): Campus
    {
        $campus = Campus::factory()->create();
        DB::table('Campus')->where('id', $campus->id)->update([
            'messaging_channel_token' => $token,
            'messaging_channel_secret' => 'multi-parent-secret',
        ]);

        return $campus->fresh();
    }

    private function studentWithPhone(Campus $campus, string $name = '多家長測試生', string $phone = '0912345678'): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => '',
            'parent_phone' => $phone,
            'LineID' => null,
        ]);
    }

    private function bind(Student $student, Campus $campus, string $lineUserId, bool $notify = true): StudentLineBinding
    {
        return StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $lineUserId,
            'campus_id' => (int) $campus->id,
            'bound_at' => now(),
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
            'notify_learning_feedback' => $notify ? 1 : 0,
        ]);
    }

    /** @var array<string, string> */
    private array $lineLoginProfiles = [];

    private function loginLine(string $lineUserId): string
    {
        $accessToken = 'tok-' . $lineUserId;
        $this->lineLoginProfiles[$accessToken] = $lineUserId;

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (!str_contains($request->url(), 'api.line.me/v2/profile')) {
                return Http::response([], 200);
            }
            $header = $request->header('Authorization');
            $auth = is_array($header) ? (string) ($header[0] ?? '') : (string) $header;
            $token = trim((string) preg_replace('/^Bearer\s+/i', '', $auth));
            $uid = $this->lineLoginProfiles[$token] ?? '';
            if ($uid === '') {
                return Http::response(['message' => 'invalid'], 401);
            }

            return Http::response(['userId' => $uid], 200);
        });

        $login = $this->postJson('/api/v1/parent/login-line', ['access_token' => $accessToken]);
        $login->assertOk();

        return (string) $login->json('token');
    }

    private function postLineBind(Campus $campus, string $lineUserId, string $text): void
    {
        $secret = 'multi-parent-secret';
        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'source' => ['userId' => $lineUserId],
                'message' => ['type' => 'text', 'text' => $text],
                'replyToken' => 'reply-token-mp',
            ]],
        ]);
        $sig = base64_encode(hash_hmac('sha256', $body, $secret, true));
        Http::fake(['api.line.me/*' => Http::response([], 200)]);
        $this->call(
            'POST',
            "/api/v1/line/webhook/{$campus->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_LINE_SIGNATURE' => $sig,
            ],
            $body
        )->assertOk();
    }

    public function test_two_parents_can_bind_same_student_without_overwriting_legacy_lineid(): void
    {
        $campus = $this->campusWithToken();
        $student = $this->studentWithPhone($campus);
        $dad = 'Udad11111111111111111111111111111';
        $mom = 'Umom22222222222222222222222222222';

        $this->postLineBind($campus, $dad, "綁定 {$student->name} 0912345678");
        $this->postLineBind($campus, $mom, "綁定 {$student->name} 0912345678");

        $this->assertSame(2, StudentLineBinding::where('student_id', $student->id)->verified()->count());
        $this->assertTrue(StudentLineBinding::where(['student_id' => $student->id, 'line_user_id' => $dad])->verified()->exists());
        $this->assertTrue(StudentLineBinding::where(['student_id' => $student->id, 'line_user_id' => $mom])->verified()->exists());

        $student->refresh();
        $this->assertTrue(
            $student->LineID === null || $student->LineID === '',
            'Legacy Student.LineID must not be treated as canonical multi-parent source'
        );
    }

    public function test_notification_preference_updates_only_current_parent_binding(): void
    {
        $campus = $this->campusWithToken();
        $student = $this->studentWithPhone($campus);
        $dad = 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
        $mom = 'Ubbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb2';
        $this->bind($student, $campus, $dad, true);
        $this->bind($student, $campus, $mom, true);

        $dadToken = $this->loginLine($dad);
        $this->assertSame(
            $dad,
            \App\Models\ParentSession::query()->latest('id')->value('line_user_id'),
            'LINE login must store the authenticated line_user_id on ParentSession'
        );

        $this->putJson('/api/v1/parent/notification-preferences', [
            'learning_feedback_push' => false,
        ], [
            'Authorization' => 'Bearer ' . $dadToken,
        ])->assertOk()->assertJson([
            'learning_feedback_push' => false,
            'binding_scoped' => true,
        ]);

        $this->assertFalse((bool) StudentLineBinding::where('line_user_id', $dad)->value('notify_learning_feedback'));
        $this->assertTrue((bool) StudentLineBinding::where('line_user_id', $mom)->value('notify_learning_feedback'));

        $momToken = $this->loginLine($mom);
        $this->assertSame(
            $mom,
            \App\Models\ParentSession::query()->latest('id')->value('line_user_id'),
            'Second parent LINE login must store a different line_user_id'
        );

        $this->getJson('/api/v1/parent/notification-preferences', [
            'Authorization' => 'Bearer ' . $momToken,
        ])->assertOk()->assertJson([
            'learning_feedback_push' => true,
            'binding_scoped' => true,
            'line_linked' => true,
        ]);
    }

    public function test_learning_feedback_push_reaches_both_verified_parents(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        Http::fake(['api.line.me/*' => Http::response([], 200)]);

        $campus = $this->campusWithToken();
        $student = $this->studentWithPhone($campus);
        $dad = 'Udad55555555555555555555555555555';
        $mom = 'Umom66666666666666666666666666666';
        $this->bind($student, $campus, $dad, true);
        $this->bind($student, $campus, $mom, true);

        $fb = LearningRecordFeedback::create([
            'learning_record_id' => 9001,
            'student_id' => (int) $student->id,
            'student_class_id' => 1,
            'class_session_id' => null,
            'teacher_id' => 1,
            'campus_id' => (int) $campus->id,
            'content' => '家長留言',
        ]);

        app(FeedbackPushNotifier::class)->notifyStaffReplied($fb);

        $sentTo = [];
        Http::assertSent(function ($request) use (&$sentTo) {
            if (!str_contains($request->url(), 'api.line.me')) {
                return false;
            }
            $sentTo[] = $request->data()['to'] ?? null;
            return true;
        });
        $this->assertEqualsCanonicalizing([$dad, $mom], array_values(array_filter($sentTo)));
    }

    public function test_tuition_reminder_fans_out_to_all_verified_bindings(): void
    {
        Http::fake(['api.line.me/*' => Http::response([], 200)]);

        $campus = $this->campusWithToken();
        $student = $this->studentWithPhone($campus);
        $dad = 'Udad77777777777777777777777777777';
        $mom = 'Umom88888888888888888888888888888';
        $this->bind($student, $campus, $dad, true);
        $this->bind($student, $campus, $mom, true);

        StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 1,
            'StartDate' => now()->subDays(30)->toDateString(),
            'TotalHours' => 1,
            'Charge' => 1000,
            'Paid' => 0,
            'Stop' => 0,
            'ClassType' => 'one_on_one',
            'ScheduleMode' => 'date',
            'SessionCount' => 1,
            'RemainingSessions' => 1,
            'UsedSessions' => 0,
            'MDate' => now()->subDays(14),
        ]);

        Artisan::call('tuition:send-reminders', ['--overdue-days' => 7]);

        $sentTo = [];
        Http::assertSent(function ($request) use (&$sentTo) {
            if (!str_contains($request->url(), 'api.line.me')) {
                return false;
            }
            $sentTo[] = $request->data()['to'] ?? null;
            return true;
        });
        $this->assertEqualsCanonicalizing([$dad, $mom], array_values(array_filter($sentTo)));
    }

    public function test_phone_login_cannot_bulk_update_multi_parent_preferences(): void
    {
        $campus = $this->campusWithToken();
        $student = $this->studentWithPhone($campus, '電話登入多家長生', '0987654321');
        $dad = 'Uccccccccccccccccccccccccccccccc3';
        $mom = 'Uddddddddddddddddddddddddddddddd4';
        $this->bind($student, $campus, $dad, true);
        $this->bind($student, $campus, $mom, true);

        $login = $this->postJson('/api/v1/parent/login', [
            'StudentID' => $student->id,
            'Phone' => '0987654321',
        ]);
        $login->assertOk();
        $token = (string) $login->json('token');

        $this->putJson('/api/v1/parent/notification-preferences', [
            'learning_feedback_push' => false,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(422);

        $this->assertTrue((bool) StudentLineBinding::where('line_user_id', $dad)->value('notify_learning_feedback'));
        $this->assertTrue((bool) StudentLineBinding::where('line_user_id', $mom)->value('notify_learning_feedback'));
    }

    public function test_stale_line_session_cannot_update_remaining_parent_preferences(): void
    {
        $campus = $this->campusWithToken();
        $student = $this->studentWithPhone($campus, '失效綁定偏好生', '0911222333');
        $dad = 'Ueeeeeeeeeeeeeeeeeeeeeeeeeeeeeee5';
        $mom = 'Ufffffffffffffffffffffffffffffff6';
        $dadBinding = $this->bind($student, $campus, $dad, true);
        $this->bind($student, $campus, $mom, true);

        $dadToken = $this->loginLine($dad);
        $dadBinding->delete();

        $this->putJson('/api/v1/parent/notification-preferences', [
            'learning_feedback_push' => false,
        ], [
            'Authorization' => 'Bearer ' . $dadToken,
        ])->assertStatus(422);

        $this->assertTrue(
            (bool) StudentLineBinding::where('line_user_id', $mom)->value('notify_learning_feedback'),
            'Revoked LINE session must not mutate the remaining parent binding'
        );
        $this->assertSame(1, StudentLineBinding::where('student_id', $student->id)->verified()->count());
    }
}
