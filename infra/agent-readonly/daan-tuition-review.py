"""Build a private Daan reconciliation worksheet from the approved reader's pages."""

import json
import sys
from collections import defaultdict
from datetime import date
from decimal import Decimal

CUTOFF = date(2026, 11, 1)
DATASETS = ("contracts", "invoices", "invoice_items", "payments", "reports")
CREDIT_IN = {"transfer_in", "transfer_reversal_in"}
CREDIT_OUT = {"transfer_out", "transfer_reversal_out"}


def day(value):
    return date.fromisoformat(str(value)[:10]) if value else None


def money(value):
    amount = Decimal(value)
    if not amount.is_finite():
        raise ValueError("invalid amount")
    return amount


def read_rows(snapshot):
    scope = snapshot["scope"]
    if scope["campus_id"] != 15 or scope["campus_code"] != "daan" or day(scope["cutoff_exclusive"]) != CUTOFF:
        raise ValueError("unexpected campus or cutoff")
    if day(scope["read_at_utc"]) < CUTOFF:
        raise ValueError("snapshot precedes the full review period")
    datasets = {}
    for name in DATASETS:
        pages = snapshot["pages"][name]
        if not pages or pages[-1] != [] or any(not 1 <= len(page) <= 200 for page in pages[:-1]):
            raise ValueError("each dataset needs bounded pages followed by an empty page")
        rows = [row for page in pages for row in page]
        ids = [row["id"] for row in rows]
        if ids != sorted(set(ids)) or any(row["campus_id"] != 15 or type(row["id"]) is not int or row["id"] <= 0 for row in rows):
            raise ValueError("invalid row scope or pagination order")
        datasets[name] = {row["id"]: row for row in rows}
    return datasets


def build_review(snapshot):
    rows = read_rows(snapshot)
    months = defaultdict(lambda: defaultdict(Decimal))
    questions = []
    later_activity = []
    observed_dates = []

    def ask(kind, dataset, row, question, **evidence):
        questions.append({
            "kind": kind, "dataset": dataset, "id": row["id"],
            "question": question, "evidence": evidence,
        })

    def in_period(when):
        if when and when < CUTOFF:
            observed_dates.append(when)
            return True
        return False

    for invoice in rows["invoices"].values():
        issued = day(invoice["issue_date"])
        if in_period(issued):
            month = months[issued.strftime("%Y-%m")]
            month["invoice_count"] += 1
            month["current_invoice_face_value"] += money(invoice["amount"])
            if invoice["status"] == "void":
                month["current_void_invoice_face_value"] += money(invoice["amount"])
        elif issued is None:
            ask("undated_invoice", "invoices", invoice, "這張帳單是哪天開的？", period=invoice["billing_period"])
        if invoice["contract_link_uncertain"]:
            ask("invoice_contract_link", "invoices", invoice, "這張帳單應歸哪份合約？", date=invoice["issue_date"])

    amounts_by_invoice = defaultdict(Decimal)
    current_amounts_by_invoice = defaultdict(Decimal)
    positives_by_report = defaultdict(list)
    duplicate_payments = defaultdict(list)
    for payment in rows["payments"].values():
        invoice = rows["invoices"][payment["invoice_id"]]
        amount = money(payment["amount"])
        method = payment["method"]
        delta = -abs(amount) if method == "void" else amount
        moved = day(payment["receipt_moved_at"])
        when = moved or day(payment["paid_at"])
        if invoice["status"] != "void":
            current_amounts_by_invoice[invoice["id"]] += delta
        if in_period(when):
            month = months[when.strftime("%Y-%m")]
            if moved:
                month["receipt_reassignments"] += delta
            elif (method in CREDIT_IN and amount > 0) or (method in CREDIT_OUT and amount < 0):
                month["internal_credit_movement"] += delta
            elif payment["receipt_move_uncertain"] or method in CREDIT_IN | CREDIT_OUT or method == "other" or (method == "refund" and delta > 0):
                month["unclassified_movement"] += delta
                ask("payment_method", "payments", payment, "這筆是實收、沖銷還是校內轉列？", amount=amount, date=payment["paid_at"])
            else:
                month["positive_collections" if delta > 0 else "reversals_and_refunds"] += delta
                month["net_recorded_collections"] += delta
            if invoice["status"] != "void":
                amounts_by_invoice[invoice["id"]] += delta
            if not moved and delta > 0 and method in {"cash", "transfer"}:
                key = (invoice["id"], when, amount, method)
                duplicate_payments[key].append(payment["id"])
        elif when is None:
            ask("undated_payment", "payments", payment, "這筆款實際是哪天收付或沖銷？", amount=amount, invoice_id=invoice["id"])
        else:
            later_activity.append({"dataset": "payments", "id": payment["id"], "date": str(when), "amount": amount})
            if moved:
                later_activity[-1]["event"] = "receipt_reassignment"
        if delta > 0 and method in {"cash", "transfer"} and payment["report_id"]:
            positives_by_report[payment["report_id"]].append(payment)
        if payment["report_link_uncertain"]:
            ask("payment_report_link", "payments", payment, "這筆收款有對應的繳費回報嗎？", invoice_id=invoice["id"], amount=amount)

    for (invoice_id, when, amount, _), payment_ids in duplicate_payments.items():
        if len(payment_ids) > 1:
            ask("possible_duplicate", "payments", rows["payments"][payment_ids[0]],
                "同帳單同日同金額的紀錄是多筆實收，還是重複登錄？",
                invoice_id=invoice_id, payment_ids=payment_ids, date=str(when), amount=amount)

    covered_line_contracts = set()
    for item in rows["invoice_items"].values():
        invoice = rows["invoices"][item["invoice_id"]]
        item_contract = rows["contracts"].get(item["contract_id"])
        invoice_contract = rows["contracts"].get(invoice["contract_id"])
        different_contract = item_contract and invoice_contract and item_contract["id"] != invoice_contract["id"]
        same_package = different_contract and item_contract["package_id"] and (
            item_contract["package_id"] == invoice_contract["package_id"]
        )
        standalone_line = item["standalone_count_line"] == 1
        if standalone_line and amounts_by_invoice[invoice["id"]] >= money(invoice["amount"]):
            covered_line_contracts.add(item["contract_id"])
        if item["contract_link_uncertain"] or (different_contract and not (same_package or standalone_line)):
            ask("invoice_item_contract", "invoice_items", item, "這個帳單項目應歸哪份合約？",
                invoice_id=invoice["id"], period_start=item["period_start"], amount=item["amount"])

    for report in rows["reports"].values():
        when = day(report["payment_date"])
        for field in ("payment_date", "confirmed_at", "voided_at"):
            event = day(report[field])
            if event and event >= CUTOFF:
                later_activity.append({
                    "dataset": "reports", "id": report["id"], "date": str(event),
                    "event": field, "amount": report["amount"],
                })
        if when and not in_period(when):
            continue
        evidence = {"invoice_id": report["invoice_id"], "date": report["payment_date"],
                    "amount": report["amount"], "period": report["billing_period"]}
        if when is None:
            ask("undated_report", "reports", report, "主任能確認這筆繳費的實收日期嗎？", **evidence)
        if any(report[field] for field in ("contract_link_uncertain", "invoice_link_uncertain", "payment_link_uncertain")):
            ask("report_link", "reports", report, "這筆繳費對應哪位學生、合約或帳單？", **evidence)
        if report["status"] == "pending":
            ask("pending_receipt", "reports", report, "這筆回報是否收到款？實收日期、金額及帳單為何？", **evidence)
        if report["status"] != "confirmed":
            continue
        matches = list(positives_by_report[report["id"]])
        legacy = rows["payments"].get(report["payment_id"])
        if legacy and legacy["report_id"] is None and legacy["method"] in {"cash", "transfer"} and money(legacy["amount"]) > 0:
            matches.append(legacy)
        if not matches:
            ask("confirmed_without_payment", "reports", report, "已確認回報有對應的原始收款憑證嗎？", **evidence)
        elif len(matches) != 1 or any(
            p["invoice_id"] != report["invoice_id"] or (report["payment_id"] and p["id"] != report["payment_id"])
            or money(p["amount"]) != money(report["amount"]) or day(p["paid_at"]) != when for p in matches
        ):
            ask("receipt_payment_mismatch", "reports", report, "回報和收款的配對、日期或金額不一致，哪筆才正確？",
                payment_ids=[p["id"] for p in matches], reported_payment_id=report["payment_id"], **evidence)

    used_by_contract = defaultdict(Decimal)
    used_by_package = defaultdict(Decimal)
    for invoice in rows["invoices"].values():
        contract = rows["contracts"].get(invoice["contract_id"])
        stored = invoice["stored_paid_amount"]
        period_net = amounts_by_invoice[invoice["id"]]
        current_net = max(Decimal(0), current_amounts_by_invoice[invoice["id"]])
        if invoice["status"] != "void" and money(stored or 0) > 0 and period_net <= 0:
            ask("invoice_paid_amount_without_evidence", "invoices", invoice,
                "帳單有已收金額，但截止日前看不到淨入帳，是否是後續補登、沖銷或原帳漏記？",
                stored_paid_amount=stored, date=invoice["issue_date"], current_net=current_net)
        elif invoice["status"] != "void" and stored is not None and money(stored) != current_net:
            ask("invoice_paid_amount_mismatch", "invoices", invoice, "帳單已收與收款流水不一致，主任能確認實收金額嗎？",
                stored_paid_amount=stored, current_net=current_net, period_net=period_net, date=invoice["issue_date"])
        if not contract:
            continue
        applied = max(Decimal(0), period_net)
        used_by_contract[contract["id"]] += applied
        if contract["package_id"]:
            used_by_package[contract["package_id"]] += applied

    flagged_packages = set()
    for contract in rows["contracts"].values():
        package = contract["package_id"]
        if contract["package_link_uncertain"]:
            ask("package_link", "contracts", contract, "這份合約屬於哪一個共用方案？")
        if package in flagged_packages or contract["id"] in covered_line_contracts:
            continue
        if (contract["paid"] == 1 or contract["package_paid"] == 1) and not (
                used_by_package[package] if package else used_by_contract[contract["id"]]):
            if package:
                flagged_packages.add(package)
            ask("paid_flag_without_evidence", "contracts", contract,
                "這份已收合約或方案有截止日前的收款嗎？請確認是否屬於合約或方案任一帳單。",
                package_id=package, charge=contract["charge"])

    return {
        "scope": snapshot["scope"], "first_observed_billing_date": str(min(observed_dates)) if observed_dates else None,
        "monthly": dict(sorted(months.items())), "later_activity": later_activity, "questions": questions,
    }


if __name__ == "__main__":
    try:
        evidence = json.load(sys.stdin, parse_float=Decimal)
        print(json.dumps(build_review(evidence), ensure_ascii=False, indent=2, default=str))
    except (KeyError, TypeError, ValueError, ArithmeticError):
        sys.exit("Invalid or incomplete Daan billing snapshot.")
