<?php

namespace App\Services;

use App\Models\SessionCorrection;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Paid monthly correction. Execution is exposed only through approved POP, not a UI write route. */
final class MonthlyContractCorrectionService
{
    private const MIRRORS = [
        ['LearningRecord', 'ClassSessionID', 'StudentClassID'],
        ['StudentSingIn', 'ClassSessionID', 'StudentClassID'],
        ['session_deduction_ledger', 'class_session_id', 'student_class_id'],
    ];

    public function preview(StudentClass $source, array $input, bool $lock = false): array
    {
        $source->refresh();
        return $this->previewState($source, $input, null, $lock);
    }

    /** Advisory projection only; execute always re-reads authoritative rows. */
    public function previewState(StudentClass $source, array $input, ?array $reviewedGraph = null, bool $lock = false, ?StudentClass $reviewedTarget = null): array
    {
        $data = Validator::make($input, [
            'source_start' => 'required|date_format:Y-m-d', 'source_end' => 'required|date_format:Y-m-d|after_or_equal:source_start',
            'target_start' => 'required|date_format:Y-m-d|after:source_end', 'target_end' => 'required|date_format:Y-m-d|after_or_equal:target_start',
            'target_course_id' => 'nullable|integer|min:1', 'source_charge' => 'required|integer|min:0', 'target_charge' => 'required|integer|min:0',
            'payment_evidence_reference' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:#-]{3,128}$/'],
        ])->validate();
        $this->require((string) $source->getAttribute('ScheduleMode') === 'date' && !(int) $source->getAttribute('PackageID'), '月結更正僅適用獨立月結課程');
        $this->require(!$source->isUsageSettlementLocked(), '此課程已結清鎖定，須另行帳務更正');
        $this->require(substr((string) $source->getAttribute('StartDate'), 0, 10) === $data['source_start'], '原合約開始日須保留，請核對期間');
        $this->require($source->getAttribute('EndDate') && $data['target_end'] === substr((string) $source->getAttribute('EndDate'), 0, 10), '更正須保留原合約結束日，不可同時擴張或縮減服務期間');
        $this->require(Carbon::parse($data['source_end'])->addDay()->toDateString() === $data['target_start'], '新舊期間必須相鄰且不重疊');
        $target = !empty($data['target_course_id']) ? ($reviewedTarget ?? $this->findCourse((int) $data['target_course_id'])) : null;
        if ($reviewedTarget) $this->require((int) $reviewedTarget->getKey() === (int) ($data['target_course_id'] ?? 0) && $reviewedGraph !== null, '投影目標識別不符');
        if (!empty($data['target_course_id'])) $this->require($target !== null, '找不到目標合約');
        if ($target) {
            $this->require((int) $source->getAttribute('ID') !== (int) $target->getAttribute('ID'), '來源與目標不可相同');
            foreach (['StudentID', 'SubjectID', 'TeacherID', 'by1', 'ClassType', 'Rate', 'rate_unit', 'settlement_day'] as $field) {
                $this->require((string) $source->getAttribute($field) === (string) $target->getAttribute($field), '目標合約的學生、科目、老師或計價規則不符');
            }
            $this->require((string) $target->getAttribute('ScheduleMode') === 'date' && !(int) $target->getAttribute('PackageID') && !$target->isUsageSettlementLocked(), '目標不是可更正的獨立月結合約');
            $this->require(substr((string) $target->getAttribute('StartDate'), 0, 10) === $data['target_start'] && substr((string) $target->getAttribute('EndDate'), 0, 10) === $data['target_end'], '目標合約期間不符');
            $this->require(!(int) $target->getAttribute('Paid') && !$target->getAttribute('PayDate'), '目標合約已有收款標記');
            $this->require(!(int) $target->getAttribute('Stop') && !$target->getAttribute('closed_reason'), '目標合約已停用或關閉');
        } else {
            $duplicates = StudentClass::query()->where('StudentID', $source->getAttribute('StudentID'))->where('SubjectID', $source->getAttribute('SubjectID'))
                ->where('ScheduleMode', 'date')->where('ID', '!=', $source->getAttribute('ID'))
                ->where('StartDate', '<=', $data['target_end'])->where('EndDate', '>=', $data['target_start'])->exists();
            $this->require(!$duplicates, '已有重疊的下一期合約，請先選用並核對既有合約');
        }
        $graph = $reviewedGraph ?? $this->graph($source, $target, $lock);
        $selected = array_values(array_filter($graph['sessions'], fn ($row) => (int) $row['StudentClassID'] === (int) $source->getAttribute('ID')
            && $row['SessionDate'] >= $data['target_start'] && $row['SessionDate'] <= $data['target_end']));
        $this->require($selected !== [], '目標期間內沒有可移轉堂次');
        $ids = array_column($selected, 'id');
        foreach ($graph['sessions'] as $row) {
            if ((int) $row['StudentClassID'] !== (int) $source->getAttribute('ID')) continue;
            if (!in_array($row['Status'], ['cancelled', 'voided', 'leave', 'rescheduled'], true)) {
                $this->require(($row['SessionDate'] >= $data['source_start'] && $row['SessionDate'] <= $data['source_end']) || in_array($row['id'], $ids), '仍有第三期有效堂次，請分期核對後處理');
            }
        }
        foreach ($selected as $row) foreach ($graph['sessions'] as $other) {
            if (!$target || (int) $other['StudentClassID'] !== (int) $target->getAttribute('ID') || in_array($other['Status'], ['cancelled', 'voided'], true)) continue;
            $overlap = $row['SessionDate'] === $other['SessionDate']
                && $row['StartTime'] < $other['EndTime'] && $other['StartTime'] < $row['EndTime'];
            $this->require(!$overlap, '目標合約已有重疊時段堂次');
        }
        foreach (self::MIRRORS as [$table, $foreignKey, $owner]) {
            foreach ($graph['mirrors'][$table] as $row) {
                $session = collect($graph['sessions'])->firstWhere('id', $row[$foreignKey]);
                $this->require($session && (int) $row[$owner] === (int) $session['StudentClassID'], '出勤、評量或扣堂關聯不一致，須先核對');
            }
        }
        $this->require($graph['group_members'] === [] && $graph['occurrence_exceptions'] === [], '已有合約群組或調課識別關聯，須另行核對移轉方案');
        $this->requireContainedScheduleChains($graph, $source, $data);
        $sourcePaid = 0;
        $sourceInvoiceTotal = 0;
        $sourceInvoiceCount = 0;
        $moveInvoiceIds = [];
        foreach ($graph['invoices'] as $invoice) {
            if ($invoice['Status'] === 'void') continue;
            $this->require((int) $invoice['StudentID'] === (int) $source->getAttribute('StudentID'), '帳單學生歸屬不符，須先核對');
            $invoiceId = (int) $invoice['id'];
            $payments = array_values(array_filter($graph['payments'], fn ($p) => (int) $p['InvoiceID'] === $invoiceId));
            $net = $payments === [] ? (int) $invoice['PaidAmount'] : max(0, array_sum(array_map(fn ($p) =>
                (int) $p['Amount'] < 0 ? (int) $p['Amount'] : (($p['Method'] ?? '') === 'void' ? 0 : (int) $p['Amount']), $payments)));
            if ($target && (int) $invoice['StudentClassID'] === (int) $target->getAttribute('ID')) {
                $this->require($net === 0 && $payments === [], '目標合約已有付款或沖銷紀錄');
            }
            $period = $invoice['billing_period'];
            $items = array_values(array_filter($graph['items'], fn ($item) => (int) $item['InvoiceID'] === $invoiceId));
            $this->require(count($items) <= 1, '多項帳單須先走帳務更正，不能自動拆分');
            foreach ($items as $item) $this->require((int) $item['StudentClassID'] === (int) $invoice['StudentClassID'], '帳單項目合約歸屬不符，須先核對');
            $item = $items[0] ?? [];
            $this->require($period && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), '帳單缺少服務期間，須先核對付款歸屬');
            $date = Carbon::createFromFormat('!Y-m', $period);
            $start = $item['PeriodStart'] ?? $date->copy()->startOfMonth()->toDateString();
            $end = $item['PeriodEnd'] ?? $date->copy()->endOfMonth()->toDateString();
            if ($start >= $data['source_start'] && $end <= $data['source_end']) {
                $sourcePaid += $net;
                $sourceInvoiceTotal += (int) $invoice['TotalAmount'];
                $sourceInvoiceCount++;
            } else {
                $this->require($start >= $data['target_start'] && $end <= $data['target_end'] && $net === 0 && $payments === []
                    && !in_array($invoice['Status'], ['paid', 'partial'], true), '帳單跨越拆分邊界或新一期已有收款，須先走帳務更正');
                $moveInvoiceIds[] = $invoiceId;
            }
        }
        $targetInvoices = array_values(array_filter($graph['invoices'], fn ($row) => $row['Status'] !== 'void'
            && (in_array((int) $row['id'], $moveInvoiceIds, true) || ($target && (int) $row['StudentClassID'] === (int) $target->getAttribute('ID')))));
        if ($targetInvoices) $this->require(array_sum(array_column($targetInvoices, 'TotalAmount')) === $data['target_charge'], '新期帳單金額與應收不符，須先走帳務更正');
        if ($sourceInvoiceCount) $this->require($sourceInvoiceTotal === $data['source_charge'], '舊期帳單金額與應收不符，須先走帳務更正');
        $this->require($sourcePaid <= $data['source_charge'], '舊期應收不可低於保留的實收金額');
        if ((int) $source->getAttribute('Paid') && $sourcePaid === 0) {
            $this->require(!empty($data['payment_evidence_reference']), '舊期只有已繳標記，須提供已核對的付款期間依據');
        }
        foreach ($graph['reports'] as $report) {
            if (!in_array($report['status'], ['pending', 'confirmed'], true)) continue;
            $this->require($report['status'] === 'confirmed' && !empty($data['payment_evidence_reference'])
                && (int) $report['StudentClassID'] === (int) $source->getAttribute('ID'), '繳費回報的期間須先核對，目標不得繼承舊期回報');
        }
        $this->require($graph['pricing_amendments'] === [], '有歷史價格調整，須另行確認新期定價與移轉方案');
        $snapshot = ['input' => $data, 'graph' => $graph];
        return ['source_course_id' => (int) $source->getAttribute('ID'), 'target_course_id' => $target ? (int) $target->getAttribute('ID') : null,
            'source_period' => [$data['source_start'], $data['source_end']], 'target_period' => [$data['target_start'], $data['target_end']],
            'source_charge' => $data['source_charge'], 'source_paid_amount' => (int) $source->getAttribute('Paid') && $sourcePaid === 0 ? null : $sourcePaid, 'target_charge' => $data['target_charge'],
            'target_payment_status' => 'unpaid', 'session_ids' => $ids, 'move_invoice_ids' => $moveInvoiceIds,
            'confirmation_token' => $this->digest($snapshot), 'snapshot' => $snapshot];
    }

    public function execute(StudentClass $source, array $input, string $token, string $reference): array
    {
        $this->require((bool) preg_match('/^[A-Za-z0-9_.:#-]{3,128}$/', $reference), '修復識別無效');
        return DB::transaction(function () use ($source, $input, $token, $reference) {
            $source = $this->requireCourse((int) $source->getAttribute('ID'), true);
            $existing = SessionCorrection::query()->where('decision_reference', $reference)->whereNull('rolled_back_at')->orderBy('id')->first();
            if ($existing) {
                $result = $existing->snapshot_before;
                $this->require(($result['confirmation_token'] ?? null) === $token && hash_equals($this->digest($result['input']), $this->digest($input)), '修復識別已綁定其他資料');
                return $result;
            }
            $this->require(!SessionCorrection::query()->where('decision_reference', $reference)->exists(), '已回復的修復識別不可重用，須取得新的核准');
            if (!empty($input['target_course_id'])) $this->requireCourse((int) $input['target_course_id'], true);
            $plan = $this->preview($source, $input, true);
            $this->require(hash_equals($plan['confirmation_token'], $token), '預覽已過期，資料有變動，請重新預覽');
            $newTarget = empty($input['target_course_id']);
            $target = $newTarget ? $source->replicate() : $this->requireCourse((int) $input['target_course_id']);
            $target->forceFill(['StartDate' => $input['target_start'], 'EndDate' => $input['target_end'], 'Charge' => $input['target_charge'],
                'Paid' => 0, 'Pay' => 0, 'PayDate' => null, 'Stop' => 0, 'closed_reason' => null, 'UsedSessions' => 0,
                'RemainingSessions' => 0, 'RemainingMinutes' => null, 'PurchasedMinutes' => null, 'settlement_locked_at' => null, 'settlement_snapshot' => null, 'Disconunt' => null]);
            if ($newTarget) {
                $target->setAttribute('pricing_snapshot', null);
                $target->setAttribute('trial_converted_to_id', null);
                $target->setAttribute('MDate', now());
            }
            $target->save();
            $ids = $plan['session_ids'];
            DB::table('ClassSession')->whereIn('id', $ids)->update(['StudentClassID' => $target->getAttribute('ID')]);
            foreach (self::MIRRORS as [$table, $foreignKey, $owner]) DB::table($table)->whereIn($foreignKey, $ids)->update([$owner => $target->getAttribute('ID')]);
            $scheduleIds = array_column(array_filter($plan['snapshot']['graph']['schedules'], fn ($row) => (int) $row['student_course_id'] === (int) $source->getAttribute('ID')
                && $row['schedule_date'] >= $input['target_start'] && $row['schedule_date'] <= $input['target_end']), 'id');
            if ($scheduleIds) DB::table('schedules')->whereIn('id', $scheduleIds)->update(['student_course_id' => $target->getAttribute('ID')]);
            if ($plan['move_invoice_ids']) {
                DB::table('Invoice')->whereIn('id', $plan['move_invoice_ids'])->update(['StudentClassID' => $target->getAttribute('ID')]);
                DB::table('InvoiceItem')->whereIn('InvoiceID', $plan['move_invoice_ids'])->update(['StudentClassID' => $target->getAttribute('ID')]);
            }
            $source->forceFill(['EndDate' => $input['source_end'], 'Charge' => $input['source_charge']])->save();
            $this->recount($source);
            $this->recount($target);
            $result = ['source_course_id' => (int) $source->getAttribute('ID'), 'target_course_id' => (int) $target->getAttribute('ID'), 'new_target' => $newTarget,
                'session_ids' => $ids, 'input' => $input, 'confirmation_token' => $token, 'decision_reference' => $reference,
                'snapshot' => $plan['snapshot'], 'after_digest' => $this->digest($this->graph($source->fresh(), $target->fresh()))];
            foreach ($ids as $id) {
                $row = collect($plan['snapshot']['graph']['sessions'])->firstWhere('id', $id);
                SessionCorrection::query()->create(['session_id' => $id, 'correction_reason' => 'monthly_contract_split', 'decision_reference' => $reference,
                    'decided_at' => now(), 'decided_by_actor' => 'pop-runner', 'previous_status' => $row['Status'], 'new_status' => $row['Status'], 'snapshot_before' => $result]);
            }
            return $result;
        });
    }

    public function verify(array $result): array
    {
        $source = $this->findCourse((int) $result['source_course_id']);
        $target = $this->findCourse((int) $result['target_course_id']);
        $ok = $source && $target && hash_equals($result['after_digest'], $this->digest($this->graph($source, $target)));
        return ['ok' => (bool) $ok, 'errors' => $ok ? [] : ['post_repair_data_drifted']];
    }

    public function rollback(array $result): array
    {
        return DB::transaction(function () use ($result) {
            StudentClass::query()->whereIn('ID', [$result['source_course_id'], $result['target_course_id']])->orderBy('ID')->lockForUpdate()->get();
            $source = $this->requireCourse((int) $result['source_course_id']);
            $target = $this->requireCourse((int) $result['target_course_id']);
            $this->require(hash_equals($result['after_digest'], $this->digest($this->graph($source, $target, true))), '更正後資料已變動，不可自動回復');
            $graph = $result['snapshot']['graph'];
            foreach ($graph['sessions'] as $row) DB::table('ClassSession')->where('id', $row['id'])->update(['StudentClassID' => $row['StudentClassID']]);
            foreach (self::MIRRORS as [$table, , $owner]) foreach ($graph['mirrors'][$table] as $row) DB::table($table)->where('id', $row['id'])->update([$owner => $row[$owner]]);
            foreach ($graph['schedules'] as $row) DB::table('schedules')->where('id', $row['id'])->update(['student_course_id' => $row['student_course_id']]);
            foreach ($graph['invoices'] as $row) DB::table('Invoice')->where('id', $row['id'])->update(['StudentClassID' => $row['StudentClassID']]);
            foreach ($graph['items'] as $row) DB::table('InvoiceItem')->where('id', $row['id'])->update(['StudentClassID' => $row['StudentClassID']]);
            foreach ($graph['courses'] as $row) {
                $id = $row['ID']; unset($row['ID']);
                DB::table('StudentClass')->where('ID', $id)->update($row);
            }
            if ($result['new_target']) StudentClass::query()->where('ID', $result['target_course_id'])->delete();
            SessionCorrection::query()->where('decision_reference', $result['decision_reference'])->whereNull('rolled_back_at')->update(['rolled_back_at' => now()]);
            return ['ok' => true];
        });
    }

    public function snapshotGraph(StudentClass $source, ?StudentClass $target = null, bool $lock = false): array
    {
        return $this->graph($source, $target, $lock);
    }

    private function graph(StudentClass $source, ?StudentClass $target, bool $lock = false): array
    {
        $ids = array_values(array_filter([(int) $source->getAttribute('ID'), $target ? (int) $target->getAttribute('ID') : null]));
        $read = function ($query) use ($lock) { return ($lock ? $query->lockForUpdate() : $query)->get()->map(fn ($row) => (array) $row)->all(); };
        $sessions = $read(DB::table('ClassSession')->whereIn('StudentClassID', $ids)->orderBy('id'));
        $invoices = $read(DB::table('Invoice')->whereIn('StudentClassID', $ids)->orderBy('id'));
        $invoiceIds = array_column($invoices, 'id');
        $schedules = $read(DB::table('schedules')->whereIn('student_course_id', $ids)->orderBy('id'));
        // Include only identifiers for outside links: a parent or child outside
        // this repair must never disappear from the signed precondition check.
        $externalLinks = $schedules === [] ? [] : $read(DB::table('schedules')
            ->where(fn ($query) => $query->whereNotIn('student_course_id', $ids)->orWhereNull('student_course_id'))
            ->where(fn ($query) => $query->whereIn('id', array_filter(array_column($schedules, 'original_schedule_id')))
                ->orWhereIn('original_schedule_id', array_column($schedules, 'id')))
            ->select(['id', 'student_course_id', 'original_schedule_id'])->orderBy('id'));
        $mirrors = [];
        foreach (self::MIRRORS as [$table, $foreignKey, $owner]) {
            $mirrors[$table] = $read(DB::table($table)->where(function ($query) use ($foreignKey, $owner, $sessions, $ids) {
                $query->whereIn($foreignKey, array_column($sessions, 'id'))->orWhereIn($owner, $ids);
            })->orderBy('id'));
        }
        return ['student' => $read(DB::table('Student')->where('id', $source->getAttribute('StudentID'))->select(['id', 'CampusID'])),
            'courses' => $read(DB::table('StudentClass')->whereIn('ID', $ids)->orderBy('ID')),
            'sessions' => $sessions, 'invoices' => $invoices, 'mirrors' => $mirrors,
            'items' => $read(DB::table('InvoiceItem')->whereIn('InvoiceID', $invoiceIds)->orderBy('id')),
            'payments' => $read(DB::table('Payment')->whereIn('InvoiceID', $invoiceIds)->orderBy('id')),
            'reports' => $read(DB::table('payment_reports')->whereIn('StudentClassID', $ids)->orderBy('id')),
            'schedules' => $schedules, 'external_schedule_links' => $externalLinks,
            'pricing_amendments' => $read(DB::table('student_class_pricing_amendments')->whereIn('student_class_id', $ids)->whereNull('voided_at')->orderBy('id')),
            'group_members' => $read(DB::table('course_contract_group_members')->whereIn('student_class_id', $ids)->orderBy('id')),
            'occurrence_exceptions' => $read(DB::table('schedule_change_log')->whereIn('student_course_id', $ids)->orderBy('id'))];
    }

    /** A complete chain can move only as a unit inside one reviewed period. */
    private function requireContainedScheduleChains(array $graph, StudentClass $source, array $input): void
    {
        $this->require($graph['external_schedule_links'] === [], '調課鏈連到範圍外合約，須另行核對移轉方案');
        $byId = array_column($graph['schedules'], null, 'id');
        $period = static function ($row) use ($input): ?string {
            $date = $row['schedule_date'];
            foreach (['source', 'target'] as $name) {
                if ($date && $date >= $input[$name.'_start'] && $date <= $input[$name.'_end']) return $name;
            }
            return null;
        };
        foreach ($graph['schedules'] as $schedule) {
            if (empty($schedule['original_schedule_id'])) continue;
            $seen = [];
            $current = $schedule;
            while (!empty($current['original_schedule_id'])) {
                $this->require(!isset($seen[$current['id']]), '調課鏈循環，須另行核對移轉方案');
                $seen[$current['id']] = true;
                $parent = $byId[$current['original_schedule_id']] ?? null;
                $this->require($parent !== null, '調課鏈缺少原堂次，須另行核對移轉方案');
                foreach ([$current, $parent] as $row) {
                    $this->require((int) $row['student_id'] === (int) $source->getAttribute('StudentID')
                        && (int) $row['branch_id'] === (int) $graph['student'][0]['CampusID'], '調課鏈學生或分校歸屬不符');
                }
                $this->require($parent['status'] === 'rescheduled'
                    && (int) $parent['student_course_id'] === (int) $current['student_course_id'], '調課鏈狀態或合約歸屬不符');
                $this->require($period($current) !== null && $period($current) === $period($parent), '調課鏈跨越拆約期間，須另行核對移轉方案');
                $current = $parent;
            }
        }
    }


    private function findCourse(int $id, bool $lock = false): ?StudentClass
    {
        $query = StudentClass::query()->where('ID', $id);
        if ($lock) $query->lockForUpdate();
        $course = $query->first();
        return $course instanceof StudentClass ? $course : null;
    }

    private function requireCourse(int $id, bool $lock = false): StudentClass
    {
        return $this->findCourse($id, $lock)
            ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(StudentClass::class, [$id]);
    }

    private function recount(StudentClass $course): void
    {
        $stop = (int) $course->getAttribute('Stop');
        $sessions = DB::table('ClassSession')->where('StudentClassID', $course->getAttribute('ID'))->whereNotIn('Status', ['cancelled', 'voided', 'leave', 'rescheduled'])->get();
        $count = $sessions->count();
        $course->forceFill(['SessionCount' => $count, 'monthly_sessions' => $count,
            'TotalHours' => (int) round($sessions->sum(fn ($s) => max(0, (strtotime($s->EndTime) - strtotime($s->StartTime)) / 3600)))])->save();
        SessionDeductionService::recomputeCounters((int) $course->getAttribute('ID'));
        if ($stop !== 0) $course->fresh()->forceFill(['Stop' => $stop])->save();
    }

    private function digest(array $data): string
    {
        $this->require((string) config('app.key') !== '', '更正預覽簽章設定缺失');
        return hash_hmac('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function canonical(array $data): array
    {
        if (!array_is_list($data)) ksort($data);
        foreach ($data as &$value) if (is_array($value)) $value = $this->canonical($value);
        return $data;
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) throw ValidationException::withMessages(['monthly_contract' => [$message]]);
    }
}
