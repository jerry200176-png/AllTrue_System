"""PR4209 + PR4211 integration: synthetic SSH output only; never contact a host."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from scripts.tests.test_dump_ids_only_contract import case_step, case_steps, ENCRYPT_STEP

CASE = 'inapp_414_amount_source'
KEY = 'synthetic-encryption-key-123'
SENTINEL = 'synthetic_private_diagnostic_987654321'


class RestrictedCaseIntegration(unittest.TestCase):
    def shell(self, script, root, env):
        return subprocess.run(['bash'], input=script, cwd=root, env=env, capture_output=True, text=True)

    def exercise(self, *, key=KEY, restricted_id='876543210', ssh_exit=0, bad_case=False, malformed=False, campus=11, read_only=True, subject='數學', teachers='', case=CASE):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            runner = root / 'runner'
            runner.mkdir()
            bindir = root / 'bin'
            bindir.mkdir()
            # Executable stub owns the entire SSH boundary: it cannot run the remote script.
            ssh = bindir / 'ssh'
            ssh.write_text('#!/bin/sh\nprintf called > "$RUNNER_TEMP/ssh-called"\n'
                           'cat "$RUNNER_TEMP/fixture"\nprintf "%s" "$PRIVATE_SENTINEL" >&2\n'
                           'exit "$FAKE_SSH_EXIT"\n')
            ssh.chmod(0o700)
            if case == 'xinzhuang_fee_cases':
                support = root / 'scripts/lib'
                support.mkdir(parents=True)
                (support / 'fee-case-ledger.php').write_bytes((Path(__file__).parents[2] / 'scripts/lib/fee-case-ledger.php').read_bytes())
            payload = {'ok': True, 'case': SENTINEL if bad_case else case,
                       'read_only_transaction': read_only, 'campus_bound': campus,
                       'snapshot_complete': False, 'contract': {'class_id': 'target'},
                       'reason': SENTINEL, 'completeness': SENTINEL,
                       'diagnostic': SENTINEL, 'synthetic_id': restricted_id}
            if case == 'xinzhuang_fee_cases':
                # Alignment bind targets 4064/3693 require eligible + 64-char state_hash (#4289).
                payload['alignment_preview'] = {
                    'eligible': True,
                    'state_hash': 'a' * 64,
                    'old_total': 1,
                    'new_total': 2,
                }
            (runner / 'fixture').write_text('{broken ' + SENTINEL if malformed else json.dumps(payload) + '\n')
            env = {'PATH': str(bindir) + ':' + os.environ['PATH'], 'RUNNER_TEMP': str(runner),
                   'CASE_KEY': case, 'DUMP_ARTIFACT_KEY': key, 'CASE_INPUT_STUDENT_CLASS_ID': restricted_id,
                   'TARGET_STUDENT_ID': '', 'TARGET_STUDENT_CLASS_ID': restricted_id if case == 'xinzhuang_fee_cases' else '', 'EXPECTED_TEACHER_IDS': teachers,
                   'TARGET_SUBJECT_NAME': subject, 'PI_SSH_USER': 'synthetic', 'PI_SSH_HOST': 'invalid.test',
                   'PRIVATE_SENTINEL': SENTINEL, 'FAKE_SSH_EXIT': str(ssh_exit)}
            checked = self.shell(case_steps()[0]['run'], d, env)
            generated_probe = root / 'probe.php'
            script = case_step('Run bounded')['run'].replace('/tmp/production_case_probe.php', str(generated_probe))
            probe = self.shell(script, d, env) if checked.returncode == 0 else checked
            env['PROBE_OUTCOME'] = 'success' if probe.returncode == 0 else 'failure'
            enc = self.shell(case_step(ENCRYPT_STEP)['run'], d, env)
            files = sorted(p.name for p in (root / 'out').iterdir())
            recovered = ''
            if files:
                dec = subprocess.run(['bash', '-o', 'pipefail', '-c',
                    'openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:DUMP_ARTIFACT_KEY '
                    '-in out/production-case.full.tar.gz.enc | tar xzO'],
                    cwd=d, env=env, capture_output=True, text=True)
                self.assertEqual(dec.returncode, 0, dec.stderr)
                recovered = dec.stdout
                for name in files:
                    self.assertNotIn(SENTINEL.encode(), (root / 'out' / name).read_bytes())
                check = subprocess.run(['sha256sum', '-c', files[1]], cwd=root / 'out', capture_output=True)
                self.assertEqual(check.returncode, 0)
            cleanup = case_step('Shred plaintext')['run'].replace('/tmp/production_case_probe.php', str(generated_probe))
            cleaned = self.shell(cleanup, d, env)
            self.assertEqual(cleaned.returncode, 0, cleaned.stderr)
            self.assertFalse((root / 'raw').exists())
            self.assertFalse(generated_probe.exists())
            self.assertFalse((runner / 'production-case-remote.sh').exists())
            public = checked.stdout + checked.stderr + probe.stdout + probe.stderr + enc.stdout + enc.stderr + cleaned.stdout + cleaned.stderr
            self.assertNotIn(SENTINEL, public)
            if restricted_id:
                self.assertNotIn(restricted_id, public)
            return probe.returncode, enc.returncode, files, recovered, (runner / 'ssh-called').exists()

    def test_restricted_probe_encryption_round_trip_and_cleanup(self):
        code, enc, files, recovered, called = self.exercise()
        self.assertEqual((code, enc, called), (0, 0, True))
        self.assertEqual(files, ['production-case.full.tar.gz.enc', 'production-case.full.tar.gz.enc.sha256'])
        self.assertIn(SENTINEL, recovered)

    def test_missing_or_short_encryption_key_never_reaches_ssh_or_upload(self):
        for key in ('', 'short'):
            with self.subTest(key=key):
                code, _, files, _, called = self.exercise(key=key)
                self.assertNotEqual(code, 0)
                self.assertFalse(called)
                self.assertEqual(files, [])

    def test_missing_restricted_id_never_reaches_ssh(self):
        code, _, files, _, called = self.exercise(restricted_id='')
        self.assertNotEqual(code, 0)
        self.assertFalse(called)
        self.assertEqual(files, [])

    def test_ssh_failure_and_malformed_json_keep_diagnostics_encrypted(self):
        for args in ({'ssh_exit': 23}, {'malformed': True}):
            with self.subTest(args=args):
                code, enc, files, recovered, called = self.exercise(**args)
                self.assertNotEqual(code, 0)
                self.assertEqual(enc, 0)
                self.assertTrue(called)
                self.assertEqual(len(files), 2)
                self.assertIn(SENTINEL, recovered)

    def test_restricted_case_refuses_public_subject_and_teacher_overrides(self):
        for args in ({'subject': SENTINEL}, {'teachers': '123'}):
            with self.subTest(args=args):
                code, _, _, _, called = self.exercise(**args)
                self.assertNotEqual(code, 0)
                self.assertFalse(called)

    def test_wrong_campus_or_missing_read_only_evidence_fails(self):
        for args in ({'campus': 12}, {'read_only': False}):
            with self.subTest(args=args):
                code, _, _, _, _ = self.exercise(**args)
                self.assertNotEqual(code, 0)

    def test_payload_case_cannot_be_printed_to_public_log(self):
        code, _, _, _, _ = self.exercise(bad_case=True)
        self.assertNotEqual(code, 0)



class XinzhuangFeeTransport(unittest.TestCase):
    exercise = RestrictedCaseIntegration.exercise
    shell = RestrictedCaseIntegration.shell
    # Reuse the same real encryption/SSH-stub boundary without inheriting old-case test cases.
    def test_fee_case_uses_encrypted_transport_and_cleanup(self):
        code, enc, files, recovered, called = self.exercise(case='xinzhuang_fee_cases', restricted_id='4064')
        self.assertEqual((code, enc, called), (0, 0, True))
        self.assertEqual(len(files), 2)
        self.assertIn(SENTINEL, recovered)

    def test_fee_case_scope_and_payload_guards(self):
        for override in ({'restricted_id': '999999'}, {'campus': 9}, {'read_only': False}, {'bad_case': True}, {'subject': SENTINEL}, {'teachers': '123'}):
            args = dict(case='xinzhuang_fee_cases', restricted_id='4064')
            args.update(override)
            code, _, _, _, _ = self.exercise(**args)
            self.assertNotEqual(code, 0)

    def test_alignment_bind_target_requires_live_hash(self):
        """4064/3693 must not soft-pass without a bindable alignment state_hash."""
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            runner = root / 'runner'
            runner.mkdir()
            bindir = root / 'bin'
            bindir.mkdir()
            ssh = bindir / 'ssh'
            ssh.write_text('#!/bin/sh\ncat "$RUNNER_TEMP/fixture"\nexit 0\n')
            ssh.chmod(0o700)
            support = root / 'scripts/lib'
            support.mkdir(parents=True)
            (support / 'fee-case-ledger.php').write_bytes(
                (Path(__file__).parents[2] / 'scripts/lib/fee-case-ledger.php').read_bytes())
            payload = {
                'ok': True, 'case': 'xinzhuang_fee_cases',
                'read_only_transaction': True, 'campus_bound': 11,
                'snapshot_complete': False,
                'alignment_preview': {'eligible': False, 'error': 'preview_failed'},
            }
            (runner / 'fixture').write_text(json.dumps(payload) + '\n')
            env = {
                'PATH': str(bindir) + ':' + os.environ['PATH'],
                'RUNNER_TEMP': str(runner),
                'CASE_KEY': 'xinzhuang_fee_cases',
                'DUMP_ARTIFACT_KEY': KEY,
                'CASE_INPUT_STUDENT_CLASS_ID': '4064',
                'TARGET_STUDENT_ID': '',
                'TARGET_STUDENT_CLASS_ID': '4064',
                'EXPECTED_TEACHER_IDS': '',
                'TARGET_SUBJECT_NAME': '數學',
                'PI_SSH_USER': 'synthetic',
                'PI_SSH_HOST': 'invalid.test',
            }
            checked = self.shell(case_steps()[0]['run'], d, env)
            generated_probe = root / 'probe.php'
            script = case_step('Run bounded')['run'].replace('/tmp/production_case_probe.php', str(generated_probe))
            probe = self.shell(script, d, env) if checked.returncode == 0 else checked
            self.assertNotEqual(probe.returncode, 0, probe.stderr + probe.stdout)


if __name__ == '__main__':
    unittest.main()
