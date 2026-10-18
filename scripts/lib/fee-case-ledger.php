<?php

/** Bounded SELECT-only evidence. Caller supplies its reviewed case/campus allowlist. */
function alltrue_fee_case_ledger(\Illuminate\Database\Connection $conn, int $courseId, int $campusId, string $campusName): array
{
    $out = ['read_only_transaction' => false, 'campus_bound' => $campusId,
        'snapshot_complete' => false, 'completeness' => 'not_started'];
    if ($conn->transactionLevel() !== 0) {
        return $out + ['reason' => 'existing_transaction'];
    }
    $rows = static function ($query) {
        $result = $query->limit(101)->get();
        if ($result->count() > 100) throw new \LengthException('row_budget');
        return $result;
    };
    $enum = static fn ($v, array $allowed) => $v === null ? null : (in_array($v, $allowed, true) ? $v : 'other');
    $stage = 'connection';
    try {
        $conn->useWriteConnectionWhenReading();
        $version = (string) $conn->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        if (stripos($version, 'MariaDB') !== false) {
            $conn->select('SET SESSION max_statement_time = 2', [], false);
        } elseif (preg_match('/^(8\.|9\.|5\.7\.)/', $version)) {
            $conn->select('SET SESSION max_execution_time = 2000', [], false);
        } else { throw new \RuntimeException('unsupported_engine'); }
        $conn->select('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', [], false);
        $conn->select('SET TRANSACTION READ ONLY', [], false);
        $conn->beginTransaction();
        $out['read_only_transaction'] = true;
        $stage = 'campus';
        if (!$conn->table('Campus')->where('id', $campusId)->where('name', $campusName)->exists()) {
            throw new \RuntimeException('campus_mismatch');
        }
        $stage = 'contract';
        $course = $conn->table('StudentClass as sc')->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where('sc.ID', $courseId)->where('s.CampusID', $campusId)->first([
                'sc.ID', 'sc.StudentID', 'sc.Charge', 'sc.Rate', 'sc.SessionCount', 'sc.UsedSessions',
                'sc.RemainingSessions', 'sc.Paid', 'sc.Pay', 'sc.PayDate', 'sc.Stop', 'sc.ScheduleMode', 'sc.ClassType',
                'sc.PackageID', 'sc.closed_reason', 'sc.rate_unit', 'sc.StartDate', 'sc.EndDate',
            ]);
        if (!$course) throw new \RuntimeException('course_not_found_in_campus');
        $studentId = (int) $course->StudentID;
        $stage = 'invoice_items';
        if ($conn->table('InvoiceItem as ii')->leftJoin('Invoice as i', 'i.id', '=', 'ii.InvoiceID')
            ->where('ii.StudentClassID', $courseId)->whereNull('i.id')->exists()) {
            throw new \RuntimeException('orphan_target_item');
        }
        $linked = $conn->table('Invoice')->where(function ($q) use ($courseId) {
            $q->where('StudentClassID', $courseId)->orWhereIn('id', function ($items) use ($courseId) {
                $items->select('InvoiceID')->from('InvoiceItem')->where('StudentClassID', $courseId);
            });
        });
        if ((clone $linked)->where(fn ($q) => $q->where('StudentID', '<>', $studentId)->orWhereNull('StudentID'))->exists()) {
            throw new \RuntimeException('invoice_owner_mismatch');
        }
        $invoices = $rows($linked->where('StudentID', $studentId)->orderBy('id')->select([
            'id', 'StudentClassID', 'TotalAmount', 'PaidAmount', 'Status', 'IssueDate', 'DueDate', 'zero_amount_reason',
            'billing_period', 'ScheduleModeAtIssue',
        ]));
        $ids = $invoices->pluck('id')->all();
        // Shared invoices are identifiable without exporting other courses or their line amounts.
        $allItems = $rows($conn->table('InvoiceItem')->whereIn('InvoiceID', $ids)->orderBy('id')
            ->select(['id', 'InvoiceID', 'StudentClassID', 'Amount']));
        $referencedIds = $allItems->pluck('StudentClassID')->merge($invoices->pluck('StudentClassID'))
            ->filter(static fn ($id) => $id !== null)->unique()->values()->all();
        $owners = $rows($conn->table('StudentClass as sc')->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereIn('sc.ID', $referencedIds)->orderBy('sc.ID')->select(['sc.ID', 'sc.StudentID', 's.CampusID']))->keyBy('ID');
        foreach ($referencedIds as $id) {
            $owner = $owners->get($id);
            if (!$owner || (int) $owner->StudentID !== $studentId || (int) $owner->CampusID !== $campusId) {
                throw new \RuntimeException('typed_invoice_reference_owner_mismatch');
            }
        }
        $stage = 'reports';
        $reports = $rows($conn->table('payment_reports')->where(function ($q) use ($ids, $courseId) {
            $q->where('StudentClassID', $courseId)->orWhereIn('InvoiceID', $ids);
        })->orderBy('id')->select(['id', 'StudentID', 'StudentClassID', 'InvoiceID', 'reported_amount',
            'status', 'payment_id', 'payment_date', 'confirmed_at', 'voided_at']));
        foreach ($reports as $r) {
            if ((int) $r->StudentID !== $studentId || (int) $r->StudentClassID !== $courseId
                || ($r->InvoiceID !== null && !in_array($r->InvoiceID, $ids))) {
                throw new \RuntimeException('report_owner_mismatch');
            }
        }
        $stage = 'payments';
        $payments = $rows($conn->table('Payment')->whereIn('InvoiceID', $ids)->orderBy('id')
            ->select(['id', 'InvoiceID', 'Amount', 'Method', 'payment_report_id', 'PaidAt']));
        $reportIds = $reports->pluck('id')->all();
        foreach ($payments as $p) {
            if ($p->payment_report_id !== null && !in_array($p->payment_report_id, $reportIds)) {
                throw new \RuntimeException('payment_report_link_mismatch');
            }
        }
        foreach ($reports as $r) {
            if ($r->payment_id === null) continue;
            $p = $payments->firstWhere('id', $r->payment_id);
            if (!$p || (int) $p->InvoiceID !== (int) $r->InvoiceID
                || ($p->payment_report_id !== null && (int) $p->payment_report_id !== (int) $r->id)) {
                throw new \RuntimeException('report_payment_link_mismatch');
            }
        }
        foreach ($payments as $p) {
            if ($p->payment_report_id === null) continue;
            $r = $reports->firstWhere('id', $p->payment_report_id);
            if (!$r || (int) $r->InvoiceID !== (int) $p->InvoiceID) {
                throw new \RuntimeException('payment_invoice_link_mismatch');
            }
        }
        $out['contract'] = (array) $course;
        $out['contract']['ScheduleMode'] = $enum($course->ScheduleMode, ['date', 'count']);
        $out['contract']['ClassType'] = $enum($course->ClassType, ['regular', 'trial', 'tutoring', 'one_on_one', 'one_on_two', 'one_on_three']);
        $out['contract']['rate_unit'] = $enum($course->rate_unit, ['session', 'hour']);
        $out['contract']['closed_reason'] = $enum($course->closed_reason === null ? null : strtolower(trim($course->closed_reason)),
            ['', 'waived', 'usage_settled', 'contract_amended', 'settled_pending', 'settled', 'completed']);
        $stage = 'pricing_amendments';
        $out['pricing_amendments'] = $rows($conn->table('student_class_pricing_amendments')
            ->where('student_class_id', $courseId)->orderBy('effective_from')->orderBy('id')
            ->select(['id', 'effective_from', 'rate', 'rate_unit', 'created_at', 'voided_at']))
            ->map(static function ($a) use ($enum) {
                $row = (array) $a; $row['rate_unit'] = $enum($a->rate_unit, ['session', 'hour']); return $row;
            })->all();
        $out['invoices'] = $invoices->map(static function ($i) use ($payments, $allItems, $courseId, $enum) {
            $net = $payments->where('InvoiceID', $i->id)->sum(static fn ($p) =>
                $p->Method === 'void' || $p->Amount < 0 ? -abs((int) $p->Amount) : max(0, (int) $p->Amount));
            return ['id' => $i->id, 'header_matches_course' => (int) $i->StudentClassID === $courseId,
                'total_amount' => $i->TotalAmount, 'stored_paid_amount' => $i->PaidAmount, 'net_paid' => max(0, $net),
                'status' => $enum($i->Status, ['unpaid', 'partial', 'paid', 'void']),
                'billing_period' => $i->billing_period === null ? null
                    : (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $i->billing_period) ? $i->billing_period : 'other'),
                'schedule_mode_at_issue' => $enum($i->ScheduleModeAtIssue, ['date', 'count']),
                'has_zero_amount_reason' => !empty($i->zero_amount_reason),
                'issue_date' => $i->IssueDate, 'due_date' => $i->DueDate,
                'unscoped_item_count' => $allItems->where('InvoiceID', $i->id)->whereNull('StudentClassID')->count(),
                'other_course_item_count' => $allItems->where('InvoiceID', $i->id)->filter(
                    static fn ($l) => $l->StudentClassID !== null && (int) $l->StudentClassID !== $courseId)->count()];
        })->all();
        $out['items'] = $allItems->filter(static fn ($l) => (int) $l->StudentClassID === $courseId)
            ->map(static fn ($l) => ['id' => $l->id, 'invoice_id' => $l->InvoiceID, 'amount' => $l->Amount])->values()->all();
        $out['reports'] = $reports->map(static function ($r) use ($enum) {
            $a = (array) $r; unset($a['StudentID'], $a['StudentClassID']);
            $a['status'] = $enum($r->status, ['pending', 'confirmed', 'rejected', 'voided']); return $a;
        })->all();
        $out['payments'] = $payments->map(static function ($p) use ($enum) {
            $a = (array) $p; $a['Method'] = $enum($p->Method, ['cash', 'transfer', 'credit_card', 'void', 'transfer_out', 'transfer_in', 'other']); return $a;
        })->all();
        $stage = 'unlinked_invoices';
        $unlinked = $rows($conn->table('Invoice as i')->where('i.StudentID', $studentId)
            ->whereNull('i.StudentClassID')->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('InvoiceItem as ii')->whereColumn('ii.InvoiceID', 'i.id')
                    ->whereNotNull('ii.StudentClassID');
            })->orderBy('i.id')->select(['i.id']));
        $out['unlinked_invoice_ids'] = $unlinked->pluck('id')->all();
        $stage = 'correction_history';
        $schema = $conn->getSchemaBuilder();
        $out['correction_table_present'] = $schema->hasTable('invoice_corrections');
        $columns = $out['correction_table_present'] ? $schema->getColumnListing('invoice_corrections') : [];
        $out['agreed_fee_extension_columns_present'] = $out['correction_table_present'] && array_diff(
            ['kind', 'campus_id', 'old_charge', 'new_charge', 'original_invoice_total', 'request_hash', 'state_hash'], $columns) === [];
        $optional = array_values(array_intersect(['kind', 'campus_id', 'old_charge', 'new_charge', 'original_invoice_total'], $columns));
        $out['corrections'] = $out['correction_table_present']
            ? $rows($conn->table('invoice_corrections')->where('student_class_id', $courseId)->orderBy('id')
                ->select(array_merge(['id', 'original_invoice_id', 'replacement_invoice_id', 'payment_report_id', 'amount', 'created_at'], $optional)))
                ->map(static function ($c) use ($enum) {
                    if (property_exists($c, 'kind')) $c->kind = $enum($c->kind, ['receipt_reissue', 'agreed_fee', 'agreed_fee_unbilled']);
                    return $c;
                })->all()
            : [];
        foreach ($out['corrections'] as $c) {
            if ((isset($c->campus_id) && (int) $c->campus_id !== $campusId)
                || ($c->original_invoice_id !== null && !in_array($c->original_invoice_id, $ids))
                || ($c->replacement_invoice_id !== null && !in_array($c->replacement_invoice_id, $ids))
                || ($c->payment_report_id !== null && !in_array($c->payment_report_id, $reportIds))) {
                throw new \RuntimeException('correction_history_link_mismatch');
            }
        }
        $out['security_audit_table_present'] = $schema->hasTable('security_audit_events');
        $out['correction_schema_ready'] = $out['correction_table_present']
            && $out['agreed_fee_extension_columns_present'] && $out['security_audit_table_present'];
        $hasUnscoped = $allItems->whereNull('StudentClassID')->isNotEmpty();
        $out['snapshot_complete'] = $unlinked->isEmpty() && !$hasUnscoped && $out['correction_schema_ready'];
        $out['completeness'] = !$out['correction_schema_ready'] ? 'correction_schema_missing'
            : ($hasUnscoped ? 'ambiguous_obligations' : ($unlinked->isEmpty() ? 'complete' : 'unlinked_obligations'));
    } catch (\Throwable $e) {
        // Never emit SQL, connection configuration, exception messages or partial ledger payloads.
        $out = array_intersect_key($out, array_flip(['read_only_transaction', 'campus_bound']));
        $out += ['snapshot_complete' => false, 'completeness' => 'incomplete',
            'failure_stage' => $stage,
            'reason' => $e instanceof \LengthException ? 'row_budget_exceeded' : 'scope_schema_or_link_failed'];
    } finally {
        if ($conn->transactionLevel() > 0) $conn->rollBack();
    }
    return $out;
}
