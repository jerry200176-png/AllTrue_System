<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** POST /api/v1/swipe-photo → private storage + signed URL → LINE push to verified parents. */
class SwipePhotoTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake(['api.line.me/*' => Http::response([], 200)]);
        config(['app.url' => 'https://alltrue.example']);

        $this->campus = Campus::create([
            'name' => 'PhotoCampus', 'Token' => 'photo-token', 'code' => 'photo', 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '',
            'URL' => '', 'TelegramToken' => '', 'TelegramChatID' => '', 'TelegramURL' => '',
            'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);
        // messaging_channel_token 不在 fillable。
        DB::table('Campus')->where('id', $this->campus->id)->update(['messaging_channel_token' => 'line-token']);
        $this->student = Student::create([
            'name' => 'PhotoKid', 'CampusID' => $this->campus->id, 'ClassID' => 1,
            'RFID' => 'PHOTO-1', 'enable' => 1,
        ]);
        StudentLineBinding::create([
            'student_id' => $this->student->id, 'line_user_id' => 'Uverified',
            'campus_id' => $this->campus->id, 'bound_at' => now(), 'verified_at' => now(),
        ]);
        StudentLineBinding::create([
            'student_id' => $this->student->id, 'line_user_id' => 'Uunverified',
            'campus_id' => $this->campus->id, 'bound_at' => now(),
        ]);
    }

    private function upload(array $overrides = [], string $token = 'photo-token')
    {
        return $this->post('/api/v1/swipe-photo', array_merge([
            'branch_code' => (string) $this->campus->id,
            'rfid' => 'PHOTO-1',
            'photo' => UploadedFile::fake()->image('a.jpg', 64, 64),
        ], $overrides), ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    public function test_rejects_bad_token_unknown_rfid_and_non_image(): void
    {
        $this->upload([], 'wrong')->assertStatus(401);
        $this->upload(['rfid' => 'NOPE'])->assertStatus(404);
        $this->upload(['photo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertStatus(422);
        $this->upload(['photo' => UploadedFile::fake()->image('big.jpg')->size(2048)])->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_stores_photo_and_pushes_text_and_signed_image_only_to_verified_parent(): void
    {
        $res = $this->upload()->assertOk()->assertJson(['ok' => true, 'sent' => 1]);

        $files = Storage::disk('local')->files("swipe-photos/{$this->campus->id}");
        $this->assertCount(1, $files);

        Http::assertSentCount(1);
        $url = null;
        Http::assertSent(function ($req) use (&$url) {
            $flex = $req['messages'][0];
            $url = $flex['contents']['hero']['url'];

            // 1 則訊息：照片＋文字同一張 Flex 卡。
            return $req['to'] === 'Uverified'
                && count($req['messages']) === 1
                && $flex['type'] === 'flex'
                && $flex['altText'] === $flex['contents']['body']['contents'][0]['text']
                && $flex['contents']['hero']['aspectRatio'] === '64:64'
                && !isset($flex['contents']['hero']['action'])
                && str_starts_with($url, 'https://alltrue.example/api/v1/swipe-photo/')
                && str_contains($url, 'signature=');
        });

        $this->assertSame($url, $res->json('image_url'));

        // LINE 伺服器用簽章網址抓得到；拿掉簽章或過期就 403。
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        $this->getJson($path)->assertOk();
        $this->getJson(parse_url($url, PHP_URL_PATH))->assertForbidden();
        $this->travel(8)->days();
        $this->getJson($path)->assertForbidden();
    }

    public function test_text_says_arrive_or_leave_from_the_swipe_just_recorded(): void
    {
        $this->travelTo(today()->setTime(10, 0));
        $texts = function (): array {
            return Http::recorded()
                ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.line.me/v2/bot/message/push'))
                ->map(fn ($pair) => $pair[0]['messages'][0]['altText'])
                ->values()->all();
        };
        $swipe = fn () => $this->postJson('/api/v1/swipe-rfid', ['branch_code' => (string) $this->campus->id, 'rfid' => 'PHOTO-1'], ['Authorization' => 'Bearer photo-token']);

        $swipe()->assertJson(['action' => 'sign_in']);
        $this->upload()->assertOk();

        $this->travel(2)->hours();
        $swipe()->assertJson(['action' => 'sign_out']);
        // 簽退後 backfill 會插入較新的 presence-window 列（id 較大、時間較早），不能蓋掉離班。
        DB::table('StudentSingIn')->insert([
            'StudentID' => $this->student->id, 'CampusID' => $this->campus->id, 'Memo' => 'presence-window',
            'SignInDT' => today()->setTime(10, 30), 'SignOutDT' => today()->setTime(11, 30),
        ]);
        $this->upload()->assertOk();

        // 照片晚到超過 2 分鐘（或沒刷卡紀錄）→ 不猜到班/離班。
        $this->travel(10)->minutes();
        $this->upload()->assertOk();

        $this->assertSame([
            'PhotoKid 已於 10:00 到班',
            'PhotoKid 已於 12:00 離班',
            'PhotoKid 已於 12:10 刷卡',
        ], $texts());
    }

    public function test_no_channel_token_still_stores_without_push(): void
    {
        DB::table('Campus')->where('id', $this->campus->id)->update(['messaging_channel_token' => null]);

        $old = "swipe-photos/{$this->campus->id}/old.jpg";
        Storage::disk('local')->put($old, 'x');
        touch(Storage::disk('local')->path($old), now()->subDays(8)->getTimestamp());

        $this->upload()->assertOk()->assertJson(['sent' => 0]);
        // 新照片存了、超過 7 天的舊照片被清掉。
        $this->assertCount(1, Storage::disk('local')->files("swipe-photos/{$this->campus->id}"));
        Storage::disk('local')->assertMissing($old);
        Http::assertNothingSent();
    }

    public function test_hd_photo_is_shrunk_to_flex_limit_and_still_one_message(): void
    {
        $this->upload(['photo' => UploadedFile::fake()->image('hd.jpg', 1920, 1080)])->assertOk();

        $files = Storage::disk('local')->files("swipe-photos/{$this->campus->id}");
        [$w, $h] = getimagesize(Storage::disk('local')->path($files[0]));
        $this->assertSame([1024, 576], [$w, $h]);
        Http::assertSent(fn ($req) => count($req['messages']) === 1
            && $req['messages'][0]['contents']['hero']['aspectRatio'] === '1024:576');
    }

    public function test_huge_declared_dimensions_are_not_decoded_and_fall_back_to_text_and_image(): void
    {
        $this->upload(['photo' => UploadedFile::fake()->image('bomb.png', 5000, 5000)])->assertOk();

        $files = Storage::disk('local')->files("swipe-photos/{$this->campus->id}");
        [$w] = getimagesize(Storage::disk('local')->path($files[0]));
        $this->assertSame(5000, $w);
        Http::assertSent(fn ($req) => array_column($req['messages'], 'type') === ['text', 'image']);
    }
}
