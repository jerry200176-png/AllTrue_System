<?php

namespace Tests\Unit;

use App\Models\Campus;
use App\Models\Student;
use PHPUnit\Framework\TestCase;

// Credentials must not reach JSON through nested serialization (e.g. a course with room.campus and student).
class ModelCredentialHiddenTest extends TestCase
{
    public function test_campus_credentials_are_not_serialized_but_stay_readable(): void
    {
        $secrets = ['Client_Secret', 'Token', 'LineNotifyID', 'TelegramToken', 'TelegramChatID', 'TelegramWebhookSecret', 'messaging_channel_token', 'messaging_channel_secret'];
        $campus = (new Campus())->forceFill(array_fill_keys($secrets, 'sentinel-secret') + ['id' => 7, 'name' => 'X', 'Client_ID' => 'cid']);

        $json = $campus->toJson();
        $this->assertStringNotContainsString('sentinel-secret', $json);
        $this->assertSame(['id' => 7, 'name' => 'X', 'Client_ID' => 'cid'], $campus->toArray());
        foreach ($secrets as $k) {
            $this->assertSame('sentinel-secret', $campus->{$k}, $k);
        }
    }

    public function test_student_notify_token_is_not_serialized(): void
    {
        $student = (new Student())->forceFill(['id' => 9, 'name' => 'S', 'Notify_Token' => 'sentinel-secret']);

        $this->assertStringNotContainsString('sentinel-secret', $student->toJson());
        $this->assertSame('sentinel-secret', $student->Notify_Token);
    }
}
