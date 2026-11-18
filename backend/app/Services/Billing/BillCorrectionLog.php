<?php

namespace App\Services\Billing;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** HQ 更正紀錄: the bill_correction rows of invoice_corrections, newest first (read only; campus and operator come from one join each). */
final class BillCorrectionLog
{
    /** @param array{campus_id?:int|null, month?:string|null, per_page?:int|null} $filters */
    public function page(array $filters): LengthAwarePaginator
    {
        if (!BillCorrection::schemaReady()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
        }
        $q = DB::table('invoice_corrections as c')
            ->leftJoin('Campus as ca', 'ca.id', '=', 'c.campus_id')
            ->leftJoin('User as u', 'u.id', '=', 'c.operator_user_id')
            ->where('c.kind', BillCorrection::KIND)
            ->when($filters['campus_id'] ?? null, fn ($w, $id) => $w->where('c.campus_id', (int) $id))
            ->when($filters['month'] ?? null, fn ($w, $m) => $w->where('c.created_at', '>=', "{$m}-01 00:00:00")->where('c.created_at', '<', date('Y-m-d', strtotime("{$m}-01 +1 month")).' 00:00:00'))
            ->orderByDesc('c.id')
            ->select(['c.id', 'c.created_at', 'c.campus_id', 'ca.name as campus_name', 'c.original_invoice_id', 'c.replacement_invoice_id',
                'c.original_invoice_total as old_total', 'c.new_charge as new_total', 'c.reason', 'u.Name as operator_name']);

        return $q->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }
}
