"""Synthetic-only regression for PR4213's display-resolver review finding."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

from scripts.tests.test_case_dump_php_lint import render_secret_case, case_block

ROOT = Path(__file__).parents[2]
CASE = 'inapp_414_amount_source'

HARNESS = r'''<?php
namespace Illuminate\Database\Eloquent {
class Collection implements \Countable {
    public function __construct(public array $rows = []) {}
    public function filter(callable $f): self { return new self(array_values(array_filter($this->rows, $f))); }
    public function sum(callable $f): int { return array_sum(array_map($f, $this->rows)); }
    public function contains(callable $f): bool { foreach ($this->rows as $r) if ($f($r)) return true; return false; }
    public function isNotEmpty(): bool { return count($this->rows) > 0; }
    public function count(): int { return count($this->rows); }
}
}
namespace App\Models {
class SyntheticModel {
    private array $attributes = []; private array $relations = [];
    public function setConnection($v): void { if ($v !== 'synthetic') throw new \RuntimeException('unexpected connection'); }
    public function setRawAttributes(array $v): void { $this->attributes = $v; }
    public function setRelation(string $k, $v): void { $this->relations[$k] = $v; }
    public function relationLoaded(string $k): bool { return array_key_exists($k, $this->relations); }
    public function getRelationValue(string $k) { return $this->relations[$k]; }
    public function getAttribute(string $k) { return $this->attributes[$k] ?? null; }
    public function getKey() { return $this->attributes['ID'] ?? $this->attributes['id']; }
    public function __get(string $k) { return $this->getAttribute($k); }
    public function __isset(string $k): bool { return isset($this->attributes[$k]); }
}
class StudentClass extends SyntheticModel {}
class StudentClassPricingAmendment extends SyntheticModel {}
class Invoice extends SyntheticModel {}
class Payment extends SyntheticModel {}
class InvoiceItem extends SyntheticModel {}
}
namespace App\Services {
class MonthlyBillingService {
    public int $calls = 0;
    public function summarizePeriod($course, $period): array {
        if ($course->getKey() !== 789 || $period !== '2026-01') throw new \RuntimeException('unexpected target');
        $this->calls++;
        return ['charge'=>9000,'source'=>'billable_sessions','period_sessions'=>6,
            'period_start'=>'2026-01-01','period_end'=>'2026-01-31'];
    }
    public function serviceRangeForCourse($invoice, $courseId): array {
        $row=$invoice->getRelationValue('items')->rows[0]; return [$row->PeriodStart,$row->PeriodEnd];
    }
}
}
namespace {
require __DIR__ . '/resolver.php';
function app($type) {
    if ($type !== \App\Services\InvoiceAmountReconciliationService::class) throw new \RuntimeException('unexpected service');
    return $GLOBALS['resolver'];
}
function collect(): \Illuminate\Database\Eloquent\Collection { return new \Illuminate\Database\Eloquent\Collection(); }
function project(string $status, int $payment, bool $crossMonth, int $invoiceClass, string $mode): array {
    $monthly=new \App\Services\MonthlyBillingService();
    $GLOBALS['resolver']=new \App\Services\InvoiceAmountReconciliationService($monthly);
    $targetStudentClassId=789; $cap=200;
    $conn=new class { public function getName(): string { return 'synthetic'; } };
    $c=(object)['schedule_mode'=>$mode,'start_date'=>'2026-01-01','end_date'=>$crossMonth?'2026-03-31':'2026-01-31',
        'rate'=>1500,'rate_unit'=>'session','charge'=>6000,'package_id'=>null];
    $invoices=[(object)['invoice_id'=>31,'student_class_id'=>$invoiceClass,'total_amount'=>6000,
        'status'=>$status,'billing_period'=>'2026-01','issue_date'=>'2026-01-10']];
    $payments=$payment?[(object)['invoice_id'=>31,'amount'=>$payment,'method'=>'cash']]:[];
    $lines=[(object)['invoice_id'=>31,'student_class_id'=>789,'period_start'=>'2026-01-01',
        'period_end'=>$crossMonth?'2026-02-28':'2026-01-31']];
    $amendments=[];
/* PROJECTION */
    return ['projection'=>$displayProjections[31],'monthly_calls'=>$monthly->calls];
}
$results=[];
foreach ([['unpaid',0,false,789,'date'],['paid',100,false,789,'date'],['partial',100,false,789,'date'],
    ['unpaid',100,false,789,'date'],['unpaid',0,true,789,'date'],['unpaid',0,false,789,'count'],
    ['unpaid',0,false,790,'date'],['void',0,false,789,'date']] as $args) $results[]=project(...$args);
echo json_encode($results),PHP_EOL;
}
'''


class DisplayProjectionTest(unittest.TestCase):
    def rendered_block(self):
        r = render_secret_case(CASE)
        self.assertEqual(r.returncode, 0, r.stderr)
        return case_block(r.stdout, CASE)

    def test_projection_stays_inside_transaction_and_uses_selected_relations(self):
        block = self.rendered_block()
        self.assertLess(block.index('beginTransaction()'), block.index('$displayResolver->resolve('))
        self.assertLess(block.index('$displayResolver->resolve('), block.index('} finally {'))
        for relation in ('pricingAmendments', 'payments', 'items'):
            self.assertIn(f"setRelation('{relation}'", block)
        self.assertIn('(int) $i->student_class_id !== $targetStudentClassId', block)
        self.assertIn('array_intersect_key(', block)
        self.assertIn("'display_projection' => $displayProjections[$iid]", block)
        self.assertNotIn('reviewSessions(', block)
        self.assertNotIn('billableSessionDetailsForPeriod(', block)

    def test_real_resolver_projects_unpaid_only_and_never_other_contracts(self):
        block = self.rendered_block()
        projection = '// Project display amounts' + block.split('// Project display amounts', 1)[1].split('// End display projection;', 1)[0]
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / 'resolver.php').write_text((ROOT / 'backend/app/Services/InvoiceAmountReconciliationService.php').read_text())
            (root / 'fixture.php').write_text(HARNESS.replace('/* PROJECTION */', projection))
            r = subprocess.run(['php', str(root / 'fixture.php')], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        rows = json.loads(r.stdout)
        unpaid = rows[0]['projection']
        self.assertEqual(unpaid['total_amount'], 9000)
        self.assertEqual(unpaid['computed_total_amount'], 9000)
        self.assertEqual(unpaid['amount_source'], 'billable_sessions')
        self.assertEqual(unpaid['period_sessions'], 6)
        self.assertEqual(unpaid['outstanding_amount'], 9000)
        for row in rows[1:6]:
            self.assertEqual(row['projection']['total_amount'], 6000)
            self.assertEqual(row['projection']['amount_source'], 'invoice_total')
        for row in rows[4:]:
            self.assertEqual(row['monthly_calls'], 0)
        for row in rows[6:]:
            self.assertEqual(row['projection'], {'status': 'not_displayed_for_target'})
        allowed = {'status', 'total_amount', 'computed_total_amount', 'outstanding_amount', 'amount_source',
                   'period_sessions', 'amount_discrepancy', 'repriceable'}
        for row in rows:
            self.assertLessEqual(row['projection'].keys(), allowed)


if __name__ == '__main__':
    unittest.main()
