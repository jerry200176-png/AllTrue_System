"""Permanent write-back metadata. Callers must hold the shared workflow mutex.

No production command is executed here. Reserve before attempting a write; an
uncertain result remains visible and cannot be automatically replayed.
"""
import hashlib
import json
import re
import subprocess

MARKER = '<!-- alltrue-writeback-cursor-v1 -->'
SCHEMA = 'alltrue-writeback-cursor-v1'
REPOSITORY = 'jerry200176-png/AllTrue_System'
ISSUE = 3998
COMMENT = 6143926615
MAX_UNKNOWN = 100


def digest(entries):
    return hashlib.sha256(json.dumps(entries, sort_keys=True, ensure_ascii=False,
                                     separators=(',', ':')).encode()).hexdigest()


def validate(state, snapshot):
    ids = [entry.get('request_id') for entry in snapshot if isinstance(entry, dict)]
    if (len(ids) != len(snapshot) or any(not isinstance(rid, str) or not rid for rid in ids)
            or len(set(ids)) != len(ids)):
        raise ValueError('invalid request snapshot')
    if not isinstance(state, dict) or state.get('schema') != SCHEMA:
        raise ValueError('invalid cursor schema')
    count = state.get('prefix')
    if type(count) is not int or not 0 <= count <= len(snapshot):
        raise ValueError('invalid cursor prefix')
    if state.get('prefix_hash') != digest(snapshot[:count]):
        raise ValueError('cursor content changed')
    expected_id = snapshot[count - 1]['request_id'] if count else None
    if state.get('last_request_id') != expected_id:
        raise ValueError('cursor request ID changed')
    sha = state.get('snapshot_sha')
    if count and (not isinstance(sha, str) or not re.fullmatch('[0-9a-f]{40}', sha)):
        raise ValueError('invalid frozen snapshot')
    if not count and sha is not None:
        raise ValueError('invalid bootstrap snapshot')
    for key in ('recent_outcomes', 'unknown'):
        rows = state.get(key)
        if not isinstance(rows, list) or len(rows) > (20 if key == 'recent_outcomes' else MAX_UNKNOWN):
            raise ValueError('invalid outcome capacity')
        seen = set()
        for row in rows:
            if not isinstance(row, dict) or set(row) != {'id', 'index', 'request_hash', 'status'}:
                raise ValueError('invalid outcome record')
            index = row['index']
            if type(index) is not int or not 0 <= index < count:
                raise ValueError('invalid outcome index')
            if row['id'] != snapshot[index]['request_id'] or row['request_hash'] != digest(snapshot[index]):
                raise ValueError('outcome content changed')
            if row['id'] in seen or row['status'] != ('unknown' if key == 'unknown' else 'succeeded'):
                raise ValueError('invalid outcome status')
            seen.add(row['id'])
    if {r['id'] for r in state['unknown']} & {r['id'] for r in state['recent_outcomes']}:
        raise ValueError('contradictory outcomes')
    return state


def reserve(state, snapshot, sha):
    validate(state, snapshot)
    if not re.fullmatch('[0-9a-f]{40}', sha) or len(state['unknown']) >= MAX_UNKNOWN:
        raise ValueError('cannot reserve cursor')
    count = state['prefix']
    if count == len(snapshot):
        raise ValueError('no remaining request')
    result = json.loads(json.dumps(state))
    request = snapshot[count]
    if not isinstance(request.get('request_id'), str) or not request['request_id']:
        raise ValueError('invalid request ID')
    result.update(prefix=count + 1, prefix_hash=digest(snapshot[:count + 1]),
                  snapshot_sha=sha, last_request_id=request['request_id'])
    result['unknown'].append(dict(id=request['request_id'], index=count,
                                 request_hash=digest(request), status='unknown'))
    return validate(result, snapshot)


def succeed(state, snapshot, request_id):
    validate(state, snapshot)
    result = json.loads(json.dumps(state))
    rows = [r for r in result['unknown'] if r['id'] == request_id]
    if len(rows) != 1:
        raise ValueError('request has no reservation')
    result['unknown'].remove(rows[0])
    result['recent_outcomes'] = (result['recent_outcomes'] + [dict(rows[0], status='succeeded')])[-20:]
    return validate(result, snapshot)


class CommentStore:
    def __init__(self, runner=subprocess.run):
        self.runner = runner

    def api(self, method, payload=None):
        args = ['gh', 'api', '--method', method,
                f'repos/{REPOSITORY}/issues/comments/{COMMENT}']
        if payload is not None:
            args += ['--input', '-']
        response = self.runner(args, input=json.dumps(payload) if payload else None,
                               text=True, capture_output=True, timeout=15, check=True)
        data = json.loads(response.stdout)
        if (data.get('id') != COMMENT or data.get('issue_url') !=
                f'https://api.github.com/repos/{REPOSITORY}/issues/{ISSUE}'):
            raise ValueError('wrong pinned cursor comment')
        return data

    def read(self):
        body = self.api('GET').get('body', '')
        if not body.startswith(MARKER + '\n'):
            raise ValueError('missing cursor marker')
        return json.loads(body[len(MARKER) + 1:])

    def write(self, state):
        body = MARKER + '\n' + json.dumps(state, sort_keys=True, separators=(',', ':'))
        if len(body.encode()) > 60000:
            raise ValueError('cursor comment too large')
        if self.api('PATCH', {'body': body}).get('body') != body:
            raise ValueError('cursor persistence unconfirmed')
