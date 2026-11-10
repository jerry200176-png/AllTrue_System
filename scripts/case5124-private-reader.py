#!/usr/bin/env python3
"""Fixed, opt-in encrypted read custody. No production writes or key discovery.

Native OpenSSL supplies CMS/AEAD; the standard library supplies closed schemas,
public GitHub provenance, bounded archives and private atomic publication.
The fixed approval files are deliberately not CLI parameters; proposed source
does not grant main trust or approval to merge/activate its recipient.
"""
import argparse
import base64
import datetime as dt
import hashlib
import gzip
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tarfile
import tempfile
import urllib.parse
import urllib.request
import zipfile
import secrets

ROOT = Path(__file__).resolve().parents[1]
REPO = 'jerry200176-png/AllTrue_System'
OWNER = 'jerry200176-png'
BINDING = 'case5124-private-reader-v1'
WORKFLOW = '.github/workflows/production-case-dump.yml'
HELPER = 'scripts/case5124-private-reader.py'
POLICY = ROOT / 'operations/closeout/case5124-reader.policy.json'
CERT = ROOT / 'operations/closeout/case5124-reader.cert.crt'
LIMIT = 16 * 1024 * 1024
SIDECAR = 'case5124.cms.der'
SUMMARY = 'case5124.public.json'
ARTIFACT = 'production-case-transfer_capacity_student'


class Refused(Exception):
    """Only fixed machine tokens may leave the private boundary."""


def need(value, token):
    if not value:
        raise Refused(token)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def utc(value):
    need(isinstance(value, str) and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', value), 'time_schema')
    return dt.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=dt.timezone.utc)


def now():
    return dt.datetime.now(dt.timezone.utc)


def pairs(items):
    result = {}
    for key, value in items:
        need(key not in result, 'duplicate_json_key')
        result[key] = value
    return result


def parse(data):
    try:
        return json.loads(data, object_pairs_hook=pairs,
                          parse_constant=lambda _: need(False, 'nonfinite_json'))
    except Refused:
        raise
    except (ValueError, UnicodeError):
        raise Refused('json_schema') from None


def encoded(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False) + '\n').encode()


def file_bytes(path, private=False, cap=LIMIT):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        st = os.fstat(stream.fileno())
        need(stat.S_ISREG(st.st_mode) and st.st_nlink == 1 and st.st_size <= cap, 'file_boundary')
        if private:
            need(st.st_uid == os.getuid() and stat.S_IMODE(st.st_mode) == 0o600, 'private_file_required')
        data = stream.read(cap + 1)
        need(len(data) <= cap, 'file_cap')
        return data


def native(args):
    result = subprocess.run(['openssl', *map(str, args)], stdin=subprocess.DEVNULL,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=30, env={**os.environ, 'LC_ALL': 'C'})
    need(result.returncode == 0, 'native_crypto_failed')
    return result.stdout


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise Refused('metadata_redirect')


def public_json(url):
    # No token, arbitrary host, redirects or retries; failure is HOLD.
    parsed = urllib.parse.urlsplit(url)
    need(parsed.scheme == 'https' and parsed.netloc in {'api.github.com', 'daan.lifenet.com.tw'}
         and not parsed.username and not parsed.fragment, 'metadata_origin')
    if parsed.netloc == 'daan.lifenet.com.tw':
        need(parsed.path == '/deployment.json' and not parsed.query, 'metadata_path')
    else:
        need(parsed.path.startswith('/repos/' + REPO + '/'), 'metadata_path')
    req = urllib.request.Request(url, headers={'Accept': 'application/vnd.github+json',
                                              'User-Agent': 'alltrue-case5124-private-reader'})
    with urllib.request.build_opener(NoRedirect).open(req, timeout=15) as response:
        need(response.status == 200, 'metadata_status')
        data = response.read(LIMIT + 1)
        need(len(data) <= LIMIT, 'metadata_cap')
        return parse(data)


def api(path):
    return public_json('https://api.github.com/repos/' + REPO + '/' + path)


def pages(path, key):
    rows = []
    total = None
    for page in range(1, 21):
        value = api(path + ('&' if '?' in path else '?') + f'per_page=100&page={page}')
        need(isinstance(value, dict) and isinstance(value.get(key), list)
             and type(value.get('total_count')) is int and 0 <= value['total_count'] <= 1900, 'metadata_schema')
        if total is None:
            total = value['total_count']
        need(value['total_count'] == total, 'metadata_changed_during_pagination')
        batch = value[key]
        if not batch:
            need(len(rows) == total, 'metadata_pagination_incomplete')
            return rows
        rows.extend(batch)
    raise Refused('metadata_pagination_incomplete')


def load_policy():
    p = parse(file_bytes(POLICY, cap=16384))
    keys = {'schema', 'binding', 'recipient_sha256', 'not_before', 'not_after', 'max_captures',
            'expected_backend_sha', 'deploy_run_id', 'source_files_sha256'}
    need(isinstance(p, dict) and set(p) == keys and type(p['schema']) is int and p['schema'] == 1 and p['binding'] == BINDING,
         'policy_schema')
    need(type(p['max_captures']) is int and 1 <= p['max_captures'] <= 4
         and type(p['deploy_run_id']) is int and p['deploy_run_id'] > 0, 'policy_bounds')
    need(isinstance(p['expected_backend_sha'], str) and re.fullmatch('[a-f0-9]{40}', p['expected_backend_sha'])
         and isinstance(p['recipient_sha256'], str) and re.fullmatch('[a-f0-9]{64}', p['recipient_sha256']), 'policy_hash')
    start, end = utc(p['not_before']), utc(p['not_after'])
    need(dt.timedelta(0) < end - start <= dt.timedelta(hours=5), 'policy_window')
    hashes = p['source_files_sha256']
    need(isinstance(hashes, dict) and set(hashes) == {HELPER, WORKFLOW}
         and all(isinstance(h, str) and re.fullmatch('[a-f0-9]{64}', h) for h in hashes.values()), 'policy_source')
    need(all(digest(file_bytes(ROOT / name)) == h for name, h in hashes.items()), 'local_source_mismatch')
    file_bytes(CERT, cap=16384)  # No symlink/special-file certificate path.
    need(digest(native(['x509', '-in', CERT, '-outform', 'DER'])) == p['recipient_sha256'], 'recipient_pin')
    # Pinned self-signed certificate: native verification checks validity dates.
    native(['verify', '-CAfile', CERT, CERT])
    remaining = max(0, int((end - now()).total_seconds()))
    native(['x509', '-in', CERT, '-checkend', str(remaining), '-noout'])
    pub = native(['x509', '-in', CERT, '-pubkey', '-noout'])
    with tempfile.TemporaryDirectory(prefix='case5124-cert-') as temp:
        key = Path(temp) / 'public.pem'
        key.write_bytes(pub)
        text = native(['pkey', '-pubin', '-in', key, '-text', '-noout']).decode('ascii')
    need(re.search(r'Public-Key: \((3072|4096) bit\)', text) and 'Modulus:' in text, 'recipient_rsa_profile')
    return p


def validate_run(p, run_id, capture=False, expected_sha=None):
    need(type(run_id) is int and run_id > 0, 'run_id_schema')
    run = api(f'actions/runs/{run_id}')
    need(run.get('id') == run_id and run.get('repository', {}).get('full_name') == REPO
         and run.get('path') == WORKFLOW and run.get('head_branch') == 'main'
         and run.get('event') == 'workflow_dispatch' and run.get('display_title') == BINDING
         and run.get('actor', {}).get('login') == OWNER and run.get('triggering_actor', {}).get('login') == OWNER
         and run.get('run_attempt') == 1, 'run_provenance')
    sha = run.get('head_sha')
    need(isinstance(sha, str) and re.fullmatch('[a-f0-9]{40}', sha), 'run_source')
    need(utc(p['not_before']) <= utc(run['created_at']) <= utc(p['not_after']), 'run_window')
    if capture:
        need(utc(p['not_before']) <= now() <= utc(p['not_after']) and sha == expected_sha
             and run.get('status') == 'in_progress', 'capture_window_or_source')
        query = urllib.parse.urlencode({'event': 'workflow_dispatch', 'branch': 'main',
                                        'created': p['not_before'] + '..' + p['not_after']})
        runs = pages('actions/workflows/production-case-dump.yml/runs?' + query, 'workflow_runs')
        eligible = {r['id']: r for r in runs if r.get('display_title') == BINDING}
        need(run_id in eligible, 'capture_index_missing')
        # All dispatches consume a slot, including failures/reruns. Future queued runs
        # do not revoke earlier slots; rerunning an old ID can never capture again.
        ordered = sorted(eligible.values(), key=lambda r: (utc(r['created_at']), r['id']))
        need(next(i for i, r in enumerate(ordered, 1) if r['id'] == run_id) <= p['max_captures'], 'capture_cap')
    else:
        need(run.get('status') == 'completed' and run.get('conclusion') == 'success'
             and utc(run['updated_at']) <= utc(p['not_after']), 'run_not_successful_in_window')
    for name, pin in p['source_files_sha256'].items():
        blob = api('contents/' + name + '?ref=' + sha)
        need(blob.get('encoding') == 'base64' and blob.get('type') == 'file', 'source_metadata')
        need(digest(base64.b64decode(blob['content'].replace('\n', ''), validate=True)) == pin, 'run_source_mismatch')
    return run


def runtime(p):
    deploy = api(f"actions/runs/{p['deploy_run_id']}")
    need(deploy.get('id') == p['deploy_run_id'] and deploy.get('repository', {}).get('full_name') == REPO
         and deploy.get('path') == '.github/workflows/deploy.yml'
         and deploy.get('head_branch') == 'main' and deploy.get('status') == 'completed'
         and deploy.get('conclusion') == 'success', 'deployment_not_completed')
    jobs = pages(f"actions/runs/{p['deploy_run_id']}/jobs?filter=latest", 'jobs')
    matching = [j for j in jobs if j.get('name') == 'Deploy to Production']
    need(len(matching) == 1 and matching[0].get('conclusion') == 'success', 'deployment_not_executed')
    manifest = public_json('https://daan.lifenet.com.tw/deployment.json')
    need(type(manifest.get('schema')) is int and manifest['schema'] == 1 and manifest.get('source') == 'github-actions:deploy.yml'
         and manifest.get('backend_sha') == p['expected_backend_sha'], 'runtime_mismatch')
    # The manifest has no run ID. The approved policy binds the canonical run/SHA
    # pair; these observations do not independently invent that authorization.


def payload(data, p):
    obj = parse(data)
    keys = {'ok', 'case', 'read_only', 'prod_head', 'student_ids', 'student_count', 'target_classes',
            'class_sessions', 'active_signins', 'deduction_ledger', 'learning_record_metadata',
            'schedules', 'schedule_change_metadata', 'counts', 'verification'}
    need(isinstance(obj, dict) and set(obj) == keys and obj['ok'] is True and obj['read_only'] is True
         and obj['case'] == 'transfer_capacity_student' and obj['prod_head'] == p['expected_backend_sha']
         and obj['student_ids'] == [493] and type(obj['student_ids'][0]) is int
         and type(obj['student_count']) is int and obj['student_count'] == 1,
         'payload_schema_or_scope')
    classes = obj['target_classes']
    need(isinstance(classes, list) and 0 < len(classes) <= 200 and all(isinstance(r, dict)
         and type(r.get('class_id')) is int and r['class_id'] > 0
         and type(r.get('student_id')) is int and r['student_id'] == 493
         and type(r.get('campus_id')) is int and r['campus_id'] == 15 for r in classes), 'payload_scope')
    ids = {r['class_id'] for r in classes}
    need(len(ids) == len(classes), 'duplicate_class')
    for name in ('class_sessions', 'active_signins', 'deduction_ledger', 'learning_record_metadata',
                 'schedules', 'schedule_change_metadata'):
        rows = obj[name]
        need(isinstance(rows, list) and len(rows) <= 2000, 'payload_rows')
        for row in rows:
            need(isinstance(row, dict), 'payload_row_schema')
            cid = row.get('class_id')
            need((type(cid) is int and cid in ids) or (name == 'schedules' and cid is None), 'payload_relation_scope')
            if name == 'schedules':
                need(type(row.get('student_id')) is int and row['student_id'] == 493
                     and type(row.get('campus_id')) is int and row['campus_id'] == 15, 'schedule_scope')
    need(isinstance(obj['verification'], dict) and obj['verification'].get('rows_complete') is True
         and isinstance(obj['counts'], dict), 'payload_incomplete')
    return obj


def profile(path):
    file_bytes(path, private=True)
    text = native(['cms', '-cmsout', '-inform', 'DER', '-in', path, '-print', '-noout']).decode('ascii')
    need('contentType: id-smime-ct-authEnvelopedData (1.2.840.113549.1.9.16.1.23)' in text
         and text.count('d.ktri:') == 1 and 'algorithm: aes-256-gcm (2.16.840.1.101.3.4.1.46)' in text
         and 'algorithm: rsaesOaep (1.2.840.113549.1.1.7)' in text
         and len(re.findall(r'OBJECT\s*:sha256\b', text)) == 2
         and len(re.findall(r'OBJECT\s*:mgf1\b', text)) == 1, 'cms_profile')


def purge(path):
    if path.exists():
        subprocess.run(['shred', '-u', '--', str(path)], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)


def private_dir(path):
    st = path.lstat()
    need(stat.S_ISDIR(st.st_mode) and st.st_uid == os.getuid() and stat.S_IMODE(st.st_mode) == 0o700,
         'private_directory_required')


def atomic(path, data):
    private_dir(path.parent)
    anchor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    name = '.case5124-' + secrets.token_hex(16)
    try:
        st = os.fstat(anchor)
        need(st.st_uid == os.getuid() and stat.S_IMODE(st.st_mode) == 0o700, 'private_directory_required')
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=anchor)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.link(name, path.name, src_dir_fd=anchor, dst_dir_fd=anchor, follow_symlinks=False)
    finally:
        try:
            os.unlink(name, dir_fd=anchor)  # Never shred the published inode.
        except FileNotFoundError:
            pass
        os.close(anchor)


def seal(p, run, raw, out):
    data = file_bytes(raw, private=True)
    payload(data, p)
    private_dir(out)
    capture = {'schema': 1, 'binding': BINDING, 'run_id': run['id'], 'source_sha': run['head_sha'],
               'backend_sha': p['expected_backend_sha'], 'captured_at': now().strftime('%Y-%m-%dT%H:%M:%SZ'),
               'payload_sha256': digest(data)}
    with tempfile.TemporaryDirectory(prefix='.case5124-seal-', dir=out) as name:
        work = Path(name)
        archive, cipher = work / 'unverified.tar.gz', work / 'cipher.der'
        try:
            with tarfile.open(archive, 'w:gz') as tar:
                for member, content in [('production-case.json', data), ('capture.json', encoded(capture))]:
                    info = tarfile.TarInfo(member)
                    info.size, info.mode = len(content), 0o600
                    tar.addfile(info, io.BytesIO(content))
            archive.chmod(0o600)
            native(['cms', '-encrypt', '-binary', '-aes-256-gcm', '-in', archive, '-outform', 'DER',
                    '-out', cipher, '-recip', CERT, '-keyopt', 'rsa_padding_mode:oaep',
                    '-keyopt', 'rsa_oaep_md:sha256', '-keyopt', 'rsa_mgf1_md:sha256'])
            cipher.chmod(0o600)
            profile(cipher)
            runtime(p)  # Public identity recheck after the same payload was captured.
            blob = file_bytes(cipher, private=True)
            need(now() <= utc(p['not_after']), 'capture_window_closed')
            atomic(out / SIDECAR, blob)
            try:
                atomic(out / SUMMARY, encoded({'status': 'sealed', 'recipient_sha256': p['recipient_sha256'],
                                               'ciphertext_sha256': digest(blob)}))
            except Exception:
                (out / SIDECAR).unlink()
                raise
        finally:
            purge(archive)


def unseal(p, run_id, artifact_id, zipped, key, output):
    run = validate_run(p, run_id)
    need(type(artifact_id) is int and artifact_id > 0, 'artifact_id_schema')
    metadata = api(f'actions/artifacts/{artifact_id}')
    blob = file_bytes(zipped, private=True, cap=64 * 1024 * 1024)
    need(metadata.get('id') == artifact_id and metadata.get('name') == ARTIFACT
         and metadata.get('expired') is False and metadata.get('workflow_run', {}).get('id') == run_id
         and metadata.get('workflow_run', {}).get('head_sha') == run['head_sha']
         and metadata.get('digest') == 'sha256:' + digest(blob), 'artifact_provenance')
    private_dir(output.parent)
    file_bytes(key, private=True, cap=16384)
    with tempfile.TemporaryDirectory(prefix='.case5124-read-', dir=output.parent) as name:
        work = Path(name)
        archive, cipher = work / 'unverified.tar.gz', work / 'cipher.der'
        try:
            with zipfile.ZipFile(io.BytesIO(blob)) as source:
                entries = source.infolist()
                allowed = {SIDECAR, SUMMARY, 'production-case.full.tar.gz.enc', 'production-case.full.tar.gz.enc.sha256'}
                need(len(entries) == 4 and {i.filename for i in entries} == allowed
                     and all(i.file_size <= LIMIT and not stat.S_ISLNK(i.external_attr >> 16) for i in entries), 'artifact_member_set')
                summary = parse(source.read(SUMMARY))
                encrypted = source.read(SIDECAR)
            need(isinstance(summary, dict) and set(summary) == {'status', 'recipient_sha256', 'ciphertext_sha256'}
                 and summary['status'] == 'sealed' and summary['recipient_sha256'] == p['recipient_sha256']
                 and summary['ciphertext_sha256'] == digest(encrypted), 'summary_schema_or_hash')
            cipher.write_bytes(encrypted)
            cipher.chmod(0o600)
            profile(cipher)
            # OpenSSL may write complete UNAUTHENTICATED plaintext before nonzero exit.
            native(['cms', '-decrypt', '-binary', '-inform', 'DER', '-in', cipher,
                    '-recip', CERT, '-inkey', key, '-out', archive])
            archive.chmod(0o600)
            file_bytes(archive, private=True)
            with gzip.open(archive, 'rb') as stream:
                expanded = stream.read(LIMIT + 65537)
                need(len(expanded) <= LIMIT + 65536, 'capsule_expansion_cap')
            with tarfile.open(fileobj=io.BytesIO(expanded), mode='r:') as tar:
                members = []
                for member in tar:
                    members.append(member)
                    need(len(members) <= 2, 'capsule_members')
                need(len(members) == 2 and {m.name for m in members} == {'production-case.json', 'capture.json'}
                     and all(m.isfile() and m.mode == 0o600 and m.size <= LIMIT for m in members), 'capsule_members')
                data = tar.extractfile('production-case.json').read(LIMIT + 1)
                capture = parse(tar.extractfile('capture.json').read(16385))
            payload(data, p)
            need(isinstance(capture, dict) and set(capture) == {'schema', 'binding', 'run_id', 'source_sha',
                 'backend_sha', 'captured_at', 'payload_sha256'} and type(capture['schema']) is int and capture['schema'] == 1
                 and capture['binding'] == BINDING and capture['run_id'] == run_id
                 and capture['source_sha'] == run['head_sha'] and capture['backend_sha'] == p['expected_backend_sha']
                 and capture['payload_sha256'] == digest(data)
                 and utc(run['created_at']) <= utc(capture['captured_at']) <= utc(p['not_after']), 'capsule_provenance')
            need(now() <= utc(p['not_after']), 'reader_window_closed')
            atomic(output, data)
        finally:
            purge(archive)


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['preflight', 'seal', 'read'])
    parser.add_argument('--run-id', type=int)
    parser.add_argument('--artifact-id', type=int)
    parser.add_argument('--artifact-zip', type=Path)
    parser.add_argument('--private-key', type=Path)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    try:
        p = load_policy()
        if args.mode == 'read':
            need(utc(p['not_before']) <= now() <= utc(p['not_after']), 'reader_window_closed')
            need(all([args.run_id, args.artifact_id, args.artifact_zip, args.private_key, args.output]), 'reader_arguments')
            unseal(p, args.run_id, args.artifact_id, args.artifact_zip, args.private_key, args.output)
        else:
            event = parse(file_bytes(Path(os.environ['GITHUB_EVENT_PATH'])))
            inputs = event.get('inputs', {})
            need(os.environ.get('GITHUB_REPOSITORY') == REPO and os.environ.get('GITHUB_REF') == 'refs/heads/main'
                 and os.environ.get('GITHUB_EVENT_NAME') == 'workflow_dispatch'
                 and os.environ.get('GITHUB_RUN_ATTEMPT') == '1'
                 and inputs.get('reader_profile') == 'case5124' and inputs.get('case_key') == 'transfer_capacity_student'
                 and inputs.get('student_id', '') == '' and inputs.get('student_class_id', '') == ''
                 and inputs.get('expected_teacher_ids', '') == '' and inputs.get('subject_name', '數學') == '數學'
                 and inputs.get('expected_backend_sha') == p['expected_backend_sha'], 'dispatch_scope')
            run = validate_run(p, int(os.environ['GITHUB_RUN_ID']), capture=True, expected_sha=os.environ['GITHUB_SHA'])
            if args.mode == 'preflight':
                runtime(p)
            else:
                seal(p, run, ROOT / 'raw/production-case.json', ROOT / 'out')
        print('case5124_reader=verified')
    except Exception:
        # Native stderr, paths, metadata and exception text can contain private data.
        print('case5124_reader=HOLD')
        raise SystemExit(1) from None


if __name__ == '__main__':
    main()
