<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only projection for shared-package planning.
 *
 * A future scheduled row is a plan only. It does not create a package ledger
 * entry and therefore must not reserve or consume the shared entitlement.
 */
class SharedPackagePlanningService
{
    /**
     * @return array<string, int|bool|string|null>
     */
    public function summarize(CoursePackage $package, int $additionalPlanned = 0): array
    {
        return $this->summarizeMany(collect([$package]), [$package->getKey() => max(0, $additionalPlanned)])[(int) $package->getKey()];
    }

    /**
     * @param  Collection<int, CoursePackage>  $packages
     * @param  array<int|string, int>  $additionalByPackageId
     * @return array<int, array<string, int|bool|string|null>>
     */
    public function summarizeMany(Collection $packages, array $additionalByPackageId = []): array
    {
        if ($packages->isEmpty()) {
            return [];
        }

        $packageIds = $packages->pluck('id')->map(static fn ($id): int => (int) $id)->filter()->values()->all();
        $memberIdsByPackage = StudentClass::query()
            ->whereIn('PackageID', $packageIds)
            ->get(['ID', 'PackageID'])
            ->groupBy('PackageID')
            ->map(static fn (Collection $members): array => $members->pluck('ID')->map(static fn ($id): int => (int) $id)->all());

        $allMemberIds = $memberIdsByPackage->flatten()->map(static fn ($id): int => (int) $id)->filter()->values()->all();
        $plannedByMember = [];
        if ($allMemberIds !== []) {
            $plannedByMember = ClassSession::query()
                ->whereIn('StudentClassID', $allMemberIds)
                ->whereDate('SessionDate', '>=', Carbon::today()->toDateString())
                ->whereIn('Status', ['scheduled', 'rescheduled'])
                ->selectRaw('StudentClassID, COUNT(*) as planned_count')
                ->groupBy('StudentClassID')
                ->pluck('planned_count', 'StudentClassID')
                ->map(static fn ($count): int => (int) $count)
                ->all();
        }

        $ledgerDeltaByPackage = $packageIds === []
            ? []
            : PackageSessionLedger::query()
                ->whereIn('package_id', $packageIds)
                ->selectRaw('package_id, COALESCE(SUM(delta), 0) as net_delta')
                ->groupBy('package_id')
                ->pluck('net_delta', 'package_id')
                ->map(static fn ($delta): int => (int) $delta)
                ->all();

        $result = [];
        foreach ($packages as $package) {
            $packageId = (int) $package->getKey();
            $purchased = max(0, (int) ($package->total_sessions ?? 0));
            $remaining = max(0, $purchased + (int) ($ledgerDeltaByPackage[$packageId] ?? 0));
            $memberIds = $memberIdsByPackage->get($packageId, []);
            $futurePlanned = 0;
            foreach ($memberIds as $memberId) {
                $futurePlanned += (int) ($plannedByMember[$memberId] ?? 0);
            }
            $additional = max(0, (int) ($additionalByPackageId[$packageId] ?? 0));
            $projected = $futurePlanned + $additional;
            $overage = $package->getAttribute('billing_mode') === CoursePackage::BILLING_MODE_SESSION
                ? max(0, $projected - $remaining)
                : 0;

            $result[$packageId] = [
                'purchased_entitlement' => $purchased,
                'actual_consumed' => max(0, $purchased - $remaining),
                'remaining_sessions' => $remaining,
                'future_planned_sessions' => $futurePlanned,
                'projected_future_planned_sessions' => $projected,
                'overage_sessions' => $overage,
                'renewal_warning' => $overage > 0,
                'renewal_message' => $overage > 0
                    ? "未來預排 {$projected} 堂，超過目前剩餘 {$remaining} 堂 {$overage} 堂；仍可排課，請安排續約或加購。"
                    : null,
            ];
        }

        return $result;
    }
}
