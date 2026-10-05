<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Services\Line\SwipePhotoDelivery;
use App\Support\LineNotifySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SwipePhotoDelivery called directly (moved out of SwipeRfidController): switch, token and verified-parent gates. */
class SwipePhotoDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.line.me/*' => Http::response([], 200)]);
        $this->campus = Campus::create([
            'name' => 'DeliveryCampus', 'Token' => 'delivery-token', 'code' => 'delivery', 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '',
            'URL' => '', 'TelegramToken' => '', 'TelegramChatID' => '', 'TelegramURL' => '',
            'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);
        DB::table('Campus')->where('id', $this->campus->id)->update(['messaging_channel_token' => 'line-token']);
        $this->student = Student::create([
            'name' => 'DeliveryKid', 'CampusID' => $this->campus->id, 'ClassID' => 1,
            'RFID' => 'DELIVERY-1', 'enable' => 1,
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

    private function push(): int
    {
        return app(SwipePhotoDelivery::class)
            ->pushToParents($this->student, $this->campus->fresh(), 'https://alltrue.example/p.jpg', null);
    }

    public function test_pushes_only_to_the_verified_parent(): void
    {
        $this->assertSame(1, $this->push());
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => $req['to'] === 'Uverified');
    }

    public function test_switch_off_sends_nothing(): void
    {
        LineNotifySettings::set($this->campus->id, ['swipe' => false]);
        $this->assertSame(0, $this->push());
        Http::assertNothingSent();
    }

    public function test_missing_channel_token_sends_nothing(): void
    {
        DB::table('Campus')->where('id', $this->campus->id)->update(['messaging_channel_token' => '']);
        $this->assertSame(0, $this->push());
        Http::assertNothingSent();
    }
}
