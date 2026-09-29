import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class StudentSessionDiagnoseTest(unittest.TestCase):
    def run_probe(self, campus='16', date='2026-09-23'):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'env').write_text('DB_USERNAME=fixture\nDB_PASSWORD=fixture\nDB_DATABASE=fixture\n')
            capture = path / 'queries'
            mysql = path / 'mysql'
            mysql.write_text('#!/usr/bin/env python3\nimport json, os, sys\nwith open(os.environ["QUERY_CAPTURE"], "a") as f:\n f.write(json.dumps(sys.argv[sys.argv.index("-e") + 1]) + "\\n")\n')
            mysql.chmod(0o755)
            env = dict(os.environ, PATH=f'{path}:{os.environ["PATH"]}', ENV_FILE=str(path / 'env'),
                       QUERY_CAPTURE=str(capture), STUDENT_NAME='Fixture', TEACHER_NAME='',
                       CAMPUS_ID=campus, DATE=date)
            result = subprocess.run(['bash', str(ROOT / 'scripts/diagnose-student-session.sh')],
                                    env=env, text=True, capture_output=True)
            queries = [json.loads(line) for line in capture.read_text().splitlines()] if capture.exists() else []
            return result, queries

    def test_reports_and_pricing_are_read_without_widening_student_campus_scope(self):
        result, queries = self.run_probe()
        self.assertEqual(0, result.returncode, result.stderr)
        for query in queries:
            self.assertTrue(query.lstrip().startswith('SELECT'), query)
            self.assertRegex(query, r'CampusID=16')
        reports = next(query for query in queries if 'FROM payment_reports' in query)
        self.assertIn("s.name='Fixture'", reports)
        self.assertIn('reported_amount', reports)
        self.assertIn('confirmed_by', reports)
        self.assertIn('LIMIT 200', reports)
        self.assertNotIn('report_token_hash', reports)
        self.assertNotIn('account_last5', reports)
        self.assertTrue(any('FROM student_class_pricing_amendments' in query for query in queries))

    def test_invalid_scope_never_opens_database(self):
        for campus, date in [('16 OR 1=1', '2026-09-23'), ('16', '2026-02-30'), ('16', "2026-09-23' OR 1=1")]:
            result, queries = self.run_probe(campus, date)
            self.assertEqual(2, result.returncode)
            self.assertEqual([], queries)


if __name__ == '__main__':
    unittest.main()
