<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Illuminate\Support\Str;

/** Read-only, lossless billing source manifest for ordinary-close review. */
final class OrdinaryCloseBillingCensus
{
    private const DOMAIN = 'alltrue-ordinary-close-billing-census-v1|';
    private const MAX_CANONICAL_SOURCE_BYTES = 8388608;
    private int $budget;
    private array $facts = [];
    private array $reads = [];
    private const REQUIRED = [
        'Invoice' => ['id', 'StudentID', 'StudentClassID'],
        'InvoiceItem' => ['id', 'InvoiceID', 'StudentClassID'],
        'Payment' => ['id', 'InvoiceID', 'payment_report_id', 'Amount', 'Method'],
        'payment_reports' => ['id', 'StudentID', 'StudentClassID', 'InvoiceID', 'payment_id'],
        'invoice_corrections' => ['id', 'student_class_id', 'campus_id', 'original_invoice_id', 'replacement_invoice_id', 'payment_report_id'],
        'student_class_pricing_amendments' => ['id', 'student_class_id'],
        'pop_execution_records' => ['id', 'operation_id', 'phase', 'snapshot'],
    ];

    public function captureLocked(int $courseId, int $studentId, int $remainingFactBudget = 1000): array
    {
        if (DB::transactionLevel() < 1 || $courseId < 1 || $studentId < 1 || $remainingFactBudget < 0 || $remainingFactBudget > 1000) {
            $this->fail('invalid_context');
        }
        $this->facts = [];
        $this->reads = [];
        $this->budget = $remainingFactBudget;
        $this->courseId = $courseId;
        try {
            $student = DB::table('Student')->where('id', $studentId)->lockForUpdate()->first(['id', 'CampusID']);
            $course = DB::table('StudentClass')->where('ID', $courseId)->lockForUpdate()->first();
            if (!$student || !$course || (int) $course->StudentID !== $studentId || (int) $student->CampusID < 1) $this->fail('owner_mismatch');
            $campusId = (int) $student->CampusID;

            $headers = $this->read('Invoice', fn ($q) => $q->where('StudentClassID', $courseId), ['id', 'StudentID', 'StudentClassID']);
            $this->validateHeaderOwners($headers, $studentId);
            $items = $this->read('InvoiceItem', fn ($q) => $q->where('StudentClassID', $courseId));
            $reports = $this->read('payment_reports', fn ($q) => $q->where('StudentClassID', $courseId));
            $corrections = $this->read('invoice_corrections', fn ($q) => $q->where('student_class_id', $courseId));
            $pricing = $this->read('student_class_pricing_amendments', fn ($q) => $q->where('student_class_id', $courseId));
            $directPayments = $this->read('Payment', fn ($q) => $q->whereIn('id', $this->ids($reports, 'payment_id')), ['id', 'InvoiceID']);

            $invoiceIds = $this->ids($headers, 'id');
            $invoiceIds = $this->union($invoiceIds, $this->ids($items, 'InvoiceID'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($reports, 'InvoiceID'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($corrections, 'original_invoice_id'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($corrections, 'replacement_invoice_id'));
            $invoiceIds = $this->union($invoiceIds, $this->ids($directPayments, 'InvoiceID'));
            $routingHeaders = $this->read('Invoice', fn ($q) => $q->whereIn('id', $invoiceIds), ['id', 'StudentID', 'StudentClassID']);
            $this->validateHeaderOwners($routingHeaders, $studentId);
            $headerRows = $this->read('Invoice', fn ($q) => $q->whereIn('id', $invoiceIds));
            $items = $this->mergeRows($items, $this->read('InvoiceItem', fn ($q) => $q->whereIn('InvoiceID', $invoiceIds)));
            $this->validateInvoices($headerRows, $items, $invoiceIds, $courseId, $studentId);
            $invoiceIds = $this->union($invoiceIds, $this->ids($headerRows, 'id'));

            $payments = $this->read('Payment', fn ($q) => $q->whereIn('InvoiceID', $invoiceIds));
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
            $this->validatePaymentsAndReports($payments, $reports, $invoiceIds, $courseId, $studentId);

            $corrections = $this->mergeRows($corrections, $this->read('invoice_corrections', fn ($q) => $q
                ->where('student_class_id', $courseId)->orWhereIn('original_invoice_id', $invoiceIds)
                ->orWhereIn('replacement_invoice_id', $invoiceIds)->orWhereIn('payment_report_id', $reportIds)));
            $this->validateCorrections($corrections, $invoiceIds, $reportIds, $courseId, $campusId);
            $this->validateInvoices($headerRows, $items, $invoiceIds, $courseId, $studentId);

            $proofRows = $this->proofs($payments, $invoiceIds, $studentId, $campusId);
            foreach ($this->reads as [$query, $columns, $expected]) {
                $actual = (clone $query)->get($columns)->map(static fn ($r) => (array) $r)->all();
                if ($actual !== $expected) $this->fail('source_changed');
            }
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
        } catch (\Throwable $e) {
            if ($e::class === RuntimeException::class && preg_match('/\Aordinary_close_billing_[a-z_]+\z/', $e->getMessage())) throw $e;
            $this->fail('source_unavailable');
        }
    }

    private int $courseId;

    private function read(string $table, callable $predicate, array $columns = ['*']): array
    {
        if (!Schema::hasTable($table)) $this->fail('source_unavailable');
        $cols = Schema::getColumnListing($table);
        if (array_diff(self::REQUIRED[$table], $cols)) $this->fail('source_unavailable');
        $query = DB::table($table);
        $predicate($query);
        $query->orderBy('id')->lockForUpdate()->limit($this->budget + 1);
        $rows = $query->get($columns)->map(static fn ($r) => (array) $r)->all();
        $this->reads[] = [clone $query, $columns, $rows];
        if (count($rows) > $this->budget) $this->fail('fact_budget_exceeded');
        foreach ($rows as $row) $this->addFact($table, $row['id']);
        return $rows;
    }

    private function validateHeaderOwners(array $headers, int $student): void
    {
        foreach ($headers as $header) if ((int) $header['StudentID'] !== $student
            || ($header['StudentClassID'] !== null && (int) $header['StudentClassID'] !== $this->courseId)) $this->fail('foreign_invoice');
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
            if (isset($item['StudentClassID']) && (int) $item['StudentClassID'] !== $course) $this->fail('foreign_invoice_item');
        }
        foreach ($byId as $id => $header) {
            if ((int) $header['StudentID'] !== $student || ($header['StudentClassID'] !== null && (int) $header['StudentClassID'] !== $course)) $this->fail('foreign_invoice');
            if ($header['StudentClassID'] === null && empty($itemsByInvoice[$id])) $this->fail('unowned_invoice');
            if ($header['StudentClassID'] === null) foreach ($itemsByInvoice[$id] as $item) {
                if (!isset($item['StudentClassID']) || (int) $item['StudentClassID'] !== $course) $this->fail('unowned_invoice_item');
            }
        }
    }

    private function validatePaymentsAndReports(array $payments, array $reports, array $invoices, int $course, int $student): void
    {
        $paymentMap = $this->byId($payments); $reportMap = $this->byId($reports);
        $reportPointers = [];
        foreach ($payments as $p) {
            if (!in_array((int) $p['InvoiceID'], array_map('intval', $invoices), true)) $this->fail('foreign_payment');
            $rid = $p['payment_report_id'] ?? null;
            if ($rid !== null) {
                if (!isset($reportMap[(string) $rid])) $this->fail('payment_report_conflict');
                $reportInvoice = $reportMap[(string) $rid]['InvoiceID'] ?? null;
                if ($reportInvoice !== null && (int) $reportInvoice !== (int) $p['InvoiceID']) $this->fail('payment_report_conflict');
                $reportPointers[(string) $rid][] = (string) $p['id'];
            }
        }
        foreach ($reportPointers as $reportId => $linked) {
            $positive = array_filter($linked, static function ($paymentId) use ($paymentMap): bool {
                $payment = $paymentMap[(string) $paymentId];
                return (int) $payment['Amount'] > 0 && (string) $payment['Method'] !== 'void';
            });
            $reportPaymentId = $reportMap[$reportId]['payment_id'] ?? null;
            if ($reportPaymentId !== null) {
                $reportPayment = $paymentMap[(string) $reportPaymentId] ?? null;
                if (!$reportPayment || (int) $reportPayment['Amount'] <= 0 || (string) $reportPayment['Method'] === 'void') $this->fail('payment_report_conflict');
                $positive[] = (string) $reportPaymentId;
            }
            if (count(array_unique(array_map('strval', $positive))) > 1) $this->fail('duplicate_report_payment');
            if ($reportPaymentId !== null && !in_array((string) $reportPaymentId, $linked, true)) {
                $reportPayment = $paymentMap[(string) $reportPaymentId] ?? null;
                if (!$reportPayment || (($reportPayment['payment_report_id'] ?? null) !== null
                    && (string) $reportPayment['payment_report_id'] !== (string) $reportId)) $this->fail('payment_report_conflict');
            }
        }
        $reportPaymentClaims = [];
        foreach ($reports as $r) {
            if ((int) $r['StudentID'] !== $student || ($r['StudentClassID'] !== null && (int) $r['StudentClassID'] !== $course)) $this->fail('foreign_report');
            if (($r['InvoiceID'] ?? null) !== null && !in_array((int) $r['InvoiceID'], $invoices, true)) $this->fail('foreign_report_invoice');
            $pid = $r['payment_id'] ?? null;
            if ($pid !== null) {
                $payment = $paymentMap[(string) $pid] ?? null;
                if (!$payment || (int) $payment['Amount'] <= 0 || (string) $payment['Method'] === 'void'
                    || (($r['InvoiceID'] ?? null) !== null && (string) $payment['InvoiceID'] !== (string) $r['InvoiceID'])
                    || (($payment['payment_report_id'] ?? null) !== null && (string) $payment['payment_report_id'] !== (string) $r['id'])) {
                    $this->fail('report_payment_conflict');
                }
                if (isset($reportPaymentClaims[(string) $pid]) && $reportPaymentClaims[(string) $pid] !== (string) $r['id']) $this->fail('duplicate_report_payment');
                $reportPaymentClaims[(string) $pid] = (string) $r['id'];
            }
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

    private function proofs(array $payments, array $invoiceIds, int $student, int $campus): array
    {
        $paymentMap = $this->byId($payments); $paymentIds = $this->ids($payments, 'id');
        $rows = $this->read('pop_execution_records', fn ($q) => $q->whereIn('phase', ['reversal-proof', 'reversal-proof-rollback'])
            ->where(fn ($q) => $q->where('snapshot->scope->course_id', $this->courseId)
                ->orWhereIn('snapshot->payment_id', $paymentIds)->orWhereIn('snapshot->scope->payment_id', $paymentIds)));
        for ($round = 0; $round < 3; $round++) {
            $snapshots = []; $operations = $references = [];
            foreach ($rows as $r) {
                if (!Str::isUuid($r['id']) || !Str::isUuid($r['operation_id'])) $this->fail('malformed_proof');
                $snap = json_decode((string) $r['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($snap) || array_is_list($snap)) $this->fail('malformed_proof');
                $snapshots[$r['id']] = $snap; $operations[] = $r['operation_id'];
                if (isset($snap['original_proof_id'])) {
                    if (!is_string($snap['original_proof_id']) || !Str::isUuid($snap['original_proof_id'])) $this->fail('malformed_proof');
                    $references[] = $snap['original_proof_id'];
                }
            }
            $expanded = $this->read('pop_execution_records', fn ($q) => $q->whereIn('phase', ['reversal-proof', 'reversal-proof-rollback'])
                ->where(fn ($q) => $q->whereIn('operation_id', $operations)->orWhereIn('id', $references)
                    ->orWhereIn('snapshot->original_proof_id', $this->ids($rows, 'id'))));
            $merged = $this->mergeRows($rows, $expanded);
            if (count($merged) === count($rows)) break;
            if ($round === 2) $this->fail('proof_history_incomplete');
            $rows = $merged;
        }
        $map = $this->byId($rows); $originalOperations = [];
        foreach ($rows as $r) {
            $snap = $snapshots[$r['id']];
            if ($r['phase'] === 'reversal-proof') {
                $scope = $snap['scope'] ?? null;
                if (!is_array($scope)) $this->fail('malformed_proof');
                $keys = array_keys($scope); sort($keys);
                if ($keys !== ['campus_id', 'course_id', 'invoice_id', 'payment_id', 'student_id']) $this->fail('malformed_proof');
                foreach ($scope as $id) if (!is_int($id) || $id < 1) $this->fail('malformed_proof');
                $payment = $paymentMap[(string) $scope['payment_id']] ?? null;
                if (!is_int($snap['payment_id'] ?? null) || $snap['payment_id'] !== $scope['payment_id'] || !$payment
                    || $scope['course_id'] !== $this->courseId || $scope['student_id'] !== $student || $scope['campus_id'] !== $campus
                    || !in_array($scope['invoice_id'], $invoiceIds, true) || (int) $payment['InvoiceID'] !== $scope['invoice_id']) $this->fail('foreign_proof');
                if (isset($originalOperations[$r['operation_id']])) $this->fail('proof_history_incomplete');
                $originalOperations[$r['operation_id']] = true;
            } elseif ($r['phase'] === 'reversal-proof-rollback') {
                $original = $map[$snap['original_proof_id'] ?? ''] ?? null;
                $originalSnapshot = $snapshots[$snap['original_proof_id'] ?? ''] ?? [];
                if (!$original || $original['phase'] !== 'reversal-proof' || $original['operation_id'] !== $r['operation_id']
                    || !is_int($snap['payment_id'] ?? null) || $snap['payment_id'] !== ($originalSnapshot['payment_id'] ?? null)) $this->fail('proof_history_incomplete');
            } else $this->fail('malformed_proof');
        }
        return $rows;
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
