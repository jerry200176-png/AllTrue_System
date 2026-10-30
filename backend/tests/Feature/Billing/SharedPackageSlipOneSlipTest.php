<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** in-app #442: a count-mode shared package is one obligation, so the slip from any member is the package's one slip. */
class SharedPackageSlipOneSlipTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $mode = 'count'): array
    {
        $campus = CampusFactory::new()->create();
        $student = Student::create(['name' => '學生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => $campus->id, 'name' => '英數方案',
            'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0,
            'rate' => 1650, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $members = [];
        foreach ([1, 2] as $id) {
            $members[] = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => $id, 'TeacherID' => 1,
                'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(3)->toDateString(),
                'TotalHours' => 0, 'SessionCount' => 10, 'RemainingSessions' => 10, 'UsedSessions' => 0,
                'Rate' => 1650, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
                'PackageID' => $pkg->id, 'ScheduleMode' => $mode, 'MDate' => now(),
            ]);
        }
        $u = User::create(['LoginName' => 'dir_' . Str::random(8), 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678, 'password_changed_at' => now()]);
        DB::table('UserCampus')->insert(['UserID' => $u->id, 'CampusID' => $campus->id, 'Admin' => 1, 'Approved' => 1]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $tok, 'expires_at' => now()->addDays(7)]);

        return [$members, $tok];
    }

    public function test_slip_from_either_member_is_the_one_package_slip(): void
    {
        [$members, $tok] = $this->package();
        foreach ($members as $m) {
            $res = $this->withHeaders(['Authorization' => "Bearer {$tok}", 'Accept' => 'application/json'])
                ->getJson("/api/v1/alerts/tuition-slip/{$m->ID}")->assertOk();
            $this->assertSame(16500, $res->json('charge'));
            $this->assertSame('Chinese、English', $res->json('subject'));
        }
    }

    public function test_monthly_package_member_slip_keeps_its_own_subject(): void
    {
        [$members, $tok] = $this->package('date');
        $res = $this->withHeaders(['Authorization' => "Bearer {$tok}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/alerts/tuition-slip/{$members[0]->ID}");
        $this->assertNotSame('Chinese、English', $res->json('subject'));
    }
}
