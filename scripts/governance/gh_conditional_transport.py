"""Unactivated GET-only transport for #4385 metadata; preserve configured gh identity.

No retries, cache storage, credential selection, environment mutation or approval.
Only legacy gh's observed HTTP304/exit1 is a successful protocol outcome. Other
nonzero exits, denial statuses, malformed responses and budget exhaustion Hold.
"""
import json
import re
import subprocess
import time
from urllib.parse import parse_qs, urlsplit

from scripts.governance.conditional_page import valid_etag

API_VERSION = '2022-11-28'
SAFE_HEADERS = {'etag', 'date', 'age', 'x-github-api-version-selected', 'x-ratelimit-limit',
                'x-ratelimit-remaining', 'x-ratelimit-used', 'x-ratelimit-reset', 'x-ratelimit-resource'}


class MetadataHold(RuntimeError):
    def __init__(self, reason, status=None):
        super().__init__(reason)  # Fixed enum; never body/stderr/credentials.
        self.reason, self.status = reason, status


def decode_response(returncode, stdout, *, expected_api_version=API_VERSION):
    if not isinstance(stdout, bytes) or len(stdout) > 1024 * 1024:
        raise MetadataHold('response_size_or_type_unknown')
    marker = b'\r\n\r\n' if b'\r\n\r\n' in stdout else b'\n\n'
    if marker not in stdout:
        raise MetadataHold('response_headers_unavailable')
    head, body = stdout.split(marker, 1)
    try:
        lines = head.decode('ascii').splitlines()
    except UnicodeDecodeError:
        raise MetadataHold('response_headers_malformed') from None
    match = re.fullmatch(r'HTTP/\S+\s+(\d{3})(?:\s+[^\r\n]*)?', lines[0]) if lines else None
    if not match:
        raise MetadataHold('response_status_unknown')
    status = int(match.group(1))
    if status not in {200, 304}:
        raise MetadataHold('http_rejected_no_retry', status)
    headers = {}
    for line in lines[1:]:
        if ':' not in line or line[:1].isspace():
            raise MetadataHold('response_headers_malformed', status)
        key, value = line.split(':', 1)
        key, value = key.lower(), value.strip()
        if key in SAFE_HEADERS:
            if key in headers:
                raise MetadataHold('response_headers_ambiguous', status)
            headers[key] = value
    if headers.get('x-github-api-version-selected') != expected_api_version:
        raise MetadataHold('response_api_version_unknown', status)
    if type(returncode) is not int or (status == 200 and returncode != 0) or (status == 304 and returncode not in {0, 1}):
        raise MetadataHold('cli_failed', status)
    etag = headers.get('etag')
    if status == 304:
        if body or not valid_etag(etag):
            raise MetadataHold('conditional_response_incomplete', status)
        data = None
    else:
        try:
            def invalid_constant(value):
                raise ValueError('nonstandard_json')
            data = json.loads(body, parse_constant=invalid_constant)
        except (ValueError, UnicodeDecodeError):
            raise MetadataHold('response_json_incomplete', status) from None
        if not isinstance(data, dict):
            raise MetadataHold('response_json_not_object', status)
    return {'status': status, 'etag': etag, 'data': data, 'headers': headers,
            'legacy_304_exit1': status == 304 and returncode == 1, 'body_bytes': len(body)}


def validate_path(repo, path):
    if not isinstance(repo, str) or not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repo) or not isinstance(path, str):
        raise MetadataHold('metadata_target_invalid')
    parsed = urlsplit(path)
    if parsed.scheme or parsed.netloc or parsed.fragment:
        raise MetadataHold('metadata_target_invalid')
    prefix = '/repos/' + repo + '/actions/'
    if not parsed.path.startswith(prefix):
        raise MetadataHold('metadata_target_invalid')
    suffix = parsed.path[len(prefix):]
    query = parse_qs(parsed.query, keep_blank_values=True, strict_parsing=True)
    if any(len(values) != 1 for values in query.values()):
        raise MetadataHold('metadata_query_invalid')
    if not set(query).issubset({'per_page', 'page', 'branch', 'status'}):
        raise MetadataHold('metadata_query_invalid')
    for key, maximum in [('per_page', 100), ('page', None)]:
        if key in query and (not re.fullmatch('[1-9][0-9]*', query[key][0]) or
                             (maximum is not None and int(query[key][0]) > maximum)):
            raise MetadataHold('metadata_query_invalid')
    if re.fullmatch(r'runs/[1-9][0-9]*/attempts/[1-9][0-9]*/jobs', suffix):
        if set(query) - {'per_page', 'page'}:
            raise MetadataHold('metadata_query_invalid')
    elif suffix == 'workflows/deploy.yml/runs':
        if set(query) - {'per_page', 'page'}:  # Never allow history pruning.
            raise MetadataHold('filtered_history_not_allowed')
    elif suffix == 'workflows/ci.yml/runs':
        if query.get('branch') != ['main'] or query.get('status') != ['completed']:
            raise MetadataHold('metadata_query_invalid')
    else:
        raise MetadataHold('metadata_target_invalid')


class GhMetadataClient:
    def __init__(self, *, repo, max_requests, deadline_seconds=60, timeout_seconds=15,
                 runner=subprocess.run, clock=time.monotonic):
        if any(type(value) is not int or value <= 0 for value in [max_requests, deadline_seconds, timeout_seconds]):
            raise MetadataHold('request_budget_invalid')
        self.repo, self.max_requests = repo, max_requests
        self.runner, self.clock, self.timeout = runner, clock, timeout_seconds
        self.deadline = clock() + deadline_seconds
        self.records = []

    def fetch(self, path, validator=None):
        validate_path(self.repo, path)
        if validator is not None and not valid_etag(validator):
            raise MetadataHold('request_validator_invalid')
        remaining = self.deadline - self.clock()
        if len(self.records) >= self.max_requests or remaining <= 0:
            raise MetadataHold('request_budget_exhausted')
        args = ['gh', 'api', '--include', '--method', 'GET', '-H', 'Accept: application/vnd.github+json',
                '-H', 'X-GitHub-Api-Version: ' + API_VERSION]
        if validator is not None:
            args += ['-H', 'If-None-Match: ' + validator]
        args.append(path)
        record = {'path': path, 'conditional': validator is not None}
        self.records.append(record)
        started = self.clock()
        try:
            proc = self.runner(args, capture_output=True, timeout=min(remaining, self.timeout), check=False)
            response = decode_response(proc.returncode, proc.stdout)
        except subprocess.TimeoutExpired:
            record['failure'] = 'timeout_no_retry'
            raise MetadataHold('timeout_no_retry') from None
        except MetadataHold as error:
            record.update(failure=error.reason, status=error.status)
            raise
        record.update(status=response['status'], headers=response['headers'], body_bytes=response['body_bytes'],
                      legacy_304_exit1=response['legacy_304_exit1'], elapsed_seconds=round(self.clock() - started, 6))
        if self.clock() > self.deadline:
            raise MetadataHold('request_deadline_exceeded')
        return response['status'], response['etag'], response['data']
