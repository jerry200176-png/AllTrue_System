<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;

/** Read-only, lossless billing source manifest for ordinary-close review. */
final class OrdinaryCloseBillingCensus
{
    private const DOMAIN = 'alltrue-ordinary-close-billing-census-v1|';
    private const MAX_CANONICAL_SOURCE_BYTES = 8388608;
    private int $budget;
    private array $facts = [];

    public function captureLocked(int $courseId, int $studentId, int $remainingFactBudget = 1000): array
    {
        if (DB::transactionLevel() < 1 || $courseId < 1 || $studentId < 1 || $remainingFactBudget < 0 || $remainingFactBudget > 1000) {
            $this->fail('invalid_context');
        }
        $this->budget = $remainingFactBudget;
        $this->courseId = $courseId;
        try {
            $student = DB::table('Student')->where('id', $studentId)->lockForUpdate()->first(['id', 'CampusID']);
            $course = DB::table('StudentClass')->where('ID', $courseId)->lockForUpdate()->first();
            if (!$student || !$course || (int) $course->StudentID !== $studentId || (int) $student->CampusID < 1) $this->fail('owner_mismatch');
            $campusId = (int) $student->CampusID;

            $headers = $this->read('Invoice', fn ($q) => $q->where('StudentClassID', $courseId));
            $items = $this->read('InvoiceItem', fn ($q) => $q->where('StudentClassID', $courseId));
            $reports = $this->read('payment_reports', fn ($q) => $q->where('StudentClassID', $courseId));
            $corrections = $this->read('invoice_corrections', fn ($q) => $q->where('student_class_id', $courseId));
            $pricing = $this->read('student_class_pricing_amendments', fn ($q) => $q->where('student_class_id', $courseId));
            $directPayments = $this->read('Payment', fn ($q) => $q->whereIn('id', $this->ids($reports, 'payment_id')));

            $invoiceIds = $this->ids($headers, 'id');
            $invoiceIds = $this->union($invoiceIds, $this->ids($items, 'InvoiceID'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($reports, 'InvoiceID'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($corrections, 'original_invoice_id'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($corrections, 'replacement_invoice_id'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($directPayments, 'InvoiceID'));
            $headerRows = $this->read('Invoice', fn ($q) => $q->whereIn('id', $invoiceIds));
            $items = $this->mergeRows($items, $this->read('InvoiceItem', fn ($q) => $q->whereIn('InvoiceID', $invoiceIds)));
            $this->validateInvoices($headerRows, $items, $invoiceIds, $courseId, $studentId);
            $invoiceIds = $this->union($invoiceIds, $this->ids($headerRows, 'id'));

            $payments = $this->mergeRows($directPayments, $this->read('Payment', fn ($q) => $q->whereIn('InvoiceID', $invoiceIds)));
            $paymentIds = $this->ids($payments, 'id');
            $reports = $this->mergeRows($reports, $this->read('payment_reports', function ($q) use ($invoiceIds, $paymentIds, $payments, $corrections) {
                $reportIds = $this->union($this->ids($payments, 'payment_report_id'), $this->ids($corrections, 'payment_report_id'));
                return $q->where('StudentClassID', $this->courseId)->orWhereIn('InvoiceID', $invoiceIds)
                    ->orWhereIn('payment_id', $paymentIds)->orWhereIn('id', $reportIds);
            }));
            $reportIds = $this->ids($reports, 'id');
            $payments = $this->mergeRows($payments, $this->read('Payment', fn ($q) => $q->whereIn('id', $this->ids($reports, 'payment_id'))));
            $payments = $this->mergeRows($payments, $this->read('Payment', fn ($q) => $q->whereIn('payment_report_id', $reportIds)));
            $paymentIds = $this->union($paymentIds, $this->ids($payments, 'id'));
            $this->validatePaymentsAndReports($payments, $reports, $invoiceIds, $paymentIds, $courseId, $studentId);

            $corrections = $this->mergeRows($corrections, $this->read('invoice_corrections', fn ($q) => $q
                ->where('student_class_id', $courseId)->orWhereIn('original_invoice_id', $invoiceIds)
                ->orWhereIn('replacement_invoice_id', $invoiceIds)->orWhereIn('payment_report_id', $reportIds)));
            $this->validateCorrections($corrections, $invoiceIds, $reportIds, $courseId, $campusId);
            $this->validateInvoices($headerRows, $items, $invoiceIds, $courseId, $studentId);

            $proofRows = $this->proofs($paymentIds, $invoiceIds, $courseId);
            $sources = [
                'invoices' => ['Invoice', $headerRows], 'invoice_items' => ['InvoiceItem', $items],
                'payments' => ['Payment', $payments], 'payment_reports' => ['payment_reports', $reports],
                'invoice_corrections' => ['invoice_corrections', $corrections],
                'pricing_amendments' => ['student_class_pricing_amendments', $pricing],
                'payment_restatement_proofs' => ['pop_execution_records', $proofRows],
            ];
            $ids = $digests = [];
            foreach ($sources as $role => [$table, $tableRows]) {
                $ids[$role] = array_map(static fn ($row) => $row['id'], $tableRows);
                $digests[$role] = $this->digest($table, $role, $tableRows);
            }
            return ['owner_binding' => ['course_id' => $courseId, 'student_id' => $studentId, 'campus_id' => $campusId],
                'captured_sources' => ['completeness' => 'PARTIAL', 'tables' => array_keys($sources)], 'id_sets' => $ids, 'table_digests' => $digests,
                'fact_count' => count($this->facts), 'context_ids' => ['course_id' => $courseId]];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail('source_unavailable');
        }
    }

    private int $courseId;

    private function read(string $table, callable $predicate): array
    {
        if (!Schema::hasTable($table)) $this->fail('source_unavailable');
        $cols = Schema::getColumnListing($table);
        if (!in_array('id', $cols, true)) $this->fail('source_unavailable');
        $query = DB::table($table);
        $predicate($query);
        $rows = $query->orderBy('id')->lockForUpdate()->limit($this->budget + 1)->get()->map(static fn ($r) => (array) $r)->all();
        if (count($rows) > $this->budget) $this->fail('fact_budget_exceeded');
        foreach ($rows as $row) $this->addFact($table, $row['id']);
        return $rows;
    }

    private function addFact(string $table, mixed $id): void
    {
        $key = $table.'|'.(string) $id;
        if (!isset($this->facts[$key])) {
            if (count($this->facts) >= $this->budget) $this->fail('fact_budget_exceeded');
            $this->facts[$key] = true;
        }
    }

    private function validateInvoices(array $headers, array $items, array $invoiceIds, int $course, int $student): void
    {
        $byId = $this->byId($headers);
        foreach ($invoiceIds as $id) if (!isset($byId[(string) $id])) $this->fail('dangling_invoice');
        $itemsByInvoice = [];
        foreach ($items as $item) {
            if (!in_array((int) $item['InvoiceID'], $invoiceIds, true)) $this->fail('foreign_invoice_item');
            $itemsByInvoice[(string) $item['InvoiceID']][] = $item;
            if (isset($item['StudentClassID']) && $item['StudentClassID'] !== null && (int) $item['StudentClassID'] !== $course) $this->fail('foreign_invoice_item');
        }
        foreach ($byId as $id => $header) {
            if ((int) $header['StudentID'] !== $student || ($header['StudentClassID'] !== null && (int) $header['StudentClassID'] !== $course)) $this->fail('foreign_invoice');
            if ($header['StudentClassID'] === null && empty($itemsByInvoice[$id])) $this->fail('unowned_invoice');
            if ($header['StudentClassID'] === null) foreach ($itemsByInvoice[$id] as $item) {
                if (!isset($item['StudentClassID']) || $item['StudentClassID'] === null || (int) $item['StudentClassID'] !== $course) $this->fail('unowned_invoice_item');
            }
        }
    }

    private function validatePaymentsAndReports(array $payments, array $reports, array $invoices, array $paymentIds, int $course, int $student): void
    {
        $paymentMap = $this->byId($payments); $reportMap = $this->byId($reports);
        $reportPointers = [];
        foreach ($payments as $p) {
            if (!in_array((int) $p['InvoiceID'], array_map('intval', $invoices), true)) $this->fail('foreign_payment');
            $rid = $p['payment_report_id'] ?? null;
            if ($rid !== null) {
                if (!isset($reportMap[(string) $rid]) || (($reportMap[(string) $rid]['payment_id'] ?? null) !== null
                    && (string) $reportMap[(string) $rid]['payment_id'] !== (string) $p['id'])) $this->fail('payment_report_conflict');
                $reportPointers[(string) $rid][] = (string) $p['id'];
            }
        }
        foreach ($reportPointers as $linked) if (count($linked) !== 1) $this->fail('duplicate_report_payment');
        foreach ($reports as $r) {
            if ((int) $r['StudentID'] !== $student || ($r['StudentClassID'] !== null && (int) $r['StudentClassID'] !== $course)) $this->fail('foreign_report');
            if (($r['InvoiceID'] ?? null) !== null && !in_array((int) $r['InvoiceID'], $invoices, true)) $this->fail('foreign_report_invoice');
            $pid = $r['payment_id'] ?? null;
            if ($pid !== null && (!isset($paymentMap[(string) $pid]) || (string) $paymentMap[(string) $pid]['InvoiceID'] !== (string) ($r['InvoiceID'] ?? $paymentMap[(string) $pid]['InvoiceID']))) $this->fail('report_payment_conflict');
        }
    }

    private function validateCorrections(array $rows, array $invoices, array $reports, int $course, int $campus): void
    {
        foreach ($rows as $r) {
            if ((int) $r['student_class_id'] !== $course || (($r['campus_id'] ?? null) !== null && (int) $r['campus_id'] !== $campus)) $this->fail('foreign_correction');
            foreach (['original_invoice_id', 'replacement_invoice_id'] as $link) {
                if (($r[$link] ?? null) !== null && !in_array((int) $r[$link], $invoices, true)) $this->fail('foreign_correction_invoice');
            }
            if (($r['payment_report_id'] ?? null) !== null && !in_array((int) $r['payment_report_id'], $reports, true)) $this->fail('dangling_correction_report');
        }
    }

    private function proofs(array $paymentIds, array $invoiceIds, int $course): array
    {
        if (!Schema::hasTable('pop_execution_records')) $this->fail('source_unavailable');
        $rows = DB::table('pop_execution_records')->whereIn('phase', ['reversal-proof', 'reversal-proof-rollback'])
            ->where(function ($q) use ($paymentIds, $course) {
                $q->where('snapshot->scope->course_id', $course);
                if ($paymentIds) $q->orWhereIn('snapshot->payment_id', $paymentIds)->orWhereIn('snapshot->scope->payment_id', $paymentIds);
            })
            ->orderBy('id')->lockForUpdate()->limit($this->budget + 1)->get()->map(static fn ($r) => (array) $r)->all();
        if (count($rows) > $this->budget) $this->fail('fact_budget_exceeded');
        $proofsById = [];
        foreach ($rows as $row) if ($row['phase'] === 'reversal-proof') {
            try { $proofSnapshot = json_decode((string) ($row['snapshot'] ?? ''), true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { $this->fail('malformed_proof'); }
            $proofsById[(string) $row['id']] = ['operation_id' => (string) $row['operation_id'], 'snapshot' => $proofSnapshot];
        }
        $selected = [];
        foreach ($rows as $r) {
            try { $snap = json_decode((string) ($r['snapshot'] ?? ''), true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { $this->fail('malformed_proof'); }
            $scope = $snap['scope'] ?? [];
            $pid = $snap['payment_id'] ?? $scope['payment_id'] ?? null;
            if ($r['phase'] === 'reversal-proof-rollback') {
                $original = $proofsById[(string) ($snap['original_proof_id'] ?? '')] ?? null;
                if (!$original || $original['operation_id'] !== (string) $r['operation_id']) $this->fail('proof_history_incomplete');
                $scope = $original['snapshot']['scope'] ?? [];
                if ((int) ($snap['payment_id'] ?? 0) !== (int) ($scope['payment_id'] ?? 0)) $this->fail('proof_history_incomplete');
            }
            $relevant = in_array((int) $pid, $paymentIds, true) || (int) ($scope['course_id'] ?? 0) === $course;
            if (!$relevant) continue;
            if (!in_array((int) $pid, $paymentIds, true) || (int) ($scope['course_id'] ?? 0) !== $course
                || !in_array((int) ($scope['invoice_id'] ?? 0), $invoiceIds, true)) $this->fail('foreign_proof');
            $selected[] = $r;
        }
        $originals = [];
        foreach ($selected as $r) if ($r['phase'] === 'reversal-proof') $originals[(string) $r['id']] = (string) $r['operation_id'];
        foreach ($selected as $r) if ($r['phase'] === 'reversal-proof-rollback') {
            $snap = json_decode((string) $r['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (($originals[(string) ($snap['original_proof_id'] ?? '')] ?? null) !== (string) $r['operation_id']) $this->fail('proof_history_incomplete');
        }
        foreach ($selected as $r) $this->addFact('pop_execution_records', $r['id']);
        return $selected;
    }

    private function digest(string $table, string $role, array $rows): string
    {
        $columns = Schema::getColumnListing($table);
        usort($rows, static fn ($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
        try { $json = json_encode(['version' => 1, 'table' => $table, 'role' => $role, 'columns' => $columns, 'rows' => $rows], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); }
        catch (JsonException $e) { $this->fail('malformed_source_value'); }
        if (strlen($json) > self::MAX_CANONICAL_SOURCE_BYTES) $this->fail('source_byte_budget_exceeded');
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true) ?: '';
        if (strlen($key) < 32) $this->fail('key_unavailable');
        return hash_hmac('sha256', self::DOMAIN.$json, $key);
    }

    private function ids(array $rows, string $key): array { return array_values(array_unique(array_map(static fn ($r) => $r[$key] ?? null, $rows))); }
    private function union(array ...$lists): array { $all = []; foreach ($lists as $list) foreach ($list as $id) if ($id !== null) $all[(string) $id] = $id; return array_values($all); }
    private function mergeRows(array $a, array $b): array { $out = []; foreach (array_merge($a, $b) as $r) $out[(string) $r['id']] = $r; return array_values($out); }
    private function byId(array $rows): array { $out = []; foreach ($rows as $r) $out[(string) $r['id']] = $r; return $out; }
    private function fail(string $code): never { throw new RuntimeException('ordinary_close_billing_'.$code); }
}
