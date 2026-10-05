<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Characterization: POST /api/v1/swipe-rfid response envelope for a student (status codes + key sets). */
class StudentSwipeResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_duplicate_and_sign_out_envelopes(): void
    {
        $campus = Campus::create([
            'name' => 'ShapeCampus', 'Token' => 'shape-token', 'code' => 'shape', 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '',
            'URL' => '', 'TelegramToken' => 'tg-x', 'TelegramChatID' => '', 'TelegramURL' => '',
            'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);
        $student = Student::create(['name' => 'ShapeKid', 'CampusID' => $campus->id, 'ClassID' => 1, 'RFID' => 'SH-1', 'enable' => 1]);
        $swipe = fn () => $this->postJson('/api/v1/swipe-rfid', ['branch_code' => (string) $campus->id, 'rfid' => 'SH-1'], ['Authorization' => 'Bearer shape-token']);

        $this->travelTo(today()->setTime(10, 0));
        $in = $swipe()->assertStatus(201);
        $this->assertSame(['ok', 'type', 'action', 'record', 'student', 'class', 'campus'], array_keys($in->json()));
        $in->assertJson(['ok' => true, 'type' => 'student', 'action' => 'sign_in', 'class' => null, 'campus' => ['TelegramToken' => 'tg-x']]);
        $in->assertJsonPath('record.Memo', 'self_study')->assertJsonPath('student.id', $student->id);

        $this->travel(30)->seconds();
        $dup = $swipe()->assertStatus(200);
        $this->assertSame(['ok', 'type', 'action', 'record', 'student', 'campus'], array_keys($dup->json()));
        $dup->assertJson(['action' => 'duplicate_ignored']);

        $this->travel(2)->minutes();
        $out = $swipe()->assertStatus(200);
        $this->assertSame(['ok', 'type', 'action', 'record', 'student', 'campus'], array_keys($out->json()));
        $out->assertJson(['action' => 'sign_out']);
        $this->assertNotNull($out->json('record.SignOutDT'));
        $this->assertSame(1, \App\Models\StudentSignIn::count());
    }
}
