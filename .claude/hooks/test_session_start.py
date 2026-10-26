#!/usr/bin/env python3
"""Offline contract tests: every installer, network and DB executable is a mock."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

HOOK = Path(__file__).with_name('session-start.sh')


class SessionStartSecurityTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.project = self.root / 'project'
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        for part in ['backend', 'frontend', 'scripts']:
            (self.project / part).mkdir(parents=True)
        (self.project / 'backend/composer.lock').write_text(json.dumps({'packages': [{
            'name': 'synthetic/package',
            'dist': {'url': 'https://api.github.com/repos/synthetic/package/zipball/' + 'a'*40}
        }]}))
        (self.project / 'frontend/package-lock.json').write_text('{}')
        self.trace = self.root / 'trace.jsonl'
        self.mode = self.root / 'mode'
        self.mode.write_text('supported')
        mock = self.bin / 'mock'
        mock.write_text('''#!/usr/bin/python3
import json, os, pathlib, sys
root = pathlib.Path(''' + repr(str(self.root)) + ''')
name = pathlib.Path(sys.argv[0]).name
args = sys.argv[1:]
env = {k:v for k,v in os.environ.items() if k not in ('PATH', 'HOME')}
record = {'command':name,'args':args,'env':env}
if name == 'mysql': record['sql'] = sys.stdin.read()
with (root/'trace.jsonl').open('a') as f: f.write(json.dumps(record)+'\\n')
mode = (root/'mode').read_text()
if name in ('git','curl'): sys.exit(22)
if name == 'composer':
    if 'config' in args:
        if mode == 'old-composer': sys.exit(1)
        if '--global' not in args: print('true' if mode == 'project-fallback' else 'false')
    if 'install' in args:
        if mode == 'denied': sys.exit(1)
        pathlib.Path('vendor').mkdir(exist_ok=True)
if name == 'npm': pathlib.Path('node_modules').mkdir(exist_ok=True)
if name == 'mysql' and mode == 'ddl-failed': sys.exit(1)
sys.exit(0)
''')
        mock.chmod(0o755)
        for name in ['composer','php','npm','node','git','curl','mysql','mysqladmin','mariadbd','service']:
            (self.bin / name).symlink_to(mock)
        self.env = {
            'PATH': str(self.bin) + ':/usr/bin:/bin', 'HOME': str(self.root),
            'CLAUDE_CODE_REMOTE': 'true', 'CLAUDE_PROJECT_DIR': str(self.project),
            'HTTP_PROXY': 'http://synthetic-proxy.invalid:8080',
            'HTTPS_PROXY': 'http://synthetic-proxy.invalid:8080',
            'NO_PROXY': 'localhost,127.0.0.1',
            'CURL_CA_BUNDLE': '/synthetic/runtime-ca.pem',
            'MYSQL_HOST': 'synthetic-remote.invalid', 'MYSQL_UNIX_PORT': '/wrong/socket',
            'MYSQL_PWD': 'synthetic-only', 'GH_TOKEN': 'synthetic-only',
            'ALLTRUE_TRIAGE_TOKEN': 'synthetic-only', 'NODE_OPTIONS': '--require=/synthetic/preload'
        }

    def run_hook(self):
        r = subprocess.run(['/bin/bash', str(HOOK)], env=self.env, capture_output=True, text=True, timeout=10)
        self.assertEqual(r.returncode, 0, r.stderr)
        calls = [json.loads(x) for x in self.trace.read_text().splitlines()] if self.trace.exists() else []
        return r, calls

    def test_not_cloud_has_no_side_effects(self):
        self.env['CLAUDE_CODE_REMOTE'] = 'false'
        _, calls = self.run_hook()
        self.assertEqual(calls, [])

    def test_denied_install_has_no_alternate_network_or_success_stamp(self):
        self.mode.write_text('denied')
        _, calls = self.run_hook()
        self.assertNotIn('git', [x['command'] for x in calls])
        self.assertNotIn('curl', [x['command'] for x in calls])
        self.assertFalse((self.project/'backend/vendor/.session-start.stamp').exists())

    def test_unsupported_composer_does_not_install(self):
        self.mode.write_text('old-composer')
        _, calls = self.run_hook()
        self.assertFalse(any(x['command']=='composer' and 'install' in x['args'] for x in calls))
        self.assertFalse((self.project/'backend/vendor/.session-start.stamp').exists())

    def test_project_cannot_enable_source_fallback(self):
        self.mode.write_text('project-fallback')
        _, calls = self.run_hook()
        self.assertFalse(any(x['command']=='composer' and 'install' in x['args'] for x in calls))

    def test_installers_keep_proxy_trust_and_strip_credentials_preload(self):
        _, calls = self.run_hook()
        installers = [x for x in calls if x['command'] in ('npm', 'composer')]
        self.assertTrue(installers)
        for x in installers:
            for key in ('HTTP_PROXY','HTTPS_PROXY','NO_PROXY','CURL_CA_BUNDLE'):
                self.assertEqual(x['env'][key], self.env[key])
            self.assertEqual(x['env']['SSL_CERT_FILE'], self.env['CURL_CA_BUNDLE'])
            for key in ('GH_TOKEN','ALLTRUE_TRIAGE_TOKEN','MYSQL_PWD','MYSQL_HOST','NODE_OPTIONS'):
                self.assertNotIn(key, x['env'])
        npm = next(x for x in calls if x['command']=='npm')
        self.assertIn('--ignore-scripts', npm['args'])
        composer = next(x for x in calls if x['command']=='composer' and 'install' in x['args'])
        self.assertIn('--no-scripts', composer['args'])
        self.assertIn('--no-plugins', composer['args'])
        self.assertNotEqual(composer['env']['COMPOSER_HOME'], str(self.root))

    def test_database_endpoint_is_fixed_local_socket(self):
        _, calls = self.run_hook()
        db = [x for x in calls if x['command'] in ('mysqladmin','mysql')]
        self.assertTrue(db)
        for x in db:
            self.assertEqual(x['args'][0], '--no-defaults')
            self.assertIn('--protocol=socket', x['args'])
            self.assertIn('--socket=/run/mysqld/mysqld.sock', x['args'])
            for key in ('MYSQL_HOST','MYSQL_UNIX_PORT','MYSQL_PWD'):
                self.assertNotIn(key, x['env'])
        sql = next(x['sql'] for x in db if x['command']=='mysql')
        self.assertIn('AllTrue_test', sql)
        self.assertNotIn('AllTrue.*', sql)

    def test_failed_database_setup_never_claims_ready(self):
        self.mode.write_text('ddl-failed')
        r, _ = self.run_hook()
        self.assertNotIn('local test DB ready', r.stderr)
        self.assertIn('readiness unknown', r.stderr)

    def test_successful_warm_run_skips_installs(self):
        self.run_hook()
        _, calls = self.run_hook()
        self.assertEqual(sum(x['command']=='npm' for x in calls), 1)
        self.assertEqual(sum(x['command']=='composer' and 'install' in x['args'] for x in calls), 1)
        self.assertEqual(sum(x['command']=='node' for x in calls), 1)


if __name__ == '__main__':
    unittest.main()
