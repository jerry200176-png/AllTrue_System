"""Prove the scoped views and MCP contract on a disposable local MariaDB."""

import json
import os
from pathlib import Path
import pwd
import secrets
import socket
import subprocess
import sys
import tempfile
import time
import tomllib
import unittest

from test_dbhub_readonly import server, statement

PROFILE = Path(__file__).resolve().parents[2] / "infra/agent-readonly"
SOURCE = "daan_fixture"
SCHEMA = "alltrue_diagnostics"
VIEWS = ("scope", "contracts", "invoices", "invoice_items", "payments", "reports")
FIXTURE = """
CREATE DATABASE daan_fixture;
CREATE DATABASE alltrue_diagnostics;
USE daan_fixture;
CREATE TABLE Campus (id INT PRIMARY KEY, code VARCHAR(20), is_test TINYINT);
CREATE TABLE Student (id INT PRIMARY KEY, CampusID INT, name VARCHAR(80));
CREATE TABLE StudentClass (ID INT PRIMARY KEY, StudentID INT, StartDate DATE, EndDate DATE,
    Charge INT, Paid TINYINT, MDate DATETIME, ScheduleMode VARCHAR(20), PackageID INT, closed_reason VARCHAR(20));
CREATE TABLE course_packages (id INT PRIMARY KEY, student_id INT, campus_id INT, paid TINYINT);
CREATE TABLE Invoice (id INT PRIMARY KEY, StudentID INT, StudentClassID INT, IssueDate DATE,
    billing_period VARCHAR(20), created_at DATETIME, updated_at DATETIME, TotalAmount INT, PaidAmount INT, Status VARCHAR(20));
CREATE TABLE InvoiceItem (id INT PRIMARY KEY, InvoiceID INT, StudentClassID INT, Amount INT, PeriodStart DATE, PeriodEnd DATE);
CREATE TABLE Payment (id INT PRIMARY KEY, InvoiceID INT, payment_report_id INT, Amount INT, PaidAt DATETIME,
    created_at DATETIME, updated_at DATETIME, Method VARCHAR(40), Note VARCHAR(100));
CREATE TABLE payment_reports (id INT PRIMARY KEY, StudentID INT, StudentClassID INT, InvoiceID INT, payment_id INT,
    billing_period VARCHAR(20), payment_date DATE, reported_amount DECIMAL(10,2), created_at DATETIME, updated_at DATETIME,
    confirmed_at DATETIME, voided_at DATETIME, status VARCHAR(20), note VARCHAR(80));
CREATE TABLE payment_report_reassignments (id INT PRIMARY KEY, from_report_id INT UNIQUE, to_report_id INT UNIQUE,
    campus_id INT, from_student_id INT, to_student_id INT, from_invoice_id INT, to_invoice_id INT, amount INT, created_at DATETIME);
INSERT INTO Campus VALUES (15,'daan',0),(9,'other',0);
INSERT INTO Student VALUES (1,15,'never expose'),(2,9,'other campus'),(3,15,'different owner');
INSERT INTO StudentClass (ID,StudentID,StartDate,PackageID) VALUES
    (31,1,'2026-01-01',30),(32,2,'2025-01-01',NULL),(33,1,'2026-01-01',NULL);
INSERT INTO course_packages VALUES (30,2,9,1);
INSERT INTO Invoice (id,StudentID,StudentClassID,IssueDate,TotalAmount) VALUES
    (1,1,32,'2026-10-31',100),(2,2,32,'2026-10-31',200),(3,1,31,'2026-11-01',300),
    (4,3,NULL,'2026-10-31',600),(5,1,31,'2026-11-02',50);
INSERT INTO InvoiceItem (id,InvoiceID,StudentClassID,Amount) VALUES (1,1,32,100),(2,2,32,200),(3,5,33,25);
INSERT INTO Payment (id,InvoiceID,payment_report_id,Amount,Method,PaidAt) VALUES
    (1,1,2,100,'cash','2026-11-01 00:00:00'),(2,2,2,200,'cash','2026-10-31'),
    (3,4,NULL,600,'cash','2026-10-31'),(4,5,NULL,50,'cash','2026-10-31');
INSERT INTO payment_reports (id,StudentID,StudentClassID,InvoiceID,payment_id,reported_amount,status,payment_date,note) VALUES
    (1,1,32,2,3,100,'confirmed','2026-10-31','private fixture'),
    (2,2,32,2,2,200,'pending','2026-10-31','other campus');
UPDATE payment_reports SET billing_period='private malformed' WHERE id=1;
UPDATE Invoice SET billing_period='2000 malformed' WHERE id=3;
"""


def exercise(binary, socket_path, port, operator):
    def sql(query, user=operator, password=None):
        endpoint = (["--protocol=tcp", "--host=127.0.0.1", f"--port={port}"]
                    if password else ["--protocol=socket", f"--socket={socket_path}"])
        return subprocess.run(
            ["mariadb", "--no-defaults", *endpoint, f"--user={user}", "--batch", "--skip-column-names"],
            input=query, text=True, capture_output=True, timeout=10,
            env={"MYSQL_PWD": password} if password else None,
        )

    def root(query):
        result = sql(query)
        assert result.returncode == 0, result.stderr
        return result.stdout.strip()

    extra = ",".join(f"({i},1,31,'2026-10-01',100)" for i in range(10, 211))
    root(FIXTURE + f"INSERT INTO Invoice (id,StudentID,StudentClassID,IssueDate,TotalAmount) VALUES {extra};")
    root((PROFILE / "daan-tuition-views.sql").read_text().replace("__SOURCE_SCHEMA__", SOURCE))
    password = secrets.token_hex(24)
    root(f"CREATE USER 'reader'@'127.0.0.1' IDENTIFIED BY '{password}';" + "".join(
        f"GRANT SELECT ON {SCHEMA}.daan_tuition_{view} TO 'reader'@'127.0.0.1';" for view in VIEWS
    ))
    assert sql(f"SELECT campus_id FROM {SCHEMA}.daan_tuition_scope", "reader", password).stdout.strip() == "15"
    for query in (
        f"SELECT name FROM {SOURCE}.Student", f"SELECT User FROM mysql.user",
        f"SELECT note FROM {SCHEMA}.daan_tuition_reports",
        f"DELETE FROM {SCHEMA}.daan_tuition_payments", f"UPDATE {SOURCE}.Payment SET Amount = 0",
    ):
        assert sql(query, "reader", password).returncode != 0, "native permission or projection refusal failed"

    with server(binary, f"mysql://reader:{password}@127.0.0.1:{port}/{SCHEMA}",
                config=PROFILE / "daan-tuition.toml", dsn_key="ALLTRUE_DAAN_TUITION_DSN",
                tools={f"daan_tuition_{view}" for view in VIEWS}) as tool:
        def page(view, after=0):
            return statement(tool(f"daan_tuition_{view}", {"after_id": after}))["rows"]

        scope, = statement(tool("daan_tuition_scope", {}))["rows"]
        assert (scope["campus_id"], scope["campus_code"], scope["cutoff_exclusive"]) == (15, "daan", "2026-11-01")
        first = page("invoices")
        second = page("invoices", first[-1]["id"])
        assert len(first) == 200 and len(second) == 4 and not page("invoices", second[-1]["id"])
        assert all(row["campus_id"] == 15 for row in first + second)
        assert {2, 3}.isdisjoint(row["id"] for row in first + second)
        assert first[0]["issue_date"] == "2026-10-31" and first[0]["contract_id"] is None
        assert page("contracts")[0]["package_link_uncertain"] == 1
        uncertain, shared = page("invoice_items")
        assert uncertain["contract_link_uncertain"] == 1
        assert shared["contract_id"] == 33 and shared["standalone_count_line"] == 1
        assert page("payments")[0]["paid_at"] == "2026-11-01 00:00:00"
        assert page("payments")[0]["report_link_uncertain"] == 1
        report, = page("reports")
        assert all(report[field] is None for field in ("invoice_id", "payment_id", "contract_id", "billing_period"))
        collected = {name: [page(name), []] for name in VIEWS if name not in {"scope", "invoices"}}
        collected["invoices"] = [first, second, []]
        worksheet = subprocess.run([sys.executable, str(PROFILE / "daan-tuition-review.py")],
                                   input=json.dumps({"scope": scope, "pages": collected}),
                                   capture_output=True, text=True, check=True, timeout=10)
        review = json.loads(worksheet.stdout)
        assert review["monthly"]["2026-10"]["net_recorded_collections"] == "650"
        assert review["later_activity"] == [{"dataset": "payments", "id": 1, "date": "2026-11-01", "amount": "100"}]
        root(f"""USE {SOURCE};
            INSERT INTO payment_reports (id,StudentID,InvoiceID,payment_id) VALUES (3,1,5,4),(4,3,4,6);
            INSERT INTO payment_report_reassignments VALUES (9,3,4,15,1,3,5,4,50,'2026-11-03 12:00:00');
            INSERT INTO Payment (id,InvoiceID,payment_report_id,Amount,Method,PaidAt,Note) VALUES
                (5,5,3,-50,'void','2026-11-03','[receipt_reassign:9] private reason'),
                (6,4,4,50,'cash','2026-10-31','[receipt_reassign:9] private reason'),
                (7,4,NULL,10,'cash','2026-10-31','[receipt_reassign:9] user-supplied note');""")
        outgoing, incoming, unmatched = page("payments", 4)
        assert all(row["receipt_moved_at"] == "2026-11-03 12:00:00" for row in (outgoing, incoming))
        assert unmatched["receipt_moved_at"] is None and unmatched["receipt_move_uncertain"] == 1
        root(f"UPDATE {SOURCE}.Campus SET code='wrong' WHERE id=15;")
        assert not page("invoices") and not statement(tool("daan_tuition_scope", {}))["rows"]
    assert root(f"SELECT SUM(Amount) FROM {SOURCE}.Payment") == "960"


def main(binary):
    with tempfile.TemporaryDirectory(prefix="daan-mariadb-") as directory:
        data = Path(directory) / "data"
        socket_path = Path(directory) / "mysql.sock"
        operator = pwd.getpwuid(os.getuid()).pw_name
        subprocess.run(["mariadb-install-db", "--no-defaults", f"--datadir={data}",
                        "--auth-root-authentication-method=socket", f"--auth-root-socket-user={operator}", "--skip-test-db"],
                       check=True, capture_output=True, timeout=60)
        with socket.socket() as reservation:
            reservation.bind(("127.0.0.1", 0))
            port = reservation.getsockname()[1]
        process = subprocess.Popen(
            ["mariadbd", "--no-defaults", f"--datadir={data}", f"--socket={socket_path}", f"--port={port}",
             "--bind-address=127.0.0.1", f"--pid-file={directory}/mysql.pid", "--skip-log-bin", "--skip-name-resolve"],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        try:
            deadline = time.monotonic() + 20
            while not socket_path.exists():
                if process.poll() is not None or time.monotonic() >= deadline:
                    raise RuntimeError("disposable MariaDB did not start")
                time.sleep(0.1)
            exercise(binary, socket_path, port, operator)
        finally:
            if process.poll() is None:
                process.terminate()
                process.wait(timeout=15)
    print("PASS: native Daan-only grants, six DBHub tools, cursor and cutoff, masked cross-owner links")


class DaanToolProfileTest(unittest.TestCase):
    def test_profile_only_exposes_the_six_fixed_read_queries(self):
        profile = tomllib.loads((PROFILE / "daan-tuition.toml").read_text())
        self.assertEqual([source["id"] for source in profile["sources"]], ["daan_tuition"])
        self.assertEqual(len(profile["tools"]), len(VIEWS))
        self.assertEqual({tool["name"] for tool in profile["tools"]}, {f"daan_tuition_{view}" for view in VIEWS})
        for tool in profile["tools"]:
            self.assertIs(tool["readonly"], True)
            self.assertEqual(tool["source"], "daan_tuition")
            self.assertIn(f' FROM {tool["name"]}', tool["statement"])
            if tool["name"] != "daan_tuition_scope":
                self.assertEqual(tool["max_rows"], 200)
                self.assertTrue(tool["statement"].endswith("WHERE id > ? ORDER BY id LIMIT 200"))


if __name__ == "__main__":
    main(sys.argv[1])
