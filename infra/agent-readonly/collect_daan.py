"""Build a private Daan tuition review from the existing AllTrue read API."""

import argparse
from collections import defaultdict
from datetime import UTC, datetime
from decimal import Decimal
import json
import os
from pathlib import Path
import re
import runpy
import tempfile
import time
from urllib.error import HTTPError, URLError

from query_alltrue import Reader

WORKSHEET = runpy.run_path(str(Path(__file__).with_name("daan-tuition-review.py")))
CUTOFF = WORKSHEET["CUTOFF"]
CAMPUS = 15


def pages(fetch, table, key, fields, *, eq=(), inside=None):
    cursor = 0
    fields = {key, *fields.split(), *(name for name, _ in eq)}
    filters = [(f"eq[{name}]", value) for name, value in eq]
    if inside:
        column, values = inside
        filters.append((f"in[{column}]", ",".join(map(str, sorted(values)))))
    while True:
        params = filters + [(f"gte[{key}]", cursor + 1), ("order", key), ("limit", 200)]
        result = fetch(table, params)
        rows = result["rows"]
        if result["table"] != table or not fields.issubset(result["columns"]) or result["count"] != len(rows) or len(rows) > 200:
            raise ValueError(f"Incomplete response for {table}.")
        if not rows:
            return
        for entry in rows:
            row = {field: entry[field] for field in fields}
            record = row[key]
            if type(record) is not int or record <= cursor:
                raise ValueError(f"Invalid cursor order for {table}.")
            if any(row[name] != value for name, value in eq) or (inside and row[column] not in values):
                raise ValueError(f"Unexpected ownership in {table}.")
            cursor = record
            yield row


def by_owner(fetch, table, key, fields, column, owners, *, eq=(), filters=()):
    def bounded_fetch(name, params):
        return fetch(name, params + list(filters))

    ids = sorted(owners)
    for start in range(0, len(ids), 200):
        yield from pages(bounded_fetch, table, key, f"{fields} {column}", eq=eq, inside=(column, set(ids[start:start + 200])))


def date_before(value):
    parsed = WORKSHEET["day"](value)
    return parsed is not None and parsed < CUTOFF


def period(value):
    return value if value and re.fullmatch(r"[0-9]{4}-(0[1-9]|1[0-2])", value) else None


def historical(row, date_field):
    billing_period = period(row["billing_period"])
    return date_before(row[date_field]) or bool(billing_period and billing_period < CUTOFF.strftime("%Y-%m")) or (
        not row[date_field] and (not row["created_at"] or date_before(row["created_at"]))
    )


def owned(records, key, owner, field):
    row = records.get(key)
    return row if row and row[field] == owner else None


def normalized(record, **fields):
    return {"id": record, "campus_id": CAMPUS, **fields}


def payment_moves(fetch, raw_reports, raw_invoices, raw_payments):
    moves = {}
    suspected = set()
    grouped = defaultdict(list)
    for payment in raw_payments.values():
        grouped[payment["payment_report_id"]].append(payment)
    scope = {"from": set(raw_reports), "to": set(raw_reports)}
    fields = "from_report_id to_report_id from_invoice_id to_invoice_id from_student_id to_student_id amount created_at"
    for side, reports in scope.items():
        for move in by_owner(fetch, "payment_report_reassignments", "id", fields,
                             f"{side}_report_id", reports, eq=(("campus_id", CAMPUS),)):
            report = raw_reports[move[f"{side}_report_id"]]
            owner = move[f"{side}_student_id"]
            invoice = move[f"{side}_invoice_id"]
            if not owned(raw_invoices, invoice, owner, "StudentID") or report["StudentID"] != owner:
                continue
            amount = Decimal(str(move["amount"]))
            candidates = [p for p in grouped[report["id"]] if p["InvoiceID"] == invoice
                          and abs(Decimal(str(p["Amount"]))) == amount]
            if side == "to":
                matches = [p for p in candidates if p["id"] == report["payment_id"] and p["Method"] in {"cash", "transfer"}
                           and Decimal(str(p["Amount"])) > 0]
            else:
                candidates = [p for p in candidates if p["Method"] == "void" and Decimal(str(p["Amount"])) < 0]
                # The generic API omits notes. Require the audit's date and a single source void.
                event_day = WORKSHEET["day"](move["created_at"])
                matches = [p for p in candidates if WORKSHEET["day"](p["PaidAt"]) == event_day
                           and WORKSHEET["day"](p["created_at"]) == event_day]
            if len(matches) == 1 and move["created_at"]:
                moves[matches[0]["id"]] = move["created_at"]
            else:
                suspected.update(p["id"] for p in candidates)
    return moves, suspected


def collect(fetch, read_at=None):
    tables = set(fetch()["tables"])
    required = {"Campus", "Student", "StudentClass", "course_packages", "Invoice", "InvoiceItem", "Payment",
                "payment_reports", "payment_report_reassignments"}
    if not required.issubset(tables):
        raise ValueError("Required AllTrue billing tables are unavailable.")
    campus = list(pages(fetch, "Campus", "id", "is_test", eq=(("id", CAMPUS),)))
    if len(campus) != 1 or campus[0]["is_test"] not in (False, 0):
        raise ValueError("Daan production campus did not verify.")
    students = {r["id"] for r in pages(fetch, "Student", "id", "CampusID", eq=(("CampusID", CAMPUS),))}
    raw_contracts = {r["ID"]: r for r in by_owner(
        fetch, "StudentClass", "ID", "StartDate Paid Charge PackageID ScheduleMode closed_reason", "StudentID", students
    ) if not r["StartDate"] or date_before(r["StartDate"])}
    packages = {r["id"]: r for r in by_owner(fetch, "course_packages", "id", "paid", "student_id", students,
                                             eq=(("campus_id", CAMPUS),))}
    contracts = {}
    standalone_count = set()
    for key, r in raw_contracts.items():
        package = owned(packages, r["PackageID"], r["StudentID"], "student_id")
        if r["ScheduleMode"] in (None, "count") and not r["PackageID"] and r["closed_reason"] not in ("waived", "refunded"):
            standalone_count.add(key)
        contracts[key] = normalized(key, charge=r["Charge"], paid=r["Paid"],
                                    package_id=package["id"] if package else None, package_paid=package["paid"] if package else None,
                                    package_link_uncertain=bool(r["PackageID"] and not package))

    raw_invoices = {r["id"]: r for r in by_owner(
        fetch, "Invoice", "id", "IssueDate billing_period created_at StudentClassID TotalAmount PaidAmount Status", "StudentID", students
    )}
    later_invoices = {key for key, row in raw_invoices.items() if not historical(row, "IssueDate")}
    early_activity = {p["InvoiceID"] for p in by_owner(
        fetch, "Payment", "id", "PaidAt", "InvoiceID", later_invoices,
        filters=(("lte[PaidAt]", "2026-10-31 23:59:59"),)
    ) if date_before(p["PaidAt"])}
    raw_invoices = {key: r for key, r in raw_invoices.items() if key not in later_invoices or key in early_activity}
    invoices = {}
    for key, r in raw_invoices.items():
        contract = owned(raw_contracts, r["StudentClassID"], r["StudentID"], "StudentID")
        invoices[key] = normalized(key, contract_id=contract["ID"] if contract else None,
                                   contract_link_uncertain=bool(r["StudentClassID"] and not contract),
                                   issue_date=r["IssueDate"], billing_period=period(r["billing_period"]),
                                   amount=r["TotalAmount"], stored_paid_amount=r["PaidAmount"], status=r["Status"])

    items = {}
    for r in by_owner(fetch, "InvoiceItem", "id", "StudentClassID Amount PeriodStart", "InvoiceID", invoices):
        invoice = invoices[r["InvoiceID"]]
        contract = owned(raw_contracts, r["StudentClassID"], raw_invoices[r["InvoiceID"]]["StudentID"], "StudentID")
        key = contract["ID"] if contract else None
        items[r["id"]] = normalized(r["id"], invoice_id=invoice["id"], contract_id=key, amount=r["Amount"],
                                    period_start=r["PeriodStart"], contract_link_uncertain=bool(r["StudentClassID"] and not contract),
                                    standalone_count_line=bool(key in standalone_count and key != invoice["contract_id"]
                                                               and invoice["status"] != "void" and invoice["amount"] > 0 and r["Amount"] > 0))

    raw_payments = {r["id"]: r for r in by_owner(
        fetch, "Payment", "id", "payment_report_id Amount PaidAt created_at Method", "InvoiceID", invoices
    )}
    raw_reports = {r["id"]: r for r in by_owner(
        fetch, "payment_reports", "id", "StudentClassID InvoiceID payment_id billing_period payment_date reported_amount created_at confirmed_at voided_at status",
        "StudentID", students
    ) if historical(r, "payment_date") or r["InvoiceID"] in invoices or r["payment_id"] in raw_payments}
    moves, suspected = payment_moves(fetch, raw_reports, raw_invoices, raw_payments)
    payments = {}
    for key, r in raw_payments.items():
        report = owned(raw_reports, r["payment_report_id"], raw_invoices[r["InvoiceID"]]["StudentID"], "StudentID")
        payments[key] = normalized(key, invoice_id=r["InvoiceID"], report_id=report["id"] if report else None,
                                    report_link_uncertain=bool(r["payment_report_id"] and not report),
                                    amount=r["Amount"], paid_at=r["PaidAt"], method=r["Method"] or "other",
                                    receipt_moved_at=moves.get(key), receipt_move_uncertain=key in suspected and key not in moves)

    reports = {}
    for key, r in raw_reports.items():
        owner = r["StudentID"]
        contract = owned(raw_contracts, r["StudentClassID"], owner, "StudentID")
        invoice = owned(raw_invoices, r["InvoiceID"], owner, "StudentID")
        payment = raw_payments.get(r["payment_id"])
        payment_scoped = payment and owned(raw_invoices, payment["InvoiceID"], owner, "StudentID")
        reports[key] = normalized(
            key, contract_id=contract["ID"] if contract else None, invoice_id=invoice["id"] if invoice else None,
            payment_id=payment["id"] if payment_scoped else None, contract_link_uncertain=bool(r["StudentClassID"] and not contract),
            invoice_link_uncertain=bool(r["InvoiceID"] and not invoice), payment_link_uncertain=bool(r["payment_id"] and not payment_scoped),
            billing_period=period(r["billing_period"]), payment_date=r["payment_date"], amount=r["reported_amount"],
            confirmed_at=r["confirmed_at"], voided_at=r["voided_at"], status=r["status"]
        )

    datasets = dict(zip(WORKSHEET["DATASETS"], (contracts, invoices, items, payments, reports)))
    scope = {"campus_id": CAMPUS, "campus_code": "daan", "cutoff_exclusive": str(CUTOFF),
             "read_at_utc": read_at or datetime.now(UTC).isoformat(), "source": "alltrue-triage-db", "consistency": "paginated_live_read"}
    evidence = {"scope": scope, "pages": {}}
    for name, records in datasets.items():
        rows = [records[key] for key in sorted(records)]
        evidence["pages"][name] = [rows[i:i + 200] for i in range(0, len(rows), 200)] + [[]]
    return WORKSHEET["build_review"](evidence)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--gateway", default=os.environ.get("ALLTRUE_READ_GATEWAY_URL"))
    parser.add_argument("--out", required=True, type=Path, help="New output file in a private directory.")
    args = parser.parse_args()
    if not args.gateway:
        parser.exit(2, "No approved relay configured. Set ALLTRUE_READ_GATEWAY_URL.\n")
    try:
        if args.out.exists() or args.out.parent.stat().st_mode & 0o077:
            parser.error("Choose a new output file in a private directory.")
        reader = Reader(args.gateway)
        next_read = time.monotonic()

        def fetch(table=None, params=()):
            nonlocal next_read
            time.sleep(max(0, next_read - time.monotonic()))
            next_read = time.monotonic() + 1.1
            return reader.fetch(table, params)

        review = collect(fetch)
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=args.out.parent) as output:
            json.dump(review, output, ensure_ascii=False, indent=2, default=str)
            output.write("\n")
            output.flush()
            os.link(output.name, args.out)
    except HTTPError as error:
        parser.exit(1, f"AllTrue read request failed (HTTP {error.code}).\n")
    except (URLError, OSError, KeyError, TypeError, ValueError, ArithmeticError):
        parser.exit(1, "Daan review could not verify the live data or save the report.\n")


if __name__ == "__main__":
    main()
