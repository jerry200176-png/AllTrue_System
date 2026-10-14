<?php

namespace App\Operations\Inapp;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared, IDs-only helpers for the ADR-POP-015 in-app strategies. */
final class InappReportSupport
{
    public const REQUEST_ID = '/^[a-z0-9][a-z0-9-]{2,63}$/';

    /** The POP request being executed; plans and records carry IDs only. */
    public static function request(array $context): object
    {
        $row = DB::table('pop_operation_requests')->where('id', (string) ($context['operation_id'] ?? ''))->first();
        if (!$row) throw new RuntimeException('inapp_request_missing');

        return $row;
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

    /** Write-back author: the issuing super admin (policy approval) or the approving super admin (human). */
    public static function attributionUserId(object $request): int
    {
        $human = DB::table('pop_approval_events')->where('operation_id', $request->id)->where('event_type', 'approved')
            ->where('approver_role', 'super_admin')->value('approver_id');
        $id = $human !== null ? (int) $human : app(InappRequesterGuard::class)->attributionUserId($request);
        if (!$id || !User::query()->whereKey($id)->where('type', 'S')->exists()) throw new RuntimeException('inapp_attribution_unresolved');

        return $id;
    }
}
