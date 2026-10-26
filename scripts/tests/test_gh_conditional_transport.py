"""Recorded protocol shape + synthetic payloads; no network, credentials or full-suite reruns."""
import copy
import json
from pathlib import Path
import subprocess
import unittest

from scripts.governance.conditional_page import digest, read_page, weak_etag_equal
from scripts.governance.gh_conditional_transport import API_VERSION, GhMetadataClient, MetadataHold, core_rate_evidence, decode_response, validate_path

TRACE = Path(__file__).resolve().parent / 'fixtures/github-conditional-jobs-protocol.json'
PATH = '/repos/o/r/actions/runs/1/attempts/1/jobs?per_page=100&page=1'
OPAQUE = 'a' * 64
STRONG, WEAK = '"' + OPAQUE + '"', 'W/"' + OPAQUE + '"'


def wire(status, tag=STRONG, body=None, version=API_VERSION, newline=b'\r\n'):
    head = [f'HTTP/2.0 {status}', 'Etag: ' + tag, 'X-Github-Api-Version-Selected: ' + version]
    payload = b'' if status == 304 and body is None else json.dumps(body).encode()
    return newline.join(line.encode() for line in head) + newline * 2 + payload


class ProtocolTest(unittest.TestCase):
    def test_recorded_real_weak200_and_strong304_legacy_exit1(self):
        record = json.loads(TRACE.read_text())
        first, second = record['requests']
        self.assertEqual((first['status'], second['status'], second['returncode']), (200, 304, 1))
        self.assertEqual(first['headers']['x-ratelimit-remaining'], second['headers']['x-ratelimit-remaining'])
        self.assertTrue(weak_etag_equal(first['headers']['etag'], second['headers']['etag']))
        raw = wire(304, second['headers']['etag'])
        result = decode_response(second['returncode'], raw)
        self.assertTrue(result['legacy_304_exit1'])
        self.assertIsNone(result['data'])

    def test_304_exit0_is_also_supported(self):
        self.assertEqual(decode_response(0, wire(304))['status'], 304)

    def test_nonzero_200_and_unknown_exit304_hold(self):
        for code, raw in [(1, wire(200, body={})), (2, wire(304)), (True, wire(304))]:
            with self.subTest(code=code), self.assertRaises(MetadataHold): decode_response(code, raw)

    def test_denials_and_server_failure_never_become_cache_success(self):
        for status in [401, 403, 404, 429, 500, 502]:
            with self.subTest(status=status), self.assertRaises(MetadataHold) as caught:
                decode_response(1, wire(status, body={}))
            self.assertEqual(caught.exception.status, status)
            self.assertEqual(caught.exception.reason, 'http_rejected_no_retry')

    def test_bad_304_body_version_validator_or_duplicate_header_hold(self):
        cases = [wire(304, body={}), wire(304, tag='missing'), wire(304, version='unexpected'),
                 wire(304).replace(b'Etag:', b'Etag: "' + b'b' * 64 + b'"\r\nEtag:', 1)]
        for raw in cases:
            with self.subTest(raw=raw[:90]), self.assertRaises(MetadataHold): decode_response(1, raw)

    def test_missing_header_truncated_json_nonobject_or_nan_hold(self):
        cases = [b'{}', wire(200, body=[]), wire(200, body={})[:-1],
                 wire(200, body={}).replace(b'{}', b'{"x":NaN}')]
        for raw in cases:
            with self.subTest(raw=raw[:90]), self.assertRaises(MetadataHold): decode_response(0, raw)

    def test_lf_gh_output_is_supported_without_printing_error_or_body(self):
        self.assertEqual(decode_response(0, wire(200, body={'jobs': []}, newline=b'\n'))['data'], {'jobs': []})


class IntegrationTest(unittest.TestCase):
    def setUp(self):
        self.identity = {'repo': 'o/r', 'path': PATH, 'api_version': API_VERSION,
                         'authz_scope': 'synthetic-same-context', 'policy_sha': 'b' * 40}
        self.body = {'total_count': 1, 'jobs': [{'id': 1, 'run_id': 1, 'run_attempt': 1}]}

    def test_same_transport_200_then_weak_equivalent_304_exit1(self):
        calls = []
        responses = [(0, wire(200, WEAK, self.body)), (1, wire(304, STRONG))]
        def runner(args, **kwargs):
            calls.append((args, kwargs))
            code, raw = responses.pop(0)
            return subprocess.CompletedProcess(args, code, stdout=raw, stderr=b'synthetic legacy304 marker')
        client = GhMetadataClient(repo='o/r', max_requests=2, runner=runner)
        first = read_page(request_identity=self.identity, fetch=lambda tag: client.fetch(PATH, tag))
        second = read_page(request_identity=self.identity, cached=first['cache'],
                           trusted_cache_digest=digest(first['cache']), fetch=lambda tag: client.fetch(PATH, tag))
        self.assertEqual(second['data'], self.body)
        self.assertEqual(len(calls), 2)
        self.assertIn('If-None-Match: ' + WEAK, calls[1][0])
        self.assertTrue(client.records[1]['legacy_304_exit1'])
        self.assertTrue(all('env' not in kwargs for _, kwargs in calls), 'preserve inherited identity/proxy')
        self.assertTrue(all(args[args.index('--method') + 1] == 'GET' for args, _ in calls))

    def test_different_opaque_case_or_tag_still_rejected(self):
        for response_tag in ['"' + 'c' * 64 + '"', '"' + 'A' * 64 + '"']:
            self.assertFalse(weak_etag_equal(WEAK, response_tag))
            cache = {'identity': self.identity, 'etag': WEAK, 'data': self.body}
            with self.assertRaises(ValueError):
                read_page(request_identity=self.identity, cached=cache, trusted_cache_digest=digest(cache),
                          fetch=lambda tag: (304, response_tag, None))

    def test_private_cache_snapshot_remains_protected_during_fetch(self):
        cache = {'identity': copy.deepcopy(self.identity), 'etag': WEAK, 'data': copy.deepcopy(self.body)}
        expected = digest(cache)
        def fetch(tag):
            cache['data']['jobs'][0]['id'] = 99
            self.identity['authz_scope'] = 'changed'
            return 304, STRONG, None
        result = read_page(request_identity=self.identity, cached=cache, trusted_cache_digest=expected, fetch=fetch)
        self.assertEqual(result['data']['jobs'][0]['id'], 1)
        self.assertEqual(digest(result['cache']), expected)

    def test_budget_and_deadline_stop_before_request(self):
        calls = []
        def runner(args, **kwargs):
            calls.append(args)
            return subprocess.CompletedProcess(args, 0, wire(200, body=self.body), b'')
        client = GhMetadataClient(repo='o/r', max_requests=1, runner=runner)
        client.fetch(PATH)
        with self.assertRaises(MetadataHold): client.fetch(PATH)
        self.assertEqual(len(calls), 1)
        ticks = iter([0, 61])
        client = GhMetadataClient(repo='o/r', max_requests=1, runner=runner, clock=lambda: next(ticks))
        with self.assertRaises(MetadataHold): client.fetch(PATH)
        self.assertEqual(len(calls), 1)

    def test_timeout_or_denial_not_retried_or_logged_as_raw_stderr(self):
        for failure in ['timeout', '403']:
            calls = []
            def runner(args, **kwargs):
                calls.append(args)
                if failure == 'timeout': raise subprocess.TimeoutExpired(args, 1)
                return subprocess.CompletedProcess(args, 1, wire(403, body={}), b'FAKE_SECRET_MUST_NOT_LEAK')
            client = GhMetadataClient(repo='o/r', max_requests=2, runner=runner)
            with self.assertRaises(MetadataHold) as caught: client.fetch(PATH)
            self.assertEqual(len(calls), 1)
            self.assertNotIn('FAKE_SECRET', str(caught.exception) + json.dumps(client.records))


class ScopeTest(unittest.TestCase):
    def test_core_rate_limit_is_observation_and_cannot_coerce_unknown_values(self):
        known = {'limit': 1000, 'remaining': 999, 'used': 1, 'reset': 1792952373}
        self.assertEqual(core_rate_evidence(known), known)
        for key, value in [('limit', True), ('limit', 0), ('remaining', '999'), ('remaining', 1001), ('reset', None)]:
            with self.subTest(key=key, value=value), self.assertRaises(MetadataHold):
                core_rate_evidence({**known, key: value})

    def test_runner_observation_keeps_existing_permissions_and_manual_pr_guard(self):
        ci = (Path(__file__).resolve().parents[2] / '.github/workflows/ci.yml').read_text()
        self.assertIn('permissions:\n  contents: read\n  pull-requests: read\n', ci)
        step = ci.split('- name: Record actual runner core rate limit', 1)[1].split('- name:', 1)[0]
        self.assertIn("github.event_name == 'workflow_dispatch' && inputs.pr_number != ''", step)
        self.assertIn('gh api /rate_limit', step)
        self.assertNotIn('permissions:', step)
        self.assertNotIn('environment:', step)

    def test_other_hosts_repos_writes_encoded_paths_or_history_filters_hold(self):
        cases = ['https://api.github.com' + PATH, PATH.replace('/o/r/', '/other/repo/'),
                 '/repos/o/r/actions/runs/1/rerun', PATH + '&page=2', PATH + '&filter=latest',
                 '/repos/o/r/actions/workflows/deploy.yml/runs?status=completed',
                 '/repos/o/r/actions/workflows/deploy.yml/runs?created=2026',
                 '/repos/o/r/actions/runs/%31/attempts/1/jobs?per_page=100&page=1']
        for path in cases:
            with self.subTest(path=path), self.assertRaises((MetadataHold, ValueError)): validate_path('o/r', path)

    def test_complete_inventory_and_explicit_attempt_paths_allowed(self):
        for path in [PATH, '/repos/o/r/actions/workflows/deploy.yml/runs?per_page=100&page=36',
                     '/repos/o/r/actions/workflows/ci.yml/runs?branch=main&status=completed&per_page=20']:
            validate_path('o/r', path)



if __name__ == '__main__':
    unittest.main()
