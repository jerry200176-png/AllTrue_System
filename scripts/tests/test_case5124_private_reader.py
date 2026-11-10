"""No network or production data: native OpenSSL plus synthetic metadata/certificates."""
import base64
import copy
import contextlib
import datetime as dt
import importlib.util
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile
import urllib.parse
import tarfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('reader', ROOT / 'scripts/case5124-private-reader.py')
r = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r)


class PrivateReader(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old_umask = os.umask(0o077)
        cls.fixture = tempfile.TemporaryDirectory(prefix='synthetic-case5124-')
        cls.base = Path(cls.fixture.name)
        cls.cert, cls.key, cls.wrong = [cls.base / s for s in ('fake.cert.pem', 'fake.key.pem', 'wrong.key.pem')]
        def command(args):
            subprocess.run(['openssl', *map(str, args)], check=True, timeout=40,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        command(['req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-subj', '/CN=SYNTHETIC-ONLY',
                 '-days', '1', '-keyout', cls.key, '-out', cls.cert])
        command(['genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:3072', '-out', cls.wrong])
        for path in (cls.cert, cls.key, cls.wrong):
            path.chmod(0o600)

    @classmethod
    def tearDownClass(cls):
        cls.fixture.cleanup()
        os.umask(cls.old_umask)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=self.base)
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        self.addCleanup(patch.stopall)
        patch.object(r, 'CERT', self.cert).start()
        patch.object(r, 'POLICY', self.work / 'policy.json').start()
        self.time = r.now().replace(microsecond=0)
        self.p = {'schema': 1, 'binding': r.BINDING, 'recipient_sha256': r.digest(r.native(['x509', '-in', self.cert, '-outform', 'DER'])),
                  'not_before': (self.time - dt.timedelta(minutes=5)).strftime('%Y-%m-%dT%H:%M:%SZ'),
                  'not_after': (self.time + dt.timedelta(hours=4)).strftime('%Y-%m-%dT%H:%M:%SZ'),
                  'max_captures': 4, 'expected_backend_sha': 'b' * 40, 'deploy_run_id': 900,
                  'source_files_sha256': {n: r.digest((ROOT / n).read_bytes()) for n in (r.HELPER, r.WORKFLOW)}}
        r.POLICY.write_bytes(r.encoded(self.p))
        self.run = {'id': 101, 'repository': {'full_name': r.REPO}, 'path': r.WORKFLOW, 'head_branch': 'main',
                    'head_sha': 'a' * 40, 'event': 'workflow_dispatch', 'display_title': r.BINDING,
                    'actor': {'login': r.OWNER}, 'triggering_actor': {'login': r.OWNER}, 'run_attempt': 1,
                    'status': 'completed', 'conclusion': 'success', 'created_at': self.p['not_before'],
                    'updated_at': self.time.strftime('%Y-%m-%dT%H:%M:%SZ')}
        self.rows = [self.run]
        self.obj = {'ok': True, 'case': 'transfer_capacity_student', 'read_only': True, 'prod_head': 'b' * 40,
                    'student_ids': [493], 'student_count': 1,
                    'target_classes': [{'class_id': 700, 'student_id': 493, 'campus_id': 15}],
                    **{k: [] for k in ('class_sessions', 'active_signins', 'deduction_ledger', 'learning_record_metadata',
                                      'schedules', 'schedule_change_metadata')},
                    'counts': {}, 'verification': {'rows_complete': True}}
        self.raw, self.zipped, self.output = [self.work / s for s in ('raw.json', 'artifact.zip', 'verified.json')]
        self.raw.write_bytes(r.encoded(self.obj))
        self.meta = {}
        self.calls = []
        patch.object(r, 'public_json', self.metadata).start()

    def metadata(self, url):
        self.calls.append(url)
        path = url.split('/' + r.REPO + '/', 1)[-1]
        page = urllib.parse.parse_qs(urllib.parse.urlsplit(url).query).get('page', ['1'])[0]
        if url == 'https://daan.lifenet.com.tw/deployment.json':
            return {'schema': 1, 'source': 'github-actions:deploy.yml', 'backend_sha': 'b' * 40}
        if path == 'actions/runs/101':
            return self.run
        if path == 'actions/runs/900':
            return {**self.run, 'id': 900, 'path': '.github/workflows/deploy.yml', 'status': 'completed', 'conclusion': 'success'}
        if path.startswith('actions/runs/900/jobs?'):
            return {'total_count': 1, 'jobs': [{'name': 'Deploy to Production', 'conclusion': 'success'}] if page == '1' else []}
        if path.startswith('actions/workflows/production-case-dump.yml/runs?'):
            return {'total_count': len(self.rows), 'workflow_runs': self.rows if page == '1' else []}
        if path.startswith('contents/'):
            name = path.removeprefix('contents/').split('?')[0]
            return {'type': 'file', 'encoding': 'base64', 'content': base64.b64encode((ROOT / name).read_bytes()).decode()}
        if path == 'actions/artifacts/501':
            return self.meta
        raise AssertionError('unexpected metadata route')

    def capture(self):
        r.seal(self.p, self.run, self.raw, self.work)
        self.package()

    def package(self):
        with zipfile.ZipFile(self.zipped, 'w') as z:
            for name in (r.SIDECAR, r.SUMMARY):
                z.writestr(name, (self.work / name).read_bytes())
            z.writestr('production-case.full.tar.gz.enc', b'UNCHANGED_SYNTHETIC_LEGACY')
            z.writestr('production-case.full.tar.gz.enc.sha256', b'SYNTHETIC')
        self.meta = {'id': 501, 'name': r.ARTIFACT, 'expired': False, 'workflow_run': {'id': 101, 'head_sha': 'a' * 40},
                     'digest': 'sha256:' + r.digest(self.zipped.read_bytes())}

    def read(self, key=None):
        return r.unseal(self.p, 101, 501, self.zipped, key or self.key, self.output)

    def refused(self, operation):
        with self.assertRaises((r.Refused, FileNotFoundError, FileExistsError, OSError, ValueError)):
            operation()
        self.assertFalse(self.output.exists())
        self.assertFalse(list(self.work.glob('.case5124-*')))

    def test_native_roundtrip_exact_payload_private_and_no_legacy_changes(self):
        before = self.raw.read_bytes()
        self.capture()
        self.read()
        self.assertEqual(self.output.read_bytes(), before)
        self.assertEqual(self.raw.read_bytes(), before)
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)
        summary = r.parse((self.work / r.SUMMARY).read_bytes())
        self.assertEqual(set(summary), {'status', 'recipient_sha256', 'ciphertext_sha256'})
        with zipfile.ZipFile(self.zipped) as z:
            self.assertEqual(z.read('production-case.full.tar.gz.enc'), b'UNCHANGED_SYNTHETIC_LEGACY')

    def test_policy_native_cert_pin_and_source_validation(self):
        self.assertEqual(r.load_policy(), self.p)

    def test_absent_policy_holds_without_metadata_or_production_access(self):
        r.POLICY.unlink()
        self.refused(r.load_policy)
        self.assertEqual(self.calls, [])

    def test_policy_closed_schema_window_cap_hash_and_pin(self):
        variants = [{'unknown': True}, {'schema': True}, {'binding': 'other'}, {'max_captures': 5}, {'max_captures': True},
                    {'not_after': (self.time + dt.timedelta(hours=6)).strftime('%Y-%m-%dT%H:%M:%SZ')},
                    {'recipient_sha256': '0' * 64}, {'expected_backend_sha': 'not-a-sha'},
                    {'source_files_sha256': {r.HELPER: '0' * 64, r.WORKFLOW: '0' * 64}}]
        for delta in variants:
            with self.subTest(delta=next(iter(delta))):
                r.POLICY.write_bytes(r.encoded({**self.p, **delta}))
                self.refused(r.load_policy)

    def test_duplicate_json_and_nonfinite_fail(self):
        for data in (b'{"a":1,"a":2}', b'{"a":NaN}'):
            self.refused(lambda: r.parse(data))

    def test_run_owner_main_attempt_event_success_window_and_source(self):
        for delta in ({'head_branch': 'feature'}, {'actor': {'login': 'other'}}, {'triggering_actor': {'login': 'other'}},
                      {'run_attempt': 2}, {'event': 'pull_request'}, {'conclusion': 'failure'},
                      {'display_title': 'other'}, {'created_at': '2000-01-01T00:00:00Z'},
                      {'repository': {'full_name': 'other/repo'}}, {'path': '.github/workflows/other.yml'}):
            with self.subTest(delta=next(iter(delta))), patch.dict(self.run, delta):
                self.refused(lambda: r.validate_run(self.p, 101))

    def test_capture_first_four_count_failed_and_rerun_dispatches(self):
        self.run['status'] = 'in_progress'
        self.rows = [{**self.run, 'id': i, 'run_attempt': 2, 'conclusion': 'failure'} for i in (97, 98, 99)] + [self.run]
        self.assertEqual(r.validate_run(self.p, 101, True, 'a' * 40)['id'], 101)
        self.rows.insert(0, {**self.run, 'id': 96, 'conclusion': 'cancelled'})
        self.refused(lambda: r.validate_run(self.p, 101, True, 'a' * 40))

    def test_capture_missing_index_expired_or_wrong_source(self):
        self.run['status'] = 'in_progress'
        self.rows = []
        self.refused(lambda: r.validate_run(self.p, 101, True, 'a' * 40))
        self.rows = [self.run]
        self.refused(lambda: r.validate_run(self.p, 101, True, 'c' * 40))
        with patch.object(r, 'now', return_value=r.utc(self.p['not_after']) + dt.timedelta(seconds=1)):
            self.refused(lambda: r.validate_run(self.p, 101, True, 'a' * 40))

    def test_metadata_unavailable_or_pagination_incomplete(self):
        with patch.object(r, 'api', side_effect=r.Refused('unavailable')):
            self.refused(lambda: r.validate_run(self.p, 101))
        with patch.object(r, 'api', return_value={'total_count': 1, 'workflow_runs': [self.run]}):
            self.refused(lambda: r.pages('actions/workflows/production-case-dump.yml/runs', 'workflow_runs'))
        with patch.object(r, 'api', side_effect=[{'total_count': 1, 'workflow_runs': [self.run]},
                                               {'total_count': 2, 'workflow_runs': []}]):
            self.refused(lambda: r.pages('actions/workflows/production-case-dump.yml/runs', 'workflow_runs'))

    def test_runtime_requires_completed_real_deploy_job_and_backend_match(self):
        r.runtime(self.p)
        for delta in ({'conclusion': 'failure'}, {'status': 'in_progress'}):
            with patch.dict(self.run, delta):
                # Explicit deploy metadata in fixture is successful; replace that route.
                original = self.metadata
                def changed(url):
                    result = original(url)
                    return {**result, **delta} if url.endswith('/actions/runs/900') else result
                with patch.object(r, 'public_json', changed):
                    self.refused(lambda: r.runtime(self.p))
        original = self.metadata
        for replacement in ({'total_count': 0, 'jobs': []}, {'total_count': 1, 'jobs': [{'name': 'Deploy to Production', 'conclusion': 'skipped'}]}):
            with patch.object(r, 'public_json', lambda url: replacement if '/jobs?' in url and '&page=1' in url else original(url)):
                self.refused(lambda: r.runtime(self.p))
        with patch.object(r, 'public_json', lambda url: {'schema': 1, 'source': 'github-actions:deploy.yml', 'backend_sha': 'c'*40}
                          if url.endswith('/deployment.json') else original(url)):
            self.refused(lambda: r.runtime(self.p))

    def test_runtime_drift_before_publication_cleans_capsule(self):
        with patch.object(r, 'runtime', side_effect=r.Refused('runtime_mismatch')):
            self.refused(self.capture)
        self.assertFalse((self.work / r.SIDECAR).exists())
        self.assertFalse((self.work / r.SUMMARY).exists())

    def test_read_window_expiring_during_native_decrypt_refuses_and_cleans(self):
        self.capture()
        end = r.utc(self.p['not_after'])
        clock = [end - dt.timedelta(seconds=1)]
        original = r.native
        def native(args):
            result = original(args)
            if '-decrypt' in args:
                clock[0] = end + dt.timedelta(seconds=5)
            return result
        argv = ['reader', 'read', '--run-id', '101', '--artifact-id', '501',
                '--artifact-zip', str(self.zipped), '--private-key', str(self.key), '--output', str(self.output)]
        stdout = io.StringIO()
        with patch.object(r, 'now', side_effect=lambda: clock[0]), patch.object(r, 'native', native), \
                patch.object(sys, 'argv', argv), contextlib.redirect_stdout(stdout):
            with self.assertRaises(SystemExit) as refused:
                r.main()
        self.assertEqual(refused.exception.code, 1)
        self.assertEqual(stdout.getvalue(), 'case5124_reader=HOLD\n')
        self.assertGreater(clock[0], end)
        self.assertFalse(self.output.exists())
        self.assertFalse(list(self.work.glob('.case5124-*')))

    def test_seal_window_expiring_during_runtime_check_refuses_and_cleans(self):
        end = r.utc(self.p['not_after'])
        clock = [end - dt.timedelta(seconds=1)]
        before = self.raw.read_bytes()
        def runtime(_):
            clock[0] = end + dt.timedelta(seconds=5)
        with patch.object(r, 'now', side_effect=lambda: clock[0]), patch.object(r, 'runtime', runtime):
            self.refused(self.capture)
        self.assertEqual(self.raw.read_bytes(), before)
        self.assertFalse((self.work / r.SIDECAR).exists())
        self.assertFalse((self.work / r.SUMMARY).exists())

    def test_scope_and_closed_root_schema_reject_before_ciphertext(self):
        variants = [{'student_ids': [494]}, {'student_ids': [493.0]}, {'student_count': True}, {'prod_head': 'c' * 40}, {'unexpected': 'private'},
                    {'target_classes': [{'class_id': 700, 'student_id': 493, 'campus_id': 16}]},
                    {'class_sessions': [{'id': 1, 'class_id': 999}]},
                    {'schedule_change_metadata': [{'id': 1, 'class_id': 999}]},
                    {'schedules': [{'class_id': None, 'student_id': 493, 'campus_id': 16}]},
                    {'verification': {'rows_complete': False}}]
        for delta in variants:
            with self.subTest(delta=next(iter(delta))):
                self.raw.write_bytes(r.encoded({**self.obj, **delta}))
                self.refused(self.capture)
                self.assertFalse((self.work / r.SIDECAR).exists())

    def test_wrong_key_and_tag_tamper_remove_unverified_plaintext(self):
        self.capture()
        self.refused(lambda: self.read(self.wrong))
        cipher = self.work / r.SIDECAR
        data = bytearray(cipher.read_bytes())
        data[-1] ^= 1
        cipher.write_bytes(data)
        summary = r.parse((self.work / r.SUMMARY).read_bytes())
        summary['ciphertext_sha256'] = r.digest(data)
        (self.work / r.SUMMARY).write_bytes(r.encoded(summary))
        self.package()  # Even a trusted artifact hash cannot waive native AEAD.
        self.refused(self.read)

    def test_malformed_and_algorithm_downgrade_rejected(self):
        self.capture()
        cipher = self.work / r.SIDECAR
        for malformed in (b'not DER', cipher.read_bytes()[:-25]):
            cipher.write_bytes(malformed)
            self.refused(lambda: r.profile(cipher))
        archive = self.work / 'fake.bin'
        archive.write_bytes(b'SYNTHETIC')
        for opts in (['-aes-256-cbc', '-keyopt', 'rsa_oaep_md:sha256', '-keyopt', 'rsa_mgf1_md:sha256'], ['-aes-256-gcm']):
            r.native(['cms', '-encrypt', '-binary', '-in', archive, '-outform', 'DER', '-out', cipher,
                      '-recip', self.cert, '-keyopt', 'rsa_padding_mode:oaep', *opts])
            self.refused(lambda: r.profile(cipher))

    def test_artifact_digest_id_run_name_and_expiry_required(self):
        self.capture()
        for delta in ({'digest': None}, {'digest': 'sha256:' + '0'*64}, {'id': 999}, {'expired': True},
                      {'name': 'other'}, {'workflow_run': {'id': 999}}):
            with self.subTest(delta=next(iter(delta))), patch.dict(self.meta, delta):
                self.refused(self.read)

    def test_archive_duplicate_or_traversal_is_not_extracted(self):
        self.capture()
        for bad in ('../escape', r.SIDECAR):
            self.package()
            with zipfile.ZipFile(self.zipped, 'a') as z:
                import warnings
                with warnings.catch_warnings():
                    warnings.simplefilter('ignore')
                    z.writestr(bad, b'SYNTHETIC')
            self.meta['digest'] = 'sha256:' + r.digest(self.zipped.read_bytes())
            self.refused(self.read)
        self.assertFalse((self.work.parent / 'escape').exists())

    def test_authenticated_invalid_capsules_never_publish(self):
        self.capture()
        capture = {'schema': 1, 'binding': r.BINDING, 'run_id': 101, 'source_sha': 'a' * 40,
                   'backend_sha': 'b' * 40, 'captured_at': self.run['updated_at'],
                   'payload_sha256': r.digest(self.raw.read_bytes())}
        variants = [('scope', r.encoded({**self.obj, 'student_ids': [494]}), capture, 'production-case.json'),
                    ('provenance', self.raw.read_bytes(), {**capture, 'run_id': 999}, 'production-case.json'),
                    ('path', self.raw.read_bytes(), capture, '../escape.json'),
                    ('json', b'[]', capture, 'production-case.json'),
                    ('expansion', b'X' * (r.LIMIT + 65537), capture, 'production-case.json')]
        for label, data, provenance, member in variants:
            with self.subTest(kind=label):
                provenance = {**provenance, 'payload_sha256': r.digest(data)}
                archive = self.work / 'synthetic-capsule.tar.gz'
                with tarfile.open(archive, 'w:gz') as tar:
                    for name, content in [(member, data), ('capture.json', r.encoded(provenance))]:
                        info = tarfile.TarInfo(name)
                        info.size, info.mode = len(content), 0o600
                        tar.addfile(info, io.BytesIO(content))
                cipher = self.work / r.SIDECAR
                r.native(['cms', '-encrypt', '-binary', '-aes-256-gcm', '-in', archive, '-outform', 'DER',
                          '-out', cipher, '-recip', self.cert, '-keyopt', 'rsa_padding_mode:oaep',
                          '-keyopt', 'rsa_oaep_md:sha256', '-keyopt', 'rsa_mgf1_md:sha256'])
                (self.work / r.SUMMARY).write_bytes(r.encoded({'status': 'sealed', 'recipient_sha256': self.p['recipient_sha256'],
                                                             'ciphertext_sha256': r.digest(cipher.read_bytes())}))
                self.package()
                self.refused(self.read)
                archive.unlink()

    def test_failed_native_authentication_staging_is_removed(self):
        self.capture()
        cipher = self.work / r.SIDECAR
        data = bytearray(cipher.read_bytes())
        data[-1] ^= 1
        cipher.write_bytes(data)
        (self.work / r.SUMMARY).write_bytes(r.encoded({'status': 'sealed', 'recipient_sha256': self.p['recipient_sha256'],
                                                     'ciphertext_sha256': r.digest(data)}))
        self.package()
        observed = []
        original = r.native
        def native(args):
            try:
                return original(args)
            except r.Refused:
                if '-decrypt' in args:
                    stage = Path(args[args.index('-out') + 1])
                    observed.append((stage, stage.stat().st_size))
                raise
        with patch.object(r, 'native', native):
            self.refused(self.read)
        self.assertEqual(len(observed), 1)
        self.assertFalse(observed[0][0].exists())

    def test_atomic_publication_preserves_existing_inode_and_permissions(self):
        dest = self.work / 'atomic.json'
        r.atomic(dest, b'SYNTHETIC')
        self.assertEqual(dest.read_bytes(), b'SYNTHETIC')
        with self.assertRaises(FileExistsError):
            r.atomic(dest, b'REPLACEMENT')
        self.assertEqual(dest.read_bytes(), b'SYNTHETIC')
        self.assertEqual(dest.stat().st_mode & 0o777, 0o600)
        self.assertFalse(list(self.work.glob('.case5124-*')))

    def test_private_files_symlinks_and_no_overwrite(self):
        self.capture()
        self.key.chmod(0o640)
        try:
            self.refused(self.read)
        finally:
            self.key.chmod(0o600)
        linked = self.work / 'key-link'
        linked.symlink_to(self.key)
        self.refused(lambda: self.read(linked))
        self.output.write_bytes(b'EXISTING')
        with self.assertRaises(FileExistsError):
            self.read()
        self.assertEqual(self.output.read_bytes(), b'EXISTING')
        self.assertFalse(list(self.work.glob('.case5124-*')))

    def test_workflow_legacy_cipher_cleanup_and_default_contract(self):
        text = (ROOT / r.WORKFLOW).read_text()
        encrypt = text.split('- name: Encrypt case output (log shows status only)', 1)[1].split('\n      - name:', 1)[0]
        cleanup = text.split('- name: Shred plaintext case output', 1)[1].split('\n      - name:', 1)[0]
        self.assertIn('if: always()', encrypt)
        self.assertIn('if: always()', cleanup)
        self.assertIn('openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -pass env:DUMP_ARTIFACT_KEY', encrypt)
        self.assertIn('find raw -type f -exec shred -u {} +', cleanup)
        self.assertIn('permissions:\n  contents: read\n\n', text)
        self.assertIn('group: production-case-dump-${{ inputs.case_key }}\n  cancel-in-progress: false', text)
        inputs = text.split('reader_profile:\n', 1)[1].split('      expected_backend_sha:', 1)[0]
        self.assertIn('default: legacy', inputs)
        self.assertIn('options:\n          - legacy\n          - case5124\n', inputs)
        self.assertLess(text.index('private-reader.py preflight'), text.index('Configure pinned production SSH trust'))
        self.assertLess(text.index('openssl enc -aes-256-cbc'), text.index('private-reader.py seal'))
        self.assertLess(text.index('private-reader.py seal'), text.index('Shred plaintext case output'))


if __name__ == '__main__':
    unittest.main()
