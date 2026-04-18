<?php

namespace App\Services;

use App\Models\ScheduleDiscrepancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes schedule-discrepancy notifications to the campus LINE group.
 *
 * LINE Notify terminated on 2025-03-31, so we use the LINE Messaging API
 * push endpoint with the campus's existing `messaging_channel_token`.
 * Target is `staff_line_group_id` (one group per campus — all directors
 * in the group receive the message, no per-user routing needed).
 *
 * Failures never bubble out: reporting must succeed even if LINE push breaks.
 * The in-app dashboard remains the primary delivery channel.
 */
class ScheduleDiscrepancyNotifier
{
    public const MAX_ATTEMPTS = 3;
    public const BACKOFF_SECONDS = [1, 2];

    public static function notify(ScheduleDiscrepancy $discrepancy): void
    {
        $campus = DB::table('Campus')->where('id', $discrepancy->branch_id)->first();
        if (!$campus) {
            return;
        }

        $token = (string) ($campus->messaging_channel_token ?? '');
        $groupId = (string) ($campus->staff_line_group_id ?? '');

        if ($token === '' || $groupId === '') {
            Log::info('schedule_discrepancy.line_skip', [
                'discrepancy_id' => $discrepancy->id,
                'campus_id' => $discrepancy->branch_id,
                'reason' => $token === '' ? 'missing_token' : 'missing_group_id',
            ]);
            return;
        }

        $reporterName = self::resolveReporterName($discrepancy->reporter_id);
        $text = self::buildMessage($campus->name ?? '', $reporterName, $discrepancy);

        self::sendWithRetry($token, $groupId, $text, $discrepancy->id);
    }

    private static function resolveReporterName(?int $reporterId): string
    {
        if (!$reporterId) return '未知老師';
        $row = DB::table('User')->select('Name')->where('id', $reporterId)->first();
        return $row->Name ?? '未知老師';
    }

    private static function buildMessage(string $campusName, string $reporterName, ScheduleDiscrepancy $d): string
    {
        $typeLabel = ScheduleDiscrepancy::TYPE_LABELS[$d->discrepancy_type] ?? $d->discrepancy_type;
        $date = $d->session_date ? $d->session_date->format('Y-m-d') : '';
        $time = $d->time_range ?: '';
        $subject = $d->subject ?: '';
        $student = $d->student_name ?: '';

        $header = sprintf('【%s】%s 回報課表出入', $campusName ?: '分校', $reporterName);
        $corrected = self::safeCorrectedTimeRange($d);

        $lines = [$header, '出入類型：' . $typeLabel];
        if ($date) $lines[] = '日期：' . $date;
        if ($time) $lines[] = '時段：' . $time;
        if ($corrected !== '') $lines[] = '正確時間應為：' . $corrected;
        if ($subject) $lines[] = '科目：' . $subject;
        if ($student) $lines[] = '學生：' . $student;
        if ($d->notes) $lines[] = '備註：' . mb_substr($d->notes, 0, 80);
        $lines[] = '→ 請至系統處理';

        return implode("\n", $lines);
    }

    /**
     * Pull `corrected_time_range` safely even if the column is missing
     * (e.g. before the migration has run). Returns '' when not set.
     */
    private static function safeCorrectedTimeRange(ScheduleDiscrepancy $d): string
    {
        try {
            $val = $d->getAttribute('corrected_time_range');
            return is_string($val) ? trim($val) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function sendWithRetry(string $token, string $groupId, string $text, int $discrepancyId): void
    {
        $attempt = 0;
        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            try {
                $response = Http::withToken($token)
                    ->timeout(8)
                    ->post('https://api.line.me/v2/bot/message/push', [
                        'to' => $groupId,
                        'messages' => [['type' => 'text', 'text' => $text]],
                    ]);

                if ($response->successful()) {
                    return;
                }

                // 4xx (except 429) are not worth retrying — token/group-id issues.
                $status = $response->status();
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    Log::warning('schedule_discrepancy.line_4xx', [
                        'discrepancy_id' => $discrepancyId,
                        'attempt' => $attempt,
                        'status' => $status,
                        'body' => mb_substr((string) $response->body(), 0, 500),
                    ]);
                    return;
                }

                // 5xx / 429 — retry with backoff.
                if ($attempt < self::MAX_ATTEMPTS) {
                    $sleep = self::BACKOFF_SECONDS[$attempt - 1] ?? 2;
                    sleep($sleep);
                }
            } catch (\Throwable $e) {
                Log::warning('schedule_discrepancy.line_exception', [
                    'discrepancy_id' => $discrepancyId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_ATTEMPTS) {
                    $sleep = self::BACKOFF_SECONDS[$attempt - 1] ?? 2;
                    sleep($sleep);
                }
            }
        }

        Log::error('schedule_discrepancy.line_failed', [
            'discrepancy_id' => $discrepancyId,
            'attempts' => $attempt,
        ]);
    }
}
