"""Synthetic regression tests for the Daan tuition worksheet."""
import importlib.util
from pathlib import Path
import unittest
from decimal import Decimal

MODULE = Path(__file__).resolve().parents[2] / "infra/agent-readonly/daan-tuition-review.py"
SPEC = importlib.util.spec_from_file_location("daan_review", MODULE)
REVIEW = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(REVIEW)


def row(id, **fields):
    return {"id": id, "campus_id": 15, **fields}


def payment(id, **fields):
    values = {"invoice_id": 1, "report_id": None, "amount": 100, "method": "cash",
              "paid_at": "2026-10-31 23:59:59", "report_link_uncertain": False}
    return row(id, **(values | fields))


def invoice(id, **fields):
    values = {"contract_id": 1, "issue_date": "2026-10-01", "billing_period": "2026-10",
              "contract_link_uncertain": False, "amount": 500, "stored_paid_amount": 0, "status": "partial"}
    return row(id, **(values | fields))


def report(id, **fields):
    values = {"invoice_id": 1, "payment_id": None, "payment_date": "2026-10-31",
              "billing_period": "2026-10", "confirmed_at": None, "voided_at": None,
              "contract_link_uncertain": False, "invoice_link_uncertain": False, "payment_link_uncertain": False,
              "status": "pending", "amount": 100}
    return row(id, **(values | fields))


def contract(id, **fields):
    values = {"package_id": None, "package_link_uncertain": False, "paid": True, "package_paid": False, "charge": 500}
    return row(id, **(values | fields))


def snapshot(**datasets):
    return {
        "scope": {"campus_id": 15, "campus_code": "daan", "cutoff_exclusive": "2026-11-01",
                  "read_at_utc": "2026-11-23 00:00:00"},
        "pages": {name: [datasets[name], []] if datasets.get(name) else [[]] for name in REVIEW.DATASETS},
    }


class DaanReviewTest(unittest.TestCase):
    def test_cutoff_reversals_and_non_cash_movements(self):
        source = snapshot(
            contracts=[contract(1)], invoices=[invoice(1, issue_date="2023-01-01")],
            payments=[payment(1), payment(2, method="void", amount=35),
                      payment(3, method="refund", amount=-5),
                      payment(4, method="transfer_reversal_in", amount=20),
                      payment(5, method="transfer_out", amount=-10),
                      payment(6, paid_at="2026-11-01 00:00:00", amount=900)],
            reports=[report(1, confirmed_at="2026-11-02", status="confirmed", payment_id=1)],
        )
        result = REVIEW.build_review(source)
        self.assertEqual(result["first_observed_billing_date"], "2023-01-01")
        self.assertEqual(result["monthly"]["2026-10"]["net_recorded_collections"], Decimal(60))
        self.assertEqual(result["monthly"]["2026-10"]["reversals_and_refunds"], Decimal(-40))
        self.assertEqual(result["monthly"]["2026-10"]["internal_credit_movement"], Decimal(10))
        self.assertEqual([(r["dataset"], r["date"]) for r in result["later_activity"]],
                         [("payments", "2026-11-01"), ("reports", "2026-11-02")])
        self.assertEqual(result["questions"], [])

    def test_review_signals_and_package_cash_evidence(self):
        item = row(1, invoice_id=1, contract_id=2, contract_link_uncertain=False, standalone_count_line=False,
                   period_start="2026-10-01", amount=100)
        result = REVIEW.build_review(snapshot(
            invoices=[invoice(1), invoice(2, contract_id=3, stored_paid_amount=10)],
            contracts=[contract(1, package_id=7), contract(2, package_id=7), contract(3)],
            invoice_items=[item],
            payments=[payment(1, report_id=3), payment(2)],
            reports=[report(1), report(2, status="confirmed"),
                     report(3, amount=150, invoice_id=2, status="confirmed")],
        ))
        self.assertEqual({(q["kind"], q["id"]) for q in result["questions"]}, {
            ("possible_duplicate", 1), ("pending_receipt", 1),
            ("confirmed_without_payment", 2), ("receipt_payment_mismatch", 3),
            ("invoice_paid_amount_without_evidence", 2), ("paid_flag_without_evidence", 3),
        })

    def test_named_standalone_bill_line_needs_full_net_coverage(self):
        line = row(1, invoice_id=1, contract_id=2, contract_link_uncertain=False, standalone_count_line=True)
        for net, expected_question in ((499, True), (500, False)):
            with self.subTest(net=net):
                result = REVIEW.build_review(snapshot(
                    contracts=[contract(1), contract(2)], invoices=[invoice(1)],
                    invoice_items=[line], payments=[payment(1, amount=net)],
                ))
                self.assertEqual([(q["kind"], q["id"]) for q in result["questions"]],
                                 [("paid_flag_without_evidence", 2)] if expected_question else [])

    def test_negative_net_payment_flags_positive_stored_amount(self):
        result = REVIEW.build_review(snapshot(invoices=[invoice(1, stored_paid_amount=100)],
                                              payments=[payment(1, method="void", amount=20)]))
        self.assertEqual([q["kind"] for q in result["questions"]], ["invoice_paid_amount_without_evidence"])

    def test_invalid_scope_and_collection_fail_closed(self):
        source = snapshot(invoices=[invoice(1)])
        source["pages"]["invoices"][0][0]["campus_id"] = 9
        with self.assertRaises(ValueError):
            REVIEW.build_review(source)
        source = snapshot(invoices=[invoice(1), invoice(1)])
        with self.assertRaises(ValueError):
            REVIEW.build_review(source)
        source = snapshot(invoices=[invoice(1)])
        source["pages"]["invoices"].pop()
        with self.assertRaises(ValueError):
            REVIEW.build_review(source)


if __name__ == "__main__":
    unittest.main()
