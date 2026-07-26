<?php

namespace App\Services\ParentBinding;

use App\Models\ParentBindingAttempt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only PB-00 aggregates — no raw name/phone/LINE id/tokens. */
final class ParentBindingReportService
{
    public function attemptReport(int $days = 7, ?int $campusId = null): array
    {
        $tz = (string) config('parent_binding.timezone', 'Asia/Taipei');
        $days = max(1, min(90, $days));
        $enabled = (bool) config('parent_binding.observability_enabled', false);
        $tableReady = Schema::hasTable('parent_binding_attempts');
        $end = Carbon::now($tz);
        $start = $end->copy()->subDays($days);
        $base = [
            'timezone' => $tz,
            'days' => $days,
            'date_range' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'observability_enabled' => $enabled,
            'table_ready' => $tableReady,
            'campus_id' => $campusId,
        ];
        if (!$tableReady) {
            return $base + [
                'status' => 'table_missing', 'total_attempts' => 0, 'success_count' => 0,
                'failure_count' => 0, 'noop_count' => 0, 'success_rate' => null,
                'by_reason_code' => [], 'by_channel' => [], 'by_campus_id' => [],
            ];
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
            'total_attempts' => $rows->count(),
            'success_count' => $success,
            'failure_count' => $failure,
            'noop_count' => $noop,
            'success_rate' => $denom > 0 ? round($success / $denom, 4) : null,
            'by_reason_code' => $rows->groupBy(fn ($r) => $r->reason_code ?? '(none)')->map->count()->sortDesc()->all(),
            'by_channel' => $rows->groupBy('channel')->map->count()->sortDesc()->all(),
            'by_campus_id' => $rows->groupBy(fn ($r) => $r->campus_id === null ? '(null)' : (string) $r->campus_id)->map->count()->sortDesc()->all(),
        ];
    }

    public function missingContactReport(?int $campusId = null): array
    {
        $tz = (string) config('parent_binding.timezone', 'Asia/Taipei');
        $campuses = DB::table('Campus')->when($campusId !== null, fn ($q) => $q->where('id', $campusId))->orderBy('id')->get(['id', 'name']);
        $rows = [];
        foreach ($campuses as $campus) {
            $students = DB::table('Student')
                ->where('CampusID', $campus->id)->where('enable', 1)
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '')->orWhereIn('status', ['active', 'paused']))
                ->get(['parent_phone', 'Phone']);
            $active = $students->count();
            $missing = $students->filter(function ($s) {
                $parent = trim((string) ($s->parent_phone ?? ''));
                $legacy = trim((string) ($s->Phone ?? ''));

                return ($parent !== '' ? $parent : $legacy) === '';
            })->count();
            $rows[] = [
                'campus_id' => (int) $campus->id,
                'campus_name' => (string) $campus->name,
                'active_student_count' => $active,
                'missing_contact_count' => $missing,
                'missing_contact_percentage' => $active > 0 ? round($missing / $active, 4) : null,
            ];
        }

        return [
            'timezone' => $tz,
            'generated_at' => Carbon::now($tz)->toIso8601String(),
            'authoritative_rule' => 'parent_phone → Phone (StudentContactPhone)',
            'campus_id' => $campusId,
            'campuses' => $rows,
        ];
    }
}
