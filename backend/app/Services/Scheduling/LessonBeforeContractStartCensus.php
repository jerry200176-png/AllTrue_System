<?php

namespace App\Services\Scheduling;

use App\Support\ContractPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Observe-only census (in-app #536, #5198 option A): lessons dated before their contract's StartDate, per campus.
 * Not a defect count: the reassign allows it and the screens label it. SELECT only, never repairs, ids and counts only.
 * Cancelled / voided lessons are not counted (nothing was taught). Ids per campus are capped; counts never are.
 */
final class LessonBeforeContractStartCensus
{
    public const ID_CAP_PER_CAMPUS = 50;

    /** @return array{count:int, by_campus:array<int,array{count:int, ids:list<int>, ids_truncated:bool}>} */
    public function run(): array
    {
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereRaw(ContractPeriod::SQL_BEFORE_START)
            ->whereRaw("LOWER(COALESCE(cs.Status, '')) NOT IN ('cancelled', 'voided')")
            ->orderBy('cs.id')
            ->get(['cs.id', 's.CampusID']);

        $out = ['count' => 0, 'by_campus' => []];
        foreach ($rows as $row) {
            $campus = (int) $row->CampusID;
            $out['count']++;
            $bucket = $out['by_campus'][$campus] ?? ['count' => 0, 'ids' => [], 'ids_truncated' => false];
            $bucket['count']++;
            if (count($bucket['ids']) < self::ID_CAP_PER_CAMPUS) {
                $bucket['ids'][] = (int) $row->id;
            } else {
                $bucket['ids_truncated'] = true;
            }
            $out['by_campus'][$campus] = $bucket;
        }
        ksort($out['by_campus']);

        return $out;
    }
}
