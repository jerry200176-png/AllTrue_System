<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLineBinding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        // Find active unpaid courses created before the cutoff date
        $unpaidCourses = StudentClass::with(['student'])
            ->where('Stop', 0)
            ->where('Paid', 0)
            ->whereDate('created_at', '<=', $cutoff)
            ->get();

        if ($unpaidCourses->isEmpty()) {
            $this->info('No overdue unpaid courses found.');
            return self::SUCCESS;
        }

        $this->info("Found {$unpaidCourses->count()} overdue unpaid course(s).");

        // Group by campus for director notifications
        $byCampus = [];

        foreach ($unpaidCourses as $course) {
            $student = $course->student;
            if (!$student) continue;

            $campusId = (int) ($student->CampusID ?? 0);
            if (!$campusId) continue;

            $byCampus[$campusId][] = $student->name ?? 'Unknown';

            // Find LINE binding for this student
            $binding = StudentLineBinding::where('student_id', $student->id)
                ->where('campus_id', $campusId)
                ->first();

            if (!$binding) continue;

            $campus = DB::table('Campus')->where('id', $campusId)->first();
            if (!$campus || empty($campus->messaging_channel_token)) continue;

            $subjectLabel = $this->subjectLabel($course);
            $msg = "親愛的家長您好，\n\n"
                . "提醒您：{$student->name} 同學的「{$subjectLabel}」課程尚未完成繳費。\n\n"
                . "請盡速聯繫補習班完成繳費，以確保課程正常進行。\n\n"
                . "如已繳費請忽略此訊息，謝謝！";

            if ($dryRun) {
                $this->line("[dry-run] Would send LINE to {$binding->line_user_id} ({$student->name}): {$msg}");
            } else {
                $this->pushLine($binding->line_user_id, $msg, $campus->messaging_channel_token, $campus->name ?? '');
            }
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

            // Find directors for this campus
            $directorIds = DB::table('UserCampus')
                ->where('CampusID', $campusId)
                ->where('Approved', 1)
                ->join('User', 'User.id', '=', 'UserCampus.UserID')
                ->where('User.type', 'D')
                ->pluck('UserCampus.UserID');

            foreach ($directorIds as $directorId) {
                Notification::create([
                    'UserID'    => $directorId,
                    'CampusID'  => $campusId,
                    'Type'      => 'tuition',
                    'Title'     => $title,
                    'Body'      => $body,
                    'CreatedAt' => now(),
                ]);
            }

            Log::info("tuition_reminders_sent", [
                'campus_id'     => $campusId,
                'student_count' => $count,
            ]);
        }

        $this->info($dryRun ? 'Dry-run complete.' : 'Reminders sent.');
        return self::SUCCESS;
    }

    private function pushLine(string $lineUserId, string $text, string $token, string $campusName): void
    {
        try {
            Http::withToken($token)->post('https://api.line.me/v2/bot/message/push', [
                'to'       => $lineUserId,
                'messages' => [['type' => 'text', 'text' => $text]],
            ]);
        } catch (\Exception $e) {
            Log::error("tuition_reminder_line_push [{$campusName}]: " . $e->getMessage());
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
