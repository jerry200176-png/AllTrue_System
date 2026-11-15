"""Run the existing Node checker against synthetic Laravel CLI inventories, no app/credentials."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[1] / 'ci' / 'check-frontend-api-routes.mjs'

class RouteInventoryTest(unittest.TestCase):
    def run_checker(self, inventory, exit_code=0):
        with tempfile.TemporaryDirectory(prefix='route-inventory-') as temp:
            root = Path(temp)
            (root / 'scripts/ci').mkdir(parents=True)
            (root / 'frontend/src').mkdir(parents=True)
            (root / 'backend').mkdir()
            shutil.copy2(SOURCE, root / 'scripts/ci/check.mjs')
            (root / 'frontend/src/app.js').write_text("fetch('/api/v1/existing');fetch('/api/v1/items/42');fetch('/api/v1/absent');")
            shim = root / 'php'
            shim.write_text('#!/usr/bin/env python3\nimport os,sys\n'
                            'if os.environ["ROUTE_EXIT"] != "0":\n print("ROUTE_DIAGNOSTIC_SENTINEL",file=sys.stderr);sys.exit(int(os.environ["ROUTE_EXIT"]))\n'
                            'print(os.environ["ROUTE_JSON"] if "--json" in sys.argv else "GET|HEAD api/v1/existing ... Controller@index")\n')
            shim.chmod(0o700)
            env = {**os.environ, 'PATH': str(root) + os.pathsep + os.environ['PATH'],
                   'ROUTE_JSON': inventory, 'ROUTE_EXIT': str(exit_code)}
            return subprocess.run(['node', str(root / 'scripts/ci/check.mjs')], env=env, capture_output=True, text=True, timeout=20)

    def test_current_laravel_json_inventory_matches_literals_and_parameters_but_preserves_real_drift(self):
        r = self.run_checker(json.dumps([{'uri': 'api/v1/existing'}, {'uri': 'api/v1/items/{item}'}, {'uri': 'login'}]))
        self.assertEqual(0, r.returncode)
        self.assertIn('2 backend routes', r.stdout)
        self.assertIn('1 frontend path(s)', r.stdout)
        self.assertIn('  - /api/v1/absent', r.stdout)
        self.assertNotIn('  - /api/v1/existing', r.stdout)
        self.assertNotIn('  - /api/v1/items/42', r.stdout)

    def test_invalid_empty_and_partial_inventories_are_unknown_not_all_missing_or_passed(self):
        for inventory in ['not-json', '{}', '[]', '[{"uri":"api/v1/existing"},{"uri":null}]']:
            with self.subTest(inventory=inventory):
                r = self.run_checker(inventory)
                self.assertEqual(0, r.returncode)  # Existing advisory policy stays unchanged.
                self.assertIn('comparison not performed', r.stdout)
                self.assertNotIn('frontend path(s)', r.stdout)
                self.assertNotIn('Every frontend', r.stdout)

    def test_child_failure_retains_diagnostic_and_exit_status_without_false_comparison(self):
        r = self.run_checker('[]', exit_code=7)
        self.assertEqual(0, r.returncode)
        self.assertIn('ROUTE_DIAGNOSTIC_SENTINEL', r.stdout + r.stderr)
        self.assertIn('exit 7', r.stdout)
        self.assertIn('comparison not performed', r.stdout)
        self.assertNotIn('Every frontend', r.stdout)

if __name__ == '__main__':
    unittest.main()
