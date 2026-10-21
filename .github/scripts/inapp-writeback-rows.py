#!/usr/bin/env python3
"""Validate the rows_json batch of an in-app write-back workflow (Founder 2026-10-21).

Usage: inapp-writeback-rows.py {phase-a|followup} {preview|write}

Reads the exact `rows_json` string from $GITHUB_EVENT_PATH (never from env: GitHub prints step env
in the public log header). Every row must pass before anything is printed: one bad row fails the
whole run (all-or-nothing). Errors name the row index and field, never a value (public repo).

preview: appends a bug_id / issue / status / close / len / sha256 table to $GITHUB_STEP_SUMMARY
         (never the reply text) and writes ID-only job outputs to $GITHUB_OUTPUT.
Reply content guard (Founder 2026-10-21): the session that writes these replies also reads untrusted
in-app reports and can post publicly (the "lethal trifecta": private data + untrusted content + an
outbound channel). A reply carrying a phone number, national ID, email, credential-like string, or a
link other than this repo's issues / the production app is rejected (OWASP LLM02 sensitive
information disclosure, LLM01 prompt-injection exfiltration). Errors name the row and rule only.

write:   prints one space-separated line per row for the SSH writer; every token is validated to a
         safe charset and the reply is base64, so no reply byte reaches a shell unencoded.
"""
import base64
import hashlib
import json
import os
import re
import sys

MAX_ROWS = 20
MAX_REPLY_CHARS = 600
BUG_ID = re.compile(r"[1-9][0-9]{0,9}")
DISPOSITION = re.compile(r"[a-z_]{1,64}")
STATUSES = ("triaged", "in_progress", "resolved", "closed")
REV = re.compile(r"[0-9a-f]{40}")
RUN_ID = re.compile(r"[0-9]{1,20}")
APP_ORIGIN = "https://daan.lifenet.com.tw"  # the production app; its own links are allowed in replies
# URLs are ASCII; stopping at the first non-ASCII character keeps "<url>請查看" from swallowing the text.
URL = re.compile(r"(?:https?://|www\.)[A-Za-z0-9\-._~:/?#\[\]@!$&*+,;=%]+", re.I)
BARE_DOMAIN = re.compile(r"(?<![\w.@-])[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|tw|me|co|app|dev|xyz|ly|gg|ru|cn|info|biz)(?![\w-])", re.I)
REPLY_RULES = (
    ("tw_mobile", re.compile(r"(?<!\d)(?:\+?886[-\s]?9|09)\d{2}[-\s]?\d{3}[-\s]?\d{3}(?!\d)")),
    ("tw_landline", re.compile(r"(?<!\d)(?:\(?0[2-8]\d?\)?|\+?886[-\s]?[2-8]\d?)[-\s]?\d{3,4}[-\s]?\d{4}(?!\d)")),
    ("tw_national_id", re.compile(r"(?<![A-Za-z0-9])[A-Za-z][12]\d{8}(?!\d)")),
    ("email", re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")),
    ("secret_token", re.compile(r"(?<![A-Za-z0-9])(?:ghp_|gho_|ghs_|ghu_|github_pat_|sk-|xox[bpas]-|AKIA)[A-Za-z0-9_-]{8,}")),
    ("private_key", re.compile(r"BEGIN [A-Z ]*KEY", re.I)),
    ("long_hex", re.compile(r"(?<![0-9A-Fa-f])[0-9A-Fa-f]{32,}(?![0-9A-Fa-f])")),
    ("long_base64", re.compile(r"(?=[A-Za-z0-9+/=_-]*\d)(?=[A-Za-z0-9+/=_-]*[A-Za-z])[A-Za-z0-9+/=_-]{32,}")),
)
FIELDS = {
    "phase-a": {"bug_id", "github_issue_url", "public_reply", "disposition", "close_as_logged"},
    "followup": {"bug_id", "expected_status", "github_issue_url", "public_reply", "production_revision", "deploy_run_id"},
}


def fail(message):
    sys.exit(f"invalid rows_json: {message}")


def text(row, key, where, default=None):
    value = row.get(key, default)
    if value is None or isinstance(value, bool) or not isinstance(value, (str, int)):
        fail(f"{where}: invalid {key}")
    return str(value)


def reply_violation(reply, repo):
    """Name of the first content rule the reply breaks, or None. Never returns the matched text."""
    allowed_issue = re.compile(r"https://github\.com/" + re.escape(repo) + r"/issues/[1-9][0-9]*")
    allowed_app = re.compile(re.escape(APP_ORIGIN) + r"(?:[/?#]\S*)?")
    for match in URL.finditer(reply):
        url = match.group(0).rstrip(".,:;!?")
        if not (allowed_issue.fullmatch(url) or allowed_app.fullmatch(url)):
            return "url_not_allowed"
    rest = URL.sub(" ", reply)  # allowed links are checked; scan the remaining text
    if BARE_DOMAIN.search(rest):
        return "url_not_allowed"
    for name, pattern in REPLY_RULES:
        if pattern.search(rest):
            return name
    return None


def validate(kind, raw, repo):
    try:
        rows = json.loads(raw)
    except (TypeError, ValueError):
        fail("not a JSON array")  # never echo the decoder message: it can quote the input
    if not isinstance(rows, list) or not 1 <= len(rows) <= MAX_ROWS:
        fail(f"must be a JSON array of 1-{MAX_ROWS} rows")
    issue_re = re.compile(r"https://github\.com/" + re.escape(repo) + r"/issues/[1-9][0-9]*")
    out, seen = [], set()
    for index, row in enumerate(rows, 1):
        where = f"row {index}"
        if not isinstance(row, dict):
            fail(f"{where}: must be an object")
        if set(row) - FIELDS[kind]:
            fail(f"{where}: unknown field")
        bug_id = text(row, "bug_id", where)
        if not BUG_ID.fullmatch(bug_id):
            fail(f"{where}: invalid bug_id")
        if bug_id in seen:
            fail(f"{where}: duplicate bug_id")
        seen.add(bug_id)
        issue = row.get("github_issue_url")
        if not isinstance(issue, str) or not issue_re.fullmatch(issue):
            fail(f"{where}: github_issue_url must be an issue in this repository")
        reply = row.get("public_reply")
        if not isinstance(reply, str) or not reply.strip() or "\x00" in reply:
            fail(f"{where}: invalid public_reply")
        if len(reply) > MAX_REPLY_CHARS:
            fail(f"{where}: public_reply longer than {MAX_REPLY_CHARS} characters")
        if issue not in reply:
            fail(f"{where}: public reply must contain github_issue_url")
        rule = reply_violation(reply, repo)
        if rule:
            fail(f"{where}: public_reply rejected by content rule {rule}")
        try:
            reply_bytes = reply.encode("utf-8")
        except UnicodeEncodeError:
            fail(f"{where}: public_reply is not valid UTF-8")
        item = {"bug_id": bug_id, "github_issue_url": issue, "len": len(reply),
                "sha256": hashlib.sha256(reply_bytes).hexdigest(),
                "reply_b64": base64.b64encode(reply_bytes).decode("ascii")}
        if kind == "phase-a":
            disposition = text(row, "disposition", where, "bug")
            if not DISPOSITION.fullmatch(disposition):
                fail(f"{where}: invalid disposition syntax")
            close = row.get("close_as_logged", False)
            if not isinstance(close, bool):
                fail(f"{where}: close_as_logged must be a JSON boolean")
            item.update(disposition=disposition, close_as_logged="true" if close else "false")
        else:
            status = text(row, "expected_status", where)
            if status not in STATUSES:
                fail(f"{where}: invalid expected_status")
            rev = text(row, "production_revision", where, "")
            run_id = text(row, "deploy_run_id", where, "")
            if rev and not REV.fullmatch(rev):
                fail(f"{where}: invalid production_revision")
            if run_id and not RUN_ID.fullmatch(run_id):
                fail(f"{where}: invalid deploy_run_id")
            if status == "closed" and not (rev and run_id):
                fail(f"{where}: closed follow-up needs production_revision and deploy_run_id")
            item.update(expected_status=status, production_revision=rev, deploy_run_id=run_id)
        out.append(item)
    return out


def main():
    if len(sys.argv) != 3 or sys.argv[1] not in FIELDS or sys.argv[2] not in ("preview", "write"):
        sys.exit("usage: inapp-writeback-rows.py {phase-a|followup} {preview|write}")
    kind, mode = sys.argv[1:]
    if os.environ.get("GITHUB_REF") != "refs/heads/main":
        sys.exit("in-app write-back runs only on refs/heads/main")
    with open(os.environ["GITHUB_EVENT_PATH"], encoding="utf-8") as fh:
        raw = (json.load(fh).get("inputs") or {}).get("rows_json")
    rows = validate(kind, raw, os.environ["GITHUB_REPOSITORY"])
    if mode == "write":
        for r in rows:
            if kind == "phase-a":
                fields = [r["bug_id"], r["github_issue_url"], r["disposition"], r["close_as_logged"]]
            else:  # "-" keeps empty optional fields from collapsing in `read`
                fields = [r["bug_id"], r["expected_status"], r["github_issue_url"],
                          r["production_revision"] or "-", r["deploy_run_id"] or "-"]
            print(" ".join(fields + [r["reply_b64"]]))
        return
    head = "close_as_logged" if kind == "phase-a" else "expected_status"
    lines = [f"## In-app write batch requested: {len(rows)} row(s) (not yet applied; the write job reports the result)", "",
             f"| # | bug_id | github_issue_url | {'disposition' if kind == 'phase-a' else 'production_revision'} | {head} | len | sha256 |",
             "|---|---|---|---|---|---|---|"]
    for i, r in enumerate(rows, 1):
        mid = r["disposition"] if kind == "phase-a" else (r["production_revision"] or "-")
        state = r["close_as_logged"] if kind == "phase-a" else r["expected_status"]
        lines.append(f"| {i} | {r['bug_id']} | {r['github_issue_url']} | {mid} | {state} | {r['len']} | {r['sha256']} |")
    lines += ["", "Reply text is intentionally not shown (public repo); sha256 is over the exact UTF-8 reply."]
    with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")
    if kind == "phase-a":
        logged = [[r["bug_id"], r["github_issue_url"]] for r in rows if r["close_as_logged"] == "true"]
        output = {"logged_issues": json.dumps(logged, separators=(",", ":"))}
    else:
        shipped = [[r["bug_id"], r["github_issue_url"], r["production_revision"]] for r in rows
                   if r["expected_status"] == "closed"]
        output = {"shipped": json.dumps(shipped, separators=(",", ":"))}
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as fh:
        for key, value in output.items():
            fh.write(f"{key}={value}\n")


if __name__ == "__main__":
    main()
