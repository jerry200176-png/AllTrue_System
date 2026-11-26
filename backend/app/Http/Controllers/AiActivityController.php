<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * 帳務中心重整 G3 (Founder 2026-11-16): what AI read through the triage token and which governed data operations
 * were requested, over the last 30 days. Super admin, read-only; shows table and route names, never row values.
 */
class AiActivityController extends Controller
{
    private const DAYS = 30;

    public function index()
    {
        $since = now()->subDays(self::DAYS);
        // ponytail: one month of read events aggregated in PHP; move the grouping into SQL if this passes ~50k rows.
        // A read outside the token's scope is refused by RestrictAccessTokenScope and logged as scope_denied (Codex #5409).
        $reads = DB::table('security_audit_events')->whereIn('event_type', ['triage_token.read', 'triage_token.scope_denied'])
            ->where('occurred_at', '>=', $since)->get(['occurred_at', 'event_type', 'outcome', 'metadata']);
        $byReason = [];
        $bySource = [];
        $byDay = [];
        foreach ($reads as $row) {
            $meta = json_decode((string) $row->metadata, true) ?: [];
            if ($row->outcome !== 'success') {
                $reason = self::failureCategory($row->event_type, $meta);
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }
            $name = (string) ($meta['source'] ?? $meta['route'] ?? '') ?: '(unknown)';
            $bySource[$name] = ($bySource[$name] ?? 0) + 1;
            $day = substr((string) $row->occurred_at, 0, 10);
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }
        arsort($bySource);
        ksort($byDay);

        $operations = DB::table('pop_operation_requests')->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(50)
            ->get(['id', 'operation_id', 'strategy_id', 'status', 'actor', 'created_at']);
        // Only real approvals; policy_denied / policy_revoked rows are not counted (Codex #5409).
        $approvals = DB::table('pop_approval_events')->where('event_type', 'approved')->whereIn('operation_id', $operations->pluck('id'))
            ->selectRaw('operation_id, COUNT(*) AS n')->groupBy('operation_id')->pluck('n', 'operation_id');

        return response()->json(['data' => [
            'days' => self::DAYS,
            'reads' => [
                'total' => $reads->count(),
                'refused' => array_sum($byReason),
                'failure_counts' => (object) $byReason,
                'by_source' => array_map(fn ($name, $count) => ['name' => $name, 'count' => $count], array_keys($bySource), array_values($bySource)),
                'by_day' => array_map(fn ($date, $count) => ['date' => $date, 'count' => $count], array_keys($byDay), array_values($byDay)),
            ],
            'operations' => $operations->map(fn ($op) => [
                'operation' => $op->operation_id, 'strategy' => $op->strategy_id, 'status' => $op->status,
                'actor' => $op->actor, 'created_at' => (string) $op->created_at, 'approval_events' => (int) ($approvals[$op->id] ?? 0),
            ])->values(),
        ]]);
    }

    /** Only fixed categories leave this endpoint. Older audit rows may lack a status or reason. */
    private static function failureCategory(string $eventType, array $meta): string
    {
        $status = $meta['http_status'] ?? null;
        $reason = $meta['reason_code'] ?? null;
        return match (true) {
            $eventType === 'triage_token.scope_denied', $status === 403, $reason === 'owner_not_super_admin' => 'access_denied',
            $status === 401, in_array($reason, ['invalid_token', 'token_expired'], true) => 'credential',
            $status === 404 => 'not_found',
            in_array($status, [400, 422], true) => 'invalid_request',
            $status === 429 => 'rate_limited',
            is_int($status) && $status >= 500 && $status < 600 => 'service_error',
            default => 'unknown',
        };
    }
}
