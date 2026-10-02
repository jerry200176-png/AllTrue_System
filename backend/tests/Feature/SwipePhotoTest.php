<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Models\StudentSignIn;
use App\Support\LineNotifySettings;
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
        LineNotifySettings::set($this->campus->id, ['swipe_in' => true, 'swipe_out' => true]);
    }

    private function upload(array $overrides = [], string $token = 'photo-token')
    {
        return $this->post('/api/v1/swipe-photo', array_merge([
            'branch_code' => (string) $this->campus->id,
            'rfid' => 'PHOTO-1',
            'photo' => UploadedFile::fake()->image('a.jpg', 64, 64),
        ], $overrides), ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function swipeRfid()
    {
        return $this->postJson('/api/v1/swipe-rfid', ['branch_code' => (string) $this->campus->id, 'rfid' => 'PHOTO-1'], ['Authorization' => 'Bearer photo-token']);
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
        $this->swipeRfid()->assertJson(['action' => 'sign_in']);
        $res = $this->upload()->assertOk()->assertJson(['ok' => true, 'sent' => 1]);

        $files = Storage::disk('local')->files("swipe-photos/{$this->campus->id}");
        $this->assertCount(1, $files);

        Http::assertSentCount(1);
        $url = null;
        Http::assertSent(function ($req) use (&$url) {
            $flex = $req['messages'][0];
            $url = $flex['contents']['hero']['url'];

            return $req['to'] === 'Uverified'
                && count($req['messages']) === 1
                && $flex['type'] === 'flex'
                && $flex['altText'] === $flex['contents']['body']['contents'][0]['text']
                && $flex['contents']['hero']['action']['uri'] === $url
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

    public function test_neutral_text_and_campus_switches_for_arrive_and_leave(): void
    {
        LineNotifySettings::set($this->campus->id, ['swipe_in' => true, 'swipe_out' => false]);
        $this->travelTo(today()->setTime(10, 0));
        $texts = fn (): array => Http::recorded()
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.line.me/v2/bot/message/push'))
            ->map(fn ($pair) => $pair[0]['messages'][0]['altText'])
            ->values()->all();
        $swipe = fn () => $this->postJson('/api/v1/swipe-rfid', ['branch_code' => (string) $this->campus->id, 'rfid' => 'PHOTO-1'], ['Authorization' => 'Bearer photo-token']);
        $photos = fn (): int => count(Storage::disk('local')->files("swipe-photos/{$this->campus->id}"));

        // 到班開 → 發；文字中性（誤刷也不會講錯到班/離班）
        $swipe()->assertJson(['action' => 'sign_in']);
        $this->upload()->assertOk()->assertJson(['sent' => 1]);

        // 離班關 → 不發、不存照片
        $this->travel(2)->hours();
        $swipe()->assertJson(['action' => 'sign_out']);
        $this->upload()->assertOk()->assertJson(['sent' => 0, 'skipped' => 'disabled']);
        $this->assertSame(1, $photos());

        // 對不起來（照片晚到超過 2 分鐘）→ fail closed，不發不存
        $this->travel(10)->minutes();
        $this->upload()->assertOk()->assertJson(['sent' => 0, 'skipped' => 'uncorrelated']);
        $this->assertSame(1, $photos());

        // 再到班，但到班開關也關 → 不發
        $this->travel(1)->hours();
        LineNotifySettings::set($this->campus->id, ['swipe_in' => false]);
        $swipe()->assertJson(['action' => 'sign_in']);
        $this->upload()->assertOk()->assertJson(['skipped' => 'disabled']);

        $this->assertSame(['PhotoKid 10:00 刷卡'], $texts());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unsafeClosedRecords(): array
    {
        return [
            'other campus (same-day transfer)' => [['CampusID' => 'other']],
            'unknown campus (pending-swipe match)' => [['CampusID' => null]],
            'voided row' => [['VoidedAt' => 'now']],
        ];
    }

    /**
     * @dataProvider unsafeClosedRecords
     * @param array<string, mixed> $patch
     */
    public function test_photo_after_sign_out_of_unsafe_record_is_not_sent(array $patch): void
    {
        // 跟 swipe-rfid 的 LineIDs 同一個規則：剛簽退的不是本校未作廢紀錄 → 不發假的離班卡片、不存照片。
        $swipe = fn () => $this->postJson('/api/v1/swipe-rfid', ['branch_code' => (string) $this->campus->id, 'rfid' => 'PHOTO-1'], ['Authorization' => 'Bearer photo-token']);
        $swipe()->assertJson(['action' => 'sign_in']);
        $patch = array_map(fn ($v) => match ($v) {
            'other' => $this->campus->id + 100,
            'now' => now(),
            default => $v,
        }, $patch);
        StudentSignIn::where('StudentID', $this->student->id)->update($patch);
        Http::fake(['api.line.me/*' => Http::response([], 200)]); // 清掉簽到時的紀錄

        $this->travel(5)->minutes();
        $swipe()->assertJson(['action' => 'sign_out']);
        $this->upload()->assertOk()->assertJson(['sent' => 0, 'skipped' => 'unsafe_record']);
        // 照片晚到（超過 2 分鐘）也一樣不發
        $this->travel(10)->minutes();
        $this->upload()->assertOk()->assertJson(['sent' => 0, 'skipped' => 'unsafe_record']);

        $this->assertSame([], Storage::disk('local')->files("swipe-photos/{$this->campus->id}"));
        Http::assertNothingSent();
    }

    public function test_presence_window_backfill_rows_do_not_hide_an_unsafe_sign_out(): void
    {
        // 簽退時會補建本校 presence-window 列（id 較新）；照片要對到真正被簽退的別校紀錄，不能被補建列蓋掉。
        $this->travelTo(today()->setTime(10, 0));
        $swipe = fn () => $this->postJson('/api/v1/swipe-rfid', ['branch_code' => (string) $this->campus->id, 'rfid' => 'PHOTO-1'], ['Authorization' => 'Bearer photo-token']);
        $swipe()->assertJson(['action' => 'sign_in']);
        StudentSignIn::where('StudentID', $this->student->id)->update(['CampusID' => $this->campus->id + 100]);

        $sc = StudentClass::create([
            'StudentID' => $this->student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 0,
            'TotalHours' => 2, 'StartDate' => now()->subYear(), 'Stop' => 0, 'SessionCount' => 10, 'ScheduleMode' => 'count',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => today()->toDateString(),
            'StartTime' => '10:30:00', 'EndTime' => '11:30:00', 'Status' => 'scheduled',
        ]);

        $this->travelTo(today()->setTime(12, 0));
        $swipe()->assertJson(['action' => 'sign_out']);
        $this->assertSame(1, StudentSignIn::where('StudentID', $this->student->id)->where('Memo', 'presence-window')->count());

        $this->upload()->assertOk()->assertJson(['sent' => 0, 'skipped' => 'unsafe_record']);
        $this->assertSame([], Storage::disk('local')->files("swipe-photos/{$this->campus->id}"));
    }

    public function test_photo_without_a_matching_rfid_swipe_fails_closed(): void
    {
        // 今天沒有 RFID 刷卡列 → 對不起來，不存不推
        $this->upload()->assertOk()->assertJson(['sent' => 0, 'skipped' => 'uncorrelated']);

        // 人工／待配對建的列（Memo 非 swipe-rfid/self_study）不算 RFID 刷卡：就算 id 較新、還是別校的，
        // 照片仍對到剛剛那筆本校 RFID 簽到。
        $this->swipeRfid()->assertJson(['action' => 'sign_in']);
        StudentSignIn::create([
            'StudentID' => $this->student->id, 'StudentClassID' => 0, 'Memo' => 'manual',
            'SignInDT' => now(), 'CampusID' => $this->campus->id + 100,
        ]);
        $this->upload()->assertOk()->assertJson(['sent' => 1]);

        $this->assertCount(1, Storage::disk('local')->files("swipe-photos/{$this->campus->id}"));
    }

    public function test_no_channel_token_still_stores_without_push(): void
    {
        DB::table('Campus')->where('id', $this->campus->id)->update(['messaging_channel_token' => null]);

        $old = "swipe-photos/{$this->campus->id}/old.jpg";
        Storage::disk('local')->put($old, 'x');
        touch(Storage::disk('local')->path($old), now()->subDays(8)->getTimestamp());

        $this->swipeRfid()->assertJson(['action' => 'sign_in']);
        $this->upload()->assertOk()->assertJson(['sent' => 0]);
        // 新照片存了、超過 7 天的舊照片被清掉。
        $this->assertCount(1, Storage::disk('local')->files("swipe-photos/{$this->campus->id}"));
        Storage::disk('local')->assertMissing($old);
        Http::assertNothingSent();
    }
}
