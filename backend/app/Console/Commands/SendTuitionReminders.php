<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\NotificationRead;
use Illuminate\Database\QueryException;
use App\Services\Line\LinePush;
use App\Services\Line\ParentLinePush;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\StaffCapabilityAuthorizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SendTuitionReminders extends Command
{
    protected $signature = 'tuition:send-reminders
                            {--dry-run : Preview without sending}
                            {--overdue-days=7 : Minimum days since course created to consider overdue}';

    protected $description = 'Send LINE push reminders to parents of unpaid courses, and notify directors.';

    public function handle(): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $overdueDays = max(1, (int) $this->option('overdue-days'));
        $cutoff = now()->subDays($overdueDays)->toDateString();

            // Prefer created_at when present; legacy rows / test schemas use MDate.
            $overdueColumn = Schema::hasColumn('StudentClass', 'created_at') ? 'created_at' : 'MDate';
            /** @var \Illuminate\Database\Eloquent\Collection<int, StudentClass> $unpaidCourses */
            $unpaidCourses = StudentClass::query()
                ->with(['student', 'coursePackage'])
                ->where('Stop', 0)
                ->where(function ($q) {
                    $q->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> ?", ['tutoring']);
                })
                ->where(fn ($q) => $q->effectivelyUnpaid())
                ->whereDate($overdueColumn, '<=', $cutoff)
                ->get();

        // F7 S5: the resolver can only remove courses (paid/free); review_required/unknown keep the legacy decision.
        $onResolver = (bool) config('billing.paid_status_outbound_notifications', true);
        $settled = app(\App\Services\BillingPayableResolver::class)->settledCourseIds($unpaidCourses, 'tuition_reminders');
        $unpaidCourses = $unpaidCourses->reject(fn (StudentClass $c) => isset($settled[(int) $c->ID]))->values();

        if ($unpaidCourses->isEmpty()) {
            $this->info('No overdue unpaid courses found.');
            return self::SUCCESS;
        }

        $this->info("Found {$unpaidCourses->count()} overdue unpaid course(s).");

        // Group by campus for director notifications
        $byCampus = [];
        $coursesByCampus = [];

        foreach ($unpaidCourses as $course) {
            if ($course->isEffectivelyPaid()) {
                continue;
            }

            $student = $course->student;
            if (!$student) continue;

            $campusId = (int) ($student->CampusID ?? 0);
            if (!$campusId) continue;

            $studentName = (string) ($student->getAttribute('name') ?? 'Unknown');
            $byCampus[$campusId][] = $studentName;
            $coursesByCampus[$campusId][(int) $course->ID] = (int) ($course->Charge ?? 0);

            // Fan-out to every verified LINE binding (dad + mom), not first() only.
            $parents = app(ParentLinePush::class);
            $bindings = $parents->bindings((int) $student->getKey(), $campusId);

            if ($bindings->isEmpty()) {
                continue;
            }

            $campus = DB::table('Campus')->where('id', $campusId)->first();
            if (!$campus || empty($campus->messaging_channel_token)) {
                continue;
            }

            $subjectLabel = $this->subjectLabel($course);
            $msg = "親愛的家長您好，\n\n"
                . "提醒您：{$studentName} 同學的「{$subjectLabel}」課程尚未完成繳費。\n\n"
                . "請盡速聯繫補習班完成繳費，以確保課程正常進行。\n\n"
                . "如已繳費請忽略此訊息，謝謝！";

            if ($dryRun) {
                foreach ($bindings as $binding) {
                    $this->line("[dry-run] Would send LINE to {$binding->line_user_id} ({$studentName}): {$msg}");
                }
                continue;
            }

            $parents->deliver(
                $bindings,
                (int) $student->getKey(),
                $campusId,
                'tuition_reminder',
                fn ($binding) => $this->pushLine($binding->line_user_id, $msg, $campus->messaging_channel_token, $campus->name ?? '')
            );
        }

        // Create system notifications for directors (one per campus)
        foreach ($byCampus as $campusId => $studentNames) {
            $count  = count(array_unique($studentNames));
            $sample = array_slice(array_unique($studentNames), 0, 3);
            $extra  = count(array_unique($studentNames)) > 3 ? ' 等' : '';

            $title = "催繳提醒：{$count} 位學生未繳費";
            $body  = implode('、', $sample) . $extra . " 的課程逾期未繳費，請盡速跟進。";

            if ($dryRun) {
                $this->line("[dry-run] Would create director notification for campus {$campusId}: {$title}");
                continue;
            }

            // Campus-scoped, one row per campus per day (#3376). NotificationSyncService keeps it live
            // (SourceType TuitionReminder) and skips the per-course rows this aggregate covers.
            if ($this->directorRecipientIds((int) $campusId) !== []) {
                $this->upsertDirectorNotice((int) $campusId, $title, $body, $coursesByCampus[$campusId] ?? []);
            }

            Log::info("tuition_reminders_sent", [
                'campus_id'     => $campusId,
                'student_count' => $count,
            ]);
        }

        if (!$onResolver) {
            app(\App\Services\Billing\PaidStatusShadow::class)->compare($unpaidCourses, 'tuition_reminders');
        }

        $this->info($dryRun ? 'Dry-run complete.' : 'Reminders sent.');
        return self::SUCCESS;
    }

    /** @param array<int,int> $courses class id => charge */
    private function upsertDirectorNotice(int $campusId, string $title, string $body, array $courses): void
    {
        ksort($courses);
        $hash = md5(json_encode([$title, $body, $courses]));
        $sourceKey = "tuition-reminder:{$campusId}:" . now()->toDateString();
        $attrs = [
            'CampusID' => $campusId, 'Type' => 'tuition', 'Severity' => 'medium', 'Title' => $title, 'Body' => $body,
            'SourceType' => 'TuitionReminder', 'SourceID' => (string) $campusId, 'OccurredAt' => now(), 'ResolvedAt' => null,
            'Payload' => ['content_hash' => $hash, 'class_ids' => array_keys($courses)],
        ];

        for ($attempt = 0; ; $attempt++) {
            try {
                DB::transaction(function () use ($sourceKey, $attrs, $hash, $courses, $campusId) {
                    $row = Notification::query()->where('SourceKey', $sourceKey)->lockForUpdate()->first();
                    if (!$row) {
                        $row = Notification::query()->create($attrs + ['SourceKey' => $sourceKey]);
                    } elseif (($row->Payload['content_hash'] ?? null) !== $hash || $row->ResolvedAt !== null) {
                        // Changed same-day content: reopen for everyone who already read/archived it.
                        $row->fill($attrs)->save();
                        NotificationRead::query()->where('NotificationID', $row->id)->delete();
                    }
                    // Same overdue items must not also alert through the per-course stream.
                    $keys = array_map(fn ($id) => "tuition:{$campusId}:{$id}", array_keys($courses));
                    Notification::query()->whereIn('SourceKey', $keys)->whereNull('ResolvedAt')->update(['ResolvedAt' => now()]);
                });

                return;
            } catch (QueryException $e) {
                // Concurrent first insert lost the unique SourceKey race: retry once as an update.
                if ($attempt >= 1 || (int) ($e->errorInfo[1] ?? 0) !== 1062 && !str_contains($e->getMessage(), 'UNIQUE')) {
                    throw $e;
                }
            }
        }
    }

    /** @return list<int> */
    protected function directorRecipientIds(int $campusId): array
    {
        return app(StaffCapabilityAuthorizer::class)->directorUserIds($campusId);
    }

    private function pushLine(string $lineUserId, string $text, string $token, string $campusName): bool
    {
        try {
            return app(LinePush::class)->send($token, $lineUserId, [['type' => 'text', 'text' => $text]])->successful();
        } catch (\Exception $e) {
            Log::error("tuition_reminder_line_push [{$campusName}]: " . $e->getMessage());
            return false;
        }
    }

    private function subjectLabel(StudentClass $course): string
    {
        static $map = [
            'English' => '英文', 'Chinese' => '國文', 'Math' => '數學',
            'Physics' => '物理', 'Chemistry' => '化學', 'Science' => '自然', 'Social' => '社會',
        ];
        $raw = $course->Subject ?? '';
        if ($raw && isset($map[$raw])) return $map[$raw];
        if ($raw) return $raw;
        // Try subject table
        $subjectId = $course->SubjectID ?? 0;
        if ($subjectId) {
            $name = DB::table('Subject')->where('id', $subjectId)->value('Subject_Name');
            if ($name) return $name;
        }
        return '課程';
    }
}
