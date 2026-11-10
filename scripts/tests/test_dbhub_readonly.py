"""Exercise pinned DBHub through real MCP against disposable synthetic data.

The reusable client also supports the separate MySQL native-grant proof. None of
these checks establishes production access, schema, audit or campus authority.
"""
from contextlib import contextmanager
import json
import os
import re
from pathlib import Path
import selectors
import sqlite3
import subprocess
import sys
import tempfile
import time


@contextmanager
def server(binary, dsn):
    config = Path(__file__).resolve().parents[2] / 'infra/agent-readonly/dbhub.toml'
    with tempfile.TemporaryDirectory(prefix='alltrue-dbhub-mcp-') as directory:
        logfile = Path(directory) / 'server.log'
        with logfile.open('w') as log:
            # Do not pass unrelated application/triage credentials to this server.
            environment = {'PATH': os.environ['PATH'], 'ALLTRUE_READONLY_DSN': dsn}
            process = subprocess.Popen(
                [binary, '--transport', 'stdio', '--config', str(config)],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=log,
                text=True, env=environment,
            )
            selector = selectors.DefaultSelector()
            selector.register(process.stdout, selectors.EVENT_READ)
            sequence = 0

            def call(method, params):
                nonlocal sequence
                sequence += 1
                process.stdin.write(json.dumps({'jsonrpc': '2.0', 'id': sequence, 'method': method, 'params': params}) + '\n')
                process.stdin.flush()
                deadline = time.monotonic() + 20
                while time.monotonic() < deadline:
                    if not selector.select(timeout=1):
                        continue
                    line = process.stdout.readline()
                    if not line:
                        # Logs may contain DSN information. Keep them out of test output.
                        codes = sorted(set(re.findall(r'\b(?:ECONNREFUSED|ETIMEDOUT|ENOTFOUND|ER_[A-Z_]+)\b', logfile.read_text())))
                        raise RuntimeError('DBHub exited before its response; codes=' + ','.join(codes))
                    result = json.loads(line)
                    if result.get('id') == sequence:
                        return result
                raise TimeoutError(method)

            def tool(name, arguments):
                reply = call('tools/call', {'name': name, 'arguments': arguments})
                result = reply.get('result', {})
                if reply.get('error') or result.get('isError'):
                    return {'success': False}
                return json.loads(result['content'][0]['text'])

            try:
                initialized = call('initialize', {'protocolVersion': '2024-11-05', 'capabilities': {}, 'clientInfo': {'name': 'alltrue-readonly-proof', 'version': '1'}})
                assert initialized['result']['serverInfo']['version'] == '1.6.0'
                process.stdin.write('{"jsonrpc":"2.0","method":"notifications/initialized"}\n')
                process.stdin.flush()
                listed = call('tools/list', {})['result']['tools']
                assert {item['name'] for item in listed} == {'execute_sql', 'search_objects', 'contract_chain', 'lesson_timeline'}
                yield tool
            finally:
                selector.close()
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
                process.stdin.close()
                process.stdout.close()


def statement(result):
    assert result['success'] is True, 'read unexpectedly failed'
    return result['data']['statements'][0]


def exercise(tool):
    capped = statement(tool('execute_sql', {'sql': 'SELECT class_id FROM contracts ORDER BY class_id'}))
    assert len(capped['rows']) == 200 and capped['count'] == 200 and capped['truncated'] is True
    for sql in ['DELETE FROM sessions', 'SELECT 1; DELETE FROM sessions', 'DROP TABLE sessions']:
        assert tool('execute_sql', {'sql': sql})['success'] is False
    assert tool('contract_chain', {'campus_id': '15 OR 1=1', 'student_id': 1})['success'] is False
    chain = statement(tool('contract_chain', {'campus_id': 15, 'student_id': 1}))
    assert [row['class_id'] for row in chain['rows']] == [1, 2]
    assert all(row['matched_rows'] == 2 for row in chain['rows'])
    timeline = statement(tool('lesson_timeline', {'campus_id': 15, 'student_id': 1}))
    rows = timeline['rows']
    assert len(rows) == 9 and all(row['campus_id'] == 15 and row['student_id'] == 1 and row['matched_rows'] == 9 for row in rows)
    assert [str(row['session_date']) for row in rows] == sorted(str(row['session_date']) for row in rows)
    assert chain['rows'][0]['purchased_units'] == 8 and len(rows) == 9
    large = statement(tool('contract_chain', {'campus_id': 15, 'student_id': 3}))
    assert len(large['rows']) == 200 and large['truncated'] is True
    assert all(row['matched_rows'] == 210 for row in large['rows'])
    print('PASS: real DBHub 1.6.0 MCP; readonly refusals, parameter validation, scope, chronology, 8/9 evidence, caps and truncation')


def main(binary):
    with tempfile.TemporaryDirectory(prefix='alltrue-dbhub-fixture-') as directory:
        database = Path(directory) / 'synthetic.sqlite'
        with sqlite3.connect(database) as conn:
            conn.executescript('''
                CREATE TABLE contracts(class_id INTEGER, student_id INTEGER, campus_id INTEGER, purchased_units INTEGER, start_date TEXT);
                CREATE TABLE sessions(session_id INTEGER, class_id INTEGER, student_id INTEGER, campus_id INTEGER, session_date TEXT, start_time TEXT, status TEXT);
                INSERT INTO contracts VALUES(1,1,15,8,'2026-01-01'),(2,1,15,8,'2026-01-10'),(3,2,16,4,'2026-01-01');
            ''')
            conn.executemany("INSERT INTO sessions VALUES(?,1,1,15,?,'10:00','attended')", [(n, f'2026-01-{n:02}') for n in range(1, 10)])
            conn.execute("INSERT INTO sessions VALUES(10,3,2,16,'2026-01-10','10:00','attended')")
            conn.executemany("INSERT INTO contracts VALUES(?,3,15,4,'2026-02-01')", [(n,) for n in range(4, 214)])
        with server(binary, 'sqlite://' + str(database)) as tool:
            exercise(tool)
        with sqlite3.connect(database) as conn:
            assert conn.execute('SELECT COUNT(*) FROM sessions').fetchone()[0] == 10


if __name__ == '__main__':
    main(sys.argv[1])
