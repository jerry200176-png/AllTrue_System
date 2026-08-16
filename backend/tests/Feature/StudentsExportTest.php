<?php

namespace Tests\Feature;

use App\Exports\StudentsExport;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #987: StudentsExport must (a) scope rows to the caller's campuses (PII) and
 * (b) stream in bounded chunks instead of Student::all().
 * #1812: HTTP export writes a PII-minimized security_audit_events row.
 */
class StudentsExportTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $name, int $campusId): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    /** @return array{0:string,1:int} */
    private function directorToken(int $campusId): array
    {
        Campus::firstOrCreate(
            ['id' => $campusId],
            [
                'name' => "測試分校{$campusId}",
                'LineNotifyID' => '',
                'Client_ID' => '',
                'Client_Secret' => '',
                'LIFFID' => '',
                'LIFF_URL' => '',
                'URL' => '',
                'TelegramURL' => '',
                'TeachLIFFID' => '',
                'TeachLIFF_URL' => '',
            ]
        );

        $user = User::create([
            'LoginName' => 'director-export-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $raw,
            'expires_at' => now()->addDay(),
        ]);

        return [$raw, (int) $user->id];
    }

    public function test_export_is_scoped_to_given_campuses(): void
    {
        $this->student('校一甲', 1);
        $this->student('校一乙', 1);
        $this->student('校二甲', 2);

        $rows = (new StudentsExport([1]))->query()->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [1, 1],
            $rows->pluck('CampusID')->map(fn ($c) => (int) $c)->all()
        );
    }

    public function test_empty_campus_list_means_no_restriction(): void
    {
        $this->student('校一甲', 1);
        $this->student('校二甲', 2);
        $this->student('校三甲', 3);

        $rows = (new StudentsExport([]))->query()->get();

        $this->assertCount(3, $rows);
    }

    public function test_multi_campus_scope(): void
    {
        $this->student('校一甲', 1);
        $this->student('校二甲', 2);
        $this->student('校三甲', 3);

        $rows = (new StudentsExport([1, 3]))->query()->get();

        $this->assertEqualsCanonicalizing([1, 3], $rows->pluck('CampusID')->map(fn ($c) => (int) $c)->all());
    }

    public function test_uses_bounded_chunk_reading(): void
    {
        $this->assertSame(500, (new StudentsExport())->chunkSize());
    }

    public function test_http_export_writes_security_audit_event(): void
    {
        $this->student('校一甲', 1);
        $this->student('校一乙', 1);
        $this->student('校二甲', 2);

        [$token, $userId] = $this->directorToken(campusId: 1);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => '*/*',
        ])->get('/api/v1/students/export');

        $res->assertOk();

        $row = DB::table('security_audit_events')
            ->where('event_type', 'pii.export.students')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(SecurityAuditEvent::ref('user', $userId), $row->actor_ref);
        $this->assertSame(1, (int) $row->campus_id);

        $meta = json_decode($row->metadata, true);
        $this->assertSame('xlsx', $meta['export_format'] ?? null);
        $this->assertSame(2, (int) ($meta['row_count'] ?? -1));
        $this->assertSame('restricted', $meta['campus_scope'] ?? null);
        $this->assertSame(1, (int) ($meta['campus_count'] ?? -1));
        $this->assertArrayNotHasKey('phone', $meta);
        $this->assertStringNotContainsString('校一', (string) $row->metadata);
    }
}
