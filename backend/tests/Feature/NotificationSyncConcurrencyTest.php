<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\NotificationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationSyncConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string|null> */
    protected $connectionsToTransact = [];

    public function test_concurrent_learning_review_insert_reuses_committed_source_key(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression covers MySQL REPEATABLE READ visibility.');
        }

        $campus = Campus::factory()->create();
        $campusId = (int) $campus->id;
        $student = Student::create([
            'name' => '並行通知測試學生',
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $studentClass = $this->createStudentClass($student->id);
        $session = ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);
        $record = LearningRecord::create([
            'StudentClassID' => $studentClass->ID,
            'ClassSessionID' => $session->id,
            'TeacherID' => 99,
            'Content' => '待審內容',
            'Status' => 'pending',
            'Subject' => 'Math',
            'SessionDate' => now()->toDateString(),
        ]);
        $sourceKey = "learning:{$campusId}:{$record->id}";

        $connectionName = 'notification_race';
        config([
            "database.connections.{$connectionName}" => config('database.connections.' . config('database.default')),
        ]);
        DB::purge($connectionName);
        $concurrent = DB::connection($connectionName);
        $injected = false;

        Notification::creating(function (Notification $notification) use (
            $concurrent,
            &$injected,
            $sourceKey
        ): void {
            if ($injected || $notification->SourceKey !== $sourceKey) {
                return;
            }

            $attributes = $notification->getAttributes();
            $attributes['created_at'] = now();
            $attributes['updated_at'] = now();
            $concurrent->table('Notifications')->insert($attributes);
            $injected = true;
        });

        try {
            $result = NotificationSyncService::sync([$campusId], $campusId);

            $this->assertTrue($injected, 'The test must inject the competing committed row.');
            $this->assertSame(1, $result['active_count']);
            $this->assertSame(0, $result['created']);
            $this->assertSame(1, $result['source_key_races_recovered']);
            $this->assertSame(
                1,
                $concurrent->table('Notifications')->where('SourceKey', $sourceKey)->count()
            );
            $this->assertNull(
                $concurrent->table('Notifications')->where('SourceKey', $sourceKey)->value('ResolvedAt')
            );
        } finally {
            $concurrent->table('Notifications')->where('SourceKey', $sourceKey)->delete();
            DB::disconnect($connectionName);
            LearningRecord::whereKey($record->id)->delete();
            ClassSession::whereKey($session->id)->delete();
            StudentClass::whereKey($studentClass->ID)->delete();
            Student::whereKey($student->id)->delete();
            Campus::whereKey($campusId)->delete();
        }
    }

    private function createStudentClass(int $studentId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 5000,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 1,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => 5,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
    }
}
