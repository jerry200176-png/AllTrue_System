<?php

namespace App\Console\Commands;

use App\Models\ParentBindingAttempt;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** php artisan parent-binding:report --days=7|--missing-contact [--campus=] --format=json */
class ParentBindingReportCommand extends Command
{
    protected $signature = 'parent-binding:report {--days=7} {--campus=} {--format=json} {--missing-contact}';
    protected $description = 'Read-only parent binding attempt / missing-contact report (PB-00).';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['json', 'table'], true)) {
            $this->error('Invalid --format');

            return self::FAILURE;
        }
        $campusId = null;
        if (($opt = $this->option('campus')) !== null && $opt !== '') {
            if (!is_numeric($opt) || (int) $opt < 1) {
                $this->error('Invalid --campus');

                return self::FAILURE;
            }
            $campusId = (int) $opt;
        }
        $report = $this->option('missing-contact')
            ? $this->missingContact($campusId)
            : $this->attempts($this->option('days'), $campusId);
        if ($report === null) {
            return self::FAILURE;
        }
        $this->line(json_encode($report, ($format === 'json' ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function attempts($daysRaw, ?int $campusId): ?array
    {
        if (!is_numeric($daysRaw) || (int) $daysRaw < 1 || (int) $daysRaw > 90) {
            $this->error('Invalid --days (1-90)');

            return null;
        }
        $tz = (string) config('parent_binding.timezone', 'Asia/Taipei');
        $days = (int) $daysRaw;
        $enabled = (bool) config('parent_binding.observability_enabled', false);
        $ready = Schema::hasTable('parent_binding_attempts');
        $end = Carbon::now($tz);
        $start = $end->copy()->subDays($days);
        $base = [
            'timezone' => $tz, 'days' => $days,
            'date_range' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'observability_enabled' => $enabled, 'table_ready' => $ready, 'campus_id' => $campusId,
        ];
        if (!$ready) {
            return $base + ['status' => 'table_missing', 'total_attempts' => 0, 'success_count' => 0, 'failure_count' => 0, 'noop_count' => 0, 'success_rate' => null, 'by_reason_code' => [], 'by_channel' => [], 'by_campus_id' => []];
        }
        $q = ParentBindingAttempt::query()->whereBetween('occurred_at', [$start, $end]);
        if ($campusId !== null) {
            $q->where('campus_id', $campusId);
        }
        $rows = $q->get(['outcome', 'reason_code', 'channel', 'campus_id']);
        $success = $rows->where('outcome', 'success')->count();
        $failure = $rows->where('outcome', 'failure')->count();
        $noop = $rows->where('outcome', 'noop')->count();
        $denom = $success + $failure;

        return $base + [
            'status' => $enabled ? 'ok' : 'observability_disabled',
            'total_attempts' => $rows->count(), 'success_count' => $success, 'failure_count' => $failure, 'noop_count' => $noop,
            'success_rate' => $denom > 0 ? round($success / $denom, 4) : null,
            'by_reason_code' => $rows->groupBy(fn ($r) => $r->reason_code ?? '(none)')->map(fn ($g) => $g->count())->sortDesc()->all(),
            'by_channel' => $rows->groupBy('channel')->map(fn ($g) => $g->count())->sortDesc()->all(),
            'by_campus_id' => $rows->groupBy(fn ($r) => $r->campus_id === null ? '(null)' : (string) $r->campus_id)->map(fn ($g) => $g->count())->sortDesc()->all(),
        ];
    }

    private function missingContact(?int $campusId): array
    {
        $tz = (string) config('parent_binding.timezone', 'Asia/Taipei');
        $campuses = DB::table('Campus')->when($campusId !== null, fn ($q) => $q->where('id', $campusId))->orderBy('id')->get(['id', 'name']);
        $rows = [];
        foreach ($campuses as $campus) {
            $students = DB::table('Student')->where('CampusID', $campus->id)->where('enable', 1)
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '')->orWhereIn('status', ['active', 'paused']))
                ->get(['parent_phone', 'Phone']);
            $active = $students->count();
            $missing = $students->filter(function ($s) {
                $p = trim((string) ($s->parent_phone ?? ''));

                return ($p !== '' ? $p : trim((string) ($s->Phone ?? ''))) === '';
            })->count();
            $rows[] = [
                'campus_id' => (int) $campus->id, 'campus_name' => (string) $campus->name,
                'active_student_count' => $active, 'missing_contact_count' => $missing,
                'missing_contact_percentage' => $active > 0 ? round($missing / $active, 4) : null,
            ];
        }

        return [
            'timezone' => $tz, 'generated_at' => Carbon::now($tz)->toIso8601String(),
            'authoritative_rule' => 'parent_phone → Phone (StudentContactPhone)',
            'campus_id' => $campusId, 'campuses' => $rows,
        ];
    }
}
