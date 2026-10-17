"""Synthetic-only execution of the generated probe's admission/failure paths."""
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from scripts.tests.test_case_dump_php_lint import render_secret_case, case_block, RUN

CASE = 'inapp_414_amount_source'


def php(source):
    with tempfile.NamedTemporaryFile('w', suffix='.php') as f:
        f.write('<?php\n' + source)
        f.flush()
        result = subprocess.run(['php', f.name], capture_output=True, text=True)
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


class LoadBoundsTest(unittest.TestCase):
    def block(self):
        result = render_secret_case(CASE)
        self.assertEqual(result.returncode, 0, result.stderr)
        return case_block(result.stdout, CASE).removeprefix(') {')

    def test_oversized_financial_inputs_and_statement_timeout_discard_all_data(self):
        block = self.block()
        setup = r'''
namespace Illuminate\Database {
class QueryException extends \RuntimeException { public $errorInfo=["HY000",1969,"synthetic-private-binding"]; }
}
namespace App\Models {
class SecurityAuditEvent { static function ref($kind,$id) { return $kind.':synthetic'; } }
class ClassSession {
    static function addGlobalScope($name, $f) {}
    static function retrieved($f) {}
}
}
namespace {
class SyntheticConnection {
    public bool $rolledBack = false;
    function useWriteConnectionWhenReading() {}
    function getPdo() { return new class { function getAttribute($a) { return $GLOBALS['scenario']==='unsupported' ? 'unsupported-engine' : '10.11.8-MariaDB'; } }; }
    function selectOne($sql, $bindings=[]) {
        if (str_contains($sql,'@@SESSION')) return (object)['budget'=>$GLOBALS['scenario']==='mismatch'?0:2];
        return (object)['campus_id'=>11,'student_id'=>987];
    }
    function select($sql, $bindings=[], $read=true) {
        if (str_starts_with($sql,'SET ')) return [];
        if (!str_ends_with($sql,' LIMIT 201')) throw new \LogicException('unbounded read');
        if ($GLOBALS['scenario']==='timeout') throw new \Illuminate\Database\QueryException('SQL synthetic-private-binding');
        if (str_contains($sql,$GLOBALS['scenario'])) return array_fill(0,201,(object)['invoice_id'=>21]);
        return str_contains($sql,'FROM Invoice i') && !str_contains($sql,'IS NULL') ? [(object)['invoice_id'=>21]] : [];
    }
    function beginTransaction() {}
    function rollBack() { $this->rolledBack=true; }
}
function app($name) { return new class { function connection() { return $GLOBALS['conn']; } }; }
$results=[];
foreach (['FROM Invoice i','FROM Payment','FROM InvoiceItem WHERE','FROM payment_reports','FROM student_class_pricing_amendments','IS NULL','FROM security_audit_events','timeout','unsupported','mismatch'] as $scenario) {
$GLOBALS['scenario']=$scenario;
$GLOBALS['conn']=new SyntheticConnection();
$targetStudentClassId=789; $case='inapp_414_amount_source'; $out=['ok'=>true,'private_partial'=>'must disappear'];
'''
        # Later tables require audit refs; these test paths fail before they are reached.
        results = php(setup + block + "\n$results[]=[$out,$GLOBALS['conn']->rolledBack];\n}\necho json_encode($results);\n}")
        for index, (output, rolled_back) in enumerate(results):
            self.assertEqual(rolled_back, index < len(results)-2)
            self.assertFalse(output['ok'])
            self.assertFalse(output['snapshot_complete'])
            self.assertEqual(set(output), {'ok','case','snapshot_complete','reason'})
            self.assertIn(output['reason'], {'input_row_budget_exceeded','statement_timeout','deadline_unavailable'})
            self.assertNotIn('synthetic-private-binding', json.dumps(output))
        for output, _ in results[:-3]:
            self.assertEqual(output['reason'], 'input_row_budget_exceeded')
        self.assertEqual(results[-3][0]['reason'], 'statement_timeout')
        for output, _ in results[-2:]:
            self.assertEqual(output['reason'], 'deadline_unavailable')

    def test_financial_admission_accepts_complete_n_and_rejects_n_plus_one(self):
        block = self.block()
        guard = '$readComplete =' + block.split('$readComplete =', 1)[1].split('$deadlineReady =', 1)[0]
        source = r"""
$cap=200;
$conn=new class {
    public $count=200;
    function select($sql,$bindings) {
        if (!str_ends_with($sql,' LIMIT 201')) throw new \RuntimeException('missing bound');
        return array_fill(0,$this->count,(object)[]);
    }
};
""" + guard + r"""
$complete=count($readComplete('SELECT synthetic'));
$conn->count=201;
try { $readComplete('SELECT synthetic'); $overflow='wrong_partial'; }
catch (\LengthException $e) { $overflow=$e->getMessage(); }
echo json_encode([$complete,$overflow]);
"""
        self.assertEqual(php(source), [200, 'input_row_budget_exceeded'])

    def test_session_budget_rejects_n_plus_one_before_amounts_can_be_returned(self):
        block = self.block()
        guard = '$sessionRows = 0;' + block.split('$sessionRows = 0;', 1)[1].split("$conn->select('SET TRANSACTION", 1)[0]
        source = r'''
namespace App\Models {
class ClassSession {
    public static $scope; public static $retrieved;
    static function addGlobalScope($name,$f) { self::$scope=$f; }
    static function retrieved($f) { self::$retrieved=$f; }
}
}
namespace {
''' + guard + r'''
$q=new class { public $limit; function limit($n) {$this->limit=$n;} };
$scope=\App\Models\ClassSession::$scope; $read=\App\Models\ClassSession::$retrieved;
$scope($q); $initial=$q->limit;
for ($i=0;$i<2000;$i++) $read((object)[]);
$scope($q); $remaining=$q->limit;
try { $read((object)[]); $result='partial_amount_wrong'; }
catch (\LengthException $e) { $result=$e->getMessage(); }
echo json_encode([$initial,$remaining,$result]);
}
'''
        self.assertEqual(php(source), [2001, 1, 'input_row_budget_exceeded'])

    def test_watchdog_is_required_and_has_no_unbounded_fallback_for_case(self):
        syntax = subprocess.run(['bash', '-n'], input=RUN, text=True, capture_output=True)
        self.assertEqual(syntax.returncode, 0, syntax.stderr)
        self.assertIn("command -v timeout >/dev/null", RUN)
        self.assertIn('timeout --signal=TERM --kill-after=5s 120s php artisan tinker', RUN)
        # Exercise the same timeout behavior on a synthetic process, never SSH/PHP production.
        r = subprocess.run(['timeout','--signal=TERM','--kill-after=0.1s','0.05s','python3','-c','import time; time.sleep(5)'],capture_output=True)
        self.assertEqual(r.returncode,124)


if __name__ == '__main__':
    unittest.main()
