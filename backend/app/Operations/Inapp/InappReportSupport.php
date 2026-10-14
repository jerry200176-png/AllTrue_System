<?php

namespace App\Operations\Inapp;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared, IDs-only helpers for the ADR-POP-015 in-app strategies. */
final class InappReportSupport
{
    public const REQUEST_ID = '/^[a-z0-9][a-z0-9-]{2,63}$/';

    /** Parameters of the POP request being executed; plans and records carry IDs only. */
    public static function requestParameters(array $context): array
    {
        $row = DB::table('pop_operation_requests')->where('id', (string) ($context['operation_id'] ?? ''))->first();
        if (!$row) throw new RuntimeException('inapp_request_missing');

        return json_decode((string) $row->parameters, true, 512, JSON_THROW_ON_ERROR);
    }

    /** True when another request of a governed operation already used this request_id. */
    public static function requestIdReplayed(string $requestId, string $popRequestId): bool
    {
        return DB::table('pop_operation_requests')->whereIn('operation_id', array_keys(InappStandingPolicy::OPERATIONS))
            ->where('id', '!=', $popRequestId)->where('parameters->request_id', $requestId)->exists();
    }

    /** @param array<int,int> $reportCampusIds */
    public static function campusMismatch(array $p, array $reportCampusIds): bool
    {
        $campusId = $p['campus_id'] ?? null;

        return !is_int($campusId) || $campusId <= 0 || $reportCampusIds === []
            || array_filter($reportCampusIds, fn (int $c): bool => $c !== $campusId) !== [];
    }

    /** Staff author of the status log and fixed reply: the configured or lowest-id type S user. */
    public static function actorUserId(): int
    {
        $configured = (int) config('pop_inapp.actor_user_id');
        $query = User::query()->where('type', 'S');
        $id = $configured > 0 ? $query->whereKey($configured)->value('id') : $query->orderBy('id')->value('id');
        if (!$id) throw new RuntimeException('inapp_actor_unavailable');

        return (int) $id;
    }
}
