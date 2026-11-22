import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "infra/agent-readonly"))
from collect_daan import collect, pages

SCHEMA = {
    "Campus": "id is_test",
    "Student": "id CampusID",
    "StudentClass": "ID StudentID StartDate Paid Charge PackageID ScheduleMode closed_reason",
    "course_packages": "id campus_id student_id paid",
    "Invoice": "id StudentID StudentClassID IssueDate billing_period created_at TotalAmount PaidAmount Status",
    "InvoiceItem": "id InvoiceID StudentClassID Amount PeriodStart",
    "Payment": "id InvoiceID PaidAt payment_report_id Amount Method created_at",
    "payment_reports": "id StudentID StudentClassID InvoiceID payment_id billing_period payment_date reported_amount created_at confirmed_at voided_at status",
    "payment_report_reassignments": "id campus_id from_report_id to_report_id from_invoice_id to_invoice_id from_student_id to_student_id amount created_at",
}


class FakeReadApi:
    def __init__(self):
        self.data = {table: [] for table in SCHEMA}
        self.requests = []

    def add(self, table, **fields):
        self.data[table].append({**dict.fromkeys(SCHEMA[table].split()), **fields})

    def fetch(self, table=None, params=()):
        if table is None:
            return {"tables": list(self.data)}
        params = dict(params)
        self.requests.append((table, params))
        selected = self.data[table]
        for spec, target in params.items():
            if "[" not in spec:
                continue
            operator, field = spec[:-1].split("[")
            if operator == "eq":
                selected = [r for r in selected if r[field] == target]
            elif operator == "in":
                selected = [r for r in selected if str(r[field]) in target.split(",")]
            elif operator == "gte":
                selected = [r for r in selected if r[field] >= target]
            elif operator == "lte":
                selected = [r for r in selected if r[field] and r[field] <= target]
        selected = sorted(selected, key=lambda r: r[params["order"]])[:2]
        return {"table": table, "rows": selected, "columns": SCHEMA[table].split(), "count": len(selected)}


class DaanApiCollectorTest(unittest.TestCase):
    def test_late_bills_and_audited_receipt_moves_preserve_october_cash_once(self):
        api = FakeReadApi()
        api.add("Campus", id=15, is_test=0)
        api.add("Student", id=1, CampusID=15)
        api.add("Student", id=2, CampusID=9)
        api.add("StudentClass", ID=3, StudentID=1, StartDate="2026-10-01", ScheduleMode="count", Charge=200)
        for identifier, student, issue in ((1, 1, "2026-10-03"), (2, 1, "2026-11-10"),
                                           (3, 2, "2026-10-03"), (4, 1, "2026-11-15")):
            api.add("Invoice", id=identifier, StudentID=student, StudentClassID=3, IssueDate=issue, TotalAmount=200, Status="paid")
        for key, invoice, date, amount, method, report, created in (
            (10, 1, "2026-10-31", 200, "cash", 20, "2026-10-31"),
            (11, 1, "2026-11-10", -200, "void", 20, "2026-11-10"),
            (12, 2, "2026-10-31", 200, "cash", 21, "2026-11-10"),
            (13, 3, "2026-10-31", 500, "cash", None, "2026-10-31"),
            (14, 4, "2026-11-15", 900, "cash", None, "2026-11-15"),
        ):
            api.add("Payment", id=key, InvoiceID=invoice, PaidAt=date, Amount=amount, Method=method,
                    payment_report_id=report, created_at=created)
        api.add("payment_reports", id=20, StudentID=1, InvoiceID=1, payment_id=10, status="voided",
                reported_amount="200.00", payment_date="2026-10-31")
        api.add("payment_reports", id=21, StudentID=1, InvoiceID=2, payment_id=12, status="confirmed",
                reported_amount="200.00", payment_date="2026-10-31")
        api.add("payment_report_reassignments", id=30, campus_id=15, from_report_id=20, to_report_id=21,
                from_invoice_id=1, to_invoice_id=2, from_student_id=1, to_student_id=1, amount=200, created_at="2026-11-10")

        report = collect(api.fetch, "2026-11-23")
        self.assertEqual(report["monthly"]["2026-10"]["net_recorded_collections"], 200)
        self.assertEqual([r["id"] for r in report["later_activity"]], [11, 12])
        self.assertEqual(report["questions"], [])
        invoice_reads = [request for name, request in api.requests if name == "Invoice"]
        self.assertEqual([r["gte[id]"] for r in invoice_reads], [1, 3, 5])
        self.assertTrue(all(r["in[StudentID]"] == "1" for r in invoice_reads))

    def test_scope_or_schema_mismatch_fails_closed(self):
        api = FakeReadApi()
        api.add("Campus", id=15, is_test=1)
        with self.assertRaisesRegex(ValueError, "campus"):
            collect(api.fetch, "2026-11-23")

        def mismatched(*_):
            return {"table": "Student", "columns": ["id", "CampusID"], "count": 1, "rows": [{"id": 1, "CampusID": 9}]}

        with self.assertRaisesRegex(ValueError, "ownership"):
            list(pages(mismatched, "Student", "id", "CampusID", eq=(("CampusID", 15),)))


if __name__ == "__main__":
    unittest.main()
