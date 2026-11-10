"""Disposable local MySQL proof; accepts only a DBHub binary, never an external DSN.

Uses the existing mysql:8.0 test image. The fixture is derived from the view
projection, so this verifies SQL/grants/privacy, NOT live-schema compatibility.
"""
import json
from pathlib import Path
import re
import secrets
import subprocess
import sys
import time
import uuid

from test_dbhub_readonly import exercise, server


def main(binary):
    name = 'alltrue-dbhub-test-' + uuid.uuid4().hex[:12]
    source = 'alltrue_source_test'
    sql = (Path(__file__).resolve().parents[2] / 'infra/agent-readonly/views.sql').read_text()
    password = secrets.token_hex(24)

    def docker(*args, data=None, check=True):
        return subprocess.run(['docker', *args], input=data, capture_output=True, text=True, timeout=30, check=check)

    def root_sql(query):
        return docker('exec', '-i', name, 'mysql', '-uroot', '--batch', '--skip-column-names', data=query)

    docker('run', '--detach', '--rm', '--name', name, '--label', 'alltrue.synthetic=1',
           '--publish', '127.0.0.1::3306', '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes',
           '--memory', '768m', '--cpus', '1', 'mysql:8.0')
    try:
        deadline = time.monotonic() + 90
        while time.monotonic() < deadline:
            if docker('exec', name, 'mysqladmin', 'ping', '-h127.0.0.1', '-uroot', '--silent', check=False).returncode == 0:
                break
            time.sleep(1)
        else:
            raise TimeoutError('synthetic MySQL startup')

        # Build disposable source columns from every explicit projection. This
        # deliberately cannot certify that a production installation has them.
        tables = {}
        for statement in sql.split(';'):
            aliases = dict((alias, table) for table, alias in re.findall(r'(?:FROM|JOIN) __SOURCE_SCHEMA__\.(\w+) (\w+)', statement))
            for alias, column in re.findall(r'\b(\w+)\.(\w+)', statement):
                if alias in aliases:
                    tables.setdefault(aliases[alias], set()).add(column)
        tables['course_packages'] = set('id student_id campus_id billing_mode total_sessions remaining_sessions used_sessions class_type paid paid_at stop closed_reason enabled'.split())
        fixture = [f'CREATE DATABASE {source}', 'CREATE DATABASE alltrue_diagnostics']
        for table, columns in tables.items():
            # Include synthetic sensitive fields to demonstrate view exclusion.
            columns |= {'name', 'password', 'Notify_Token', 'Memo', 'Comment', 'closed_reason', 'source', 'reason'}
            definitions = []
            for column in sorted(columns):
                numeric = column.lower() == 'id' or column.lower().endswith('id') or column.endswith('_id') or column in {'SessionCount', 'CampusID'}
                definitions.append('`' + column + '` ' + ('BIGINT' if numeric else 'VARCHAR(128)'))
            fixture.append(f'CREATE TABLE {source}.`{table}` (' + ','.join(definitions) + ')')
        fixture.extend([
            f"INSERT INTO {source}.Student (id,CampusID,name) VALUES (1,15,'synthetic'),(2,16,'synthetic'),(3,15,'synthetic')",
            f"INSERT INTO {source}.StudentClass (ID,StudentID,SessionCount,StartDate) VALUES (1,1,8,'2026-01-01'),(2,1,8,'2026-01-10'),(3,2,4,'2026-01-01')",
            f"INSERT INTO {source}.StudentClass (ID,StudentID,SessionCount,StartDate) VALUES " + ','.join(f"({n},3,4,'2026-02-01')" for n in range(4, 214)),
            f"INSERT INTO {source}.ClassSession (id,StudentClassID,SessionDate,StartTime,Status) VALUES " + ','.join(f"({n},1,'2026-01-{n:02}','10:00','attended')" for n in range(1, 10)) + ",(10,3,'2026-01-10','10:00','attended')",
        ])
        root_sql(';\n'.join(fixture) + ';\n' + sql.replace('__SOURCE_SCHEMA__', source))
        sentinel = 'synthetic-contact@example.invalid'
        root_sql(f"UPDATE {source}.StudentClass SET ClassType='{sentinel}', closed_reason='{sentinel}';"
                 f"INSERT INTO {source}.course_packages (id,class_type,closed_reason) VALUES (1,'{sentinel}','{sentinel}');"
                 f"INSERT INTO {source}.Payment (id,Method) VALUES (1,'{sentinel}');"
                 f"INSERT INTO {source}.session_deduction_ledger (id,event_type,source) VALUES (1,'deduct','{sentinel}');"
                 f"INSERT INTO {source}.package_session_ledger (id,reason) VALUES (1,'{sentinel}');")
        views = re.findall(r'CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics\.(\w+)', sql)
        assert len(views) == 11
        root_sql(f"CREATE USER 'agent_test'@'%' IDENTIFIED BY '{password}';\n" + '\n'.join(f"GRANT SELECT ON alltrue_diagnostics.`{view}` TO 'agent_test'@'%';" for view in views))
        # Test native grants independently of DBHub's readonly parser.
        config = f'[client]\nuser=agent_test\npassword={password}\n'.replace('\\', '\\\\')
        docker('exec', '-i', name, 'sh', '-c', 'umask 077; cat > /tmp/agent-test.cnf', data=config)

        def native(query):
            return docker('exec', '-i', name, 'mysql', '--defaults-extra-file=/tmp/agent-test.cnf', '--batch', '--skip-column-names', data=query, check=False)

        assert native('SELECT COUNT(*) FROM alltrue_diagnostics.contracts').returncode == 0
        for query in [
            'DELETE FROM alltrue_diagnostics.sessions',
            f'DELETE FROM {source}.ClassSession',
            f'SELECT name FROM {source}.Student',
            'SELECT User FROM mysql.user',
            'CREATE TABLE alltrue_diagnostics.unauthorized (id INT)',
            'SELECT password FROM alltrue_diagnostics.contracts',
        ]:
            assert native(query).returncode != 0, 'native grant/column refusal failed'
        for view in views:
            selected = native(f'SELECT * FROM alltrue_diagnostics.`{view}`')
            assert selected.returncode == 0, f'{view}: {selected.stderr}'
            assert sentinel not in selected.stdout, f'free-text sentinel leaked from {view}'
        port = docker('port', name, '3306/tcp').stdout.strip().rsplit(':', 1)[1]
        with server(binary, f'mysql://agent_test:{password}@127.0.0.1:{port}/alltrue_diagnostics') as tool:
            exercise(tool)
            assert tool('execute_sql', {'sql': f'SELECT name FROM {source}.Student'})['success'] is False
            assert tool('execute_sql', {'sql': 'SELECT password FROM contracts'})['success'] is False
        assert root_sql(f'SELECT COUNT(*) FROM {source}.ClassSession').stdout.strip() == '10'
        print('PASS: eleven native MySQL views, view-only SELECT grants, source/system/sensitive-column denials; fixture rows preserved')
    finally:
        docker('rm', '--force', name, check=False)


if __name__ == '__main__':
    main(sys.argv[1])
