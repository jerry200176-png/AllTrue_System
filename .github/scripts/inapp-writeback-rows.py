#!/usr/bin/env python3
"""Validate in-app write-back batches; the batch change note records policy and rationale.

Usage: inapp-writeback-rows.py {phase-a|followup} {preview|write}
Read rows_json only from $GITHUB_EVENT_PATH; step env is publicly logged.
Validate all rows before output; errors name fields/rules, never input values.
preview emits only IDs/hashes; write emits validated fields and base64 replies.
Content checks cover known patterns and allowed destinations, not all PII.
"""
import base64
import hashlib
import json
import os
import re
import sys
import unicodedata
from urllib.parse import unquote, unquote_plus, urlsplit

MAX_ROWS = 20
MAX_REPLY_CHARS = 600
BUG_ID = re.compile(r"[1-9][0-9]{0,9}")
DISPOSITION = re.compile(r"[a-z_]{1,64}")
STATUSES = ("triaged", "in_progress", "resolved", "closed")
REV = re.compile(r"[0-9a-f]{40}")
RUN_ID = re.compile(r"[0-9]{1,20}")
APP_ORIGIN = "https://daan.lifenet.com.tw"  # the production app; its own links are allowed in replies
ALLOWED_HOSTS = ("daan.lifenet.com.tw", "github.com")  # bare mentions of these hosts are fine
# Read the complete authority: quotes, parentheses, backslashes and Unicode must not
# hide a later @/foreign hostname. Keep the established ASCII path/query boundary
# so Chinese prose immediately after an issue URL is still treated as prose.
# Recognize parser-stripped controls in the scheme/separators as well, then reject
# them before parsing; never remove line breaks from the actual reply.
URL_HTTP = r"h[\t\r\n]*t[\t\r\n]*t[\t\r\n]*p(?:[\t\r\n]*s)?[\t\r\n]*:[\t\r\n]*(?:[\\/][\t\r\n]*)*"
URL_STARTS = re.compile(r"(?i:(?:" + URL_HTTP
                        + r"|[a-z][a-z0-9+.-]{1,15}://|www\.|(?<![\w:/\\])[\\/](?:[\t\r\n]*[\\/])+))")
# TAB/LF/CR are removed by URL parsers, so they must not truncate an authority
# before userinfo or another host. A new URL after a line break stays separate.
URL = re.compile(URL_STARTS.pattern + r"[^\s/?#<>]+(?:[\t\r\n]+(?!" + URL_STARTS.pattern + r")[^\s/?#<>]+)*"
                 r"(?:[/?#][^\s<>\x7f-\U0010ffff]*)?")
DOT = r"\s*(?:\.|\[\.\]|\(\.\)|\{\.\}|\[dot\]|\(dot\)|\s+dot\s+)\s*"  # "." and defanged forms
LABEL = r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"
DOMAIN = re.compile(rf"(?<![\w@-]){LABEL}(?:{DOT}{LABEL})*{DOT}(?:[a-z]{{2,63}}|xn--[a-z0-9-]+)(?![\w-])", re.I)
IP = re.compile(r"(?<![\d.])\d{1,3}(?:\s*(?:\.|\[\.\])\s*\d{1,3}){3}(?![\d.])")
SEP = r"[-\s.]*"  # separators people put between digits
REPLY_RULES = (
    ("tw_mobile", re.compile(rf"(?<!\d)(?:(?:\+|00)?886{SEP}(?:\(0\){SEP})?|0)9(?:{SEP}\d){{8}}(?!\d)")),
    ("tw_landline", re.compile(rf"(?<!\d)(?:(?:\+|00)?886{SEP}(?:\(0\){SEP})?\(?|\(?0)[2-8](?:[-\s.)]*\d){{7,8}}(?!\d)")),
    ("tw_national_id", re.compile(r"(?<![A-Za-z0-9])[A-Za-z][-\s]*[12](?:[-\s]*\d){8}(?!\d)")),
    ("email", re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+")),
    ("email", re.compile(r"[A-Za-z0-9._%+-]+\s*(?:\(at\)|\[at\]|\{at\}|\s+at\s+)\s*" + LABEL + DOT + LABEL, re.I)),
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


def normalize(text):
    """NFKC (full-width letters, digits, ＠ and ． become ASCII) and drop format characters (Cf, e.g.
    zero-width spaces) so look-alike or split values cannot slip past the rules."""
    return "".join(ch for ch in unicodedata.normalize("NFKC", text) if unicodedata.category(ch) != "Cf")


def foreign_domain(text):
    """True when the text names a host other than the allowed ones, or a bare IP address."""
    if IP.search(text):
        return True
    for match in DOMAIN.finditer(text):
        host = re.sub(DOT, ".", match.group(0), flags=re.I).lower()
        if host not in ALLOWED_HOSTS:
            return True
    return False


def allowed_url_authority(url):
    """Require the same canonical HTTPS authorities as before, never URL userinfo.

    urlsplit is deliberately stricter here than browser URL recovery: raw
    backslashes, ports, encoded hosts and noncanonical authorities fail closed.
    Path/query/fragment checks remain separate below.
    """
    if re.search(r"[\x00-\x20\x7f]", url):
        return False  # urlsplit strips some controls rather than reporting them
    try:
        parsed = urlsplit(url)
        return (parsed.scheme == "https" and parsed.hostname in ALLOWED_HOSTS
                and parsed.username is None and parsed.password is None
                and parsed.netloc == parsed.hostname and "\\" not in url)
    except ValueError:
        return False


def reply_urls(text):
    """Yield complete URLs, including nested starts, while excluding matching prose delimiters."""
    # Scan prefixes rather than consuming full URL spans, so an outer app URL
    # cannot hide a nested URL start or a preceding token hide an app link.
    for start in URL_STARTS.finditer(text):
        match = URL.match(text, start.start())
        if match is None:
            yield start.group(0)  # a malformed URL/network-path prefix is never evidence of an allowed URL
            continue
        url = match.group(0).rstrip(".,:;!?。")
        if match.start() > 0:
            closer = {"(": ")", "'": "'", '"': '"', "`": "`", "「": "」", "『": "』",
                      "“": "”", "‘": "’"}.get(text[match.start() - 1])
            if closer and url.endswith(closer):
                url = url[:-1]
        yield url


def decoded_views(text, decode):
    """Keep all normalized representations until stable, bounded by the reply cap.

    Percent decoding consumes input bytes; form '+' conversion can change once.
    URI component data uses unquote instead, preserving literal '+'.
    """
    views, text = [], normalize(text)
    for _ in range(MAX_REPLY_CHARS + 1):
        views.append(text)
        decoded = normalize(decode(text, errors="strict"))
        if decoded == text:
            return views
        text = decoded
    raise ValueError("decoding did not stabilize")


def reply_violation(reply, repo):
    """Name of the first content rule the reply breaks, or None. Never returns the matched text.
    Personal names are NOT detected: this is a pattern guard, not a PII guarantee."""
    allowed_issue = re.compile(r"https://github\.com/" + re.escape(repo) + r"/issues/[1-9][0-9]*")
    allowed_app = re.compile(re.escape(APP_ORIGIN) + r"(?:[/?#]\S*)?")
    try:
        views = decoded_views(reply, unquote_plus)
    except ValueError:  # malformed UTF-8 or exhausted decoding fails closed
        return "url_not_allowed"
    url_parts, bad_link = [], False
    pending, seen = [url for view in views for url in reply_urls(view)], set()
    while pending:
        url = pending.pop()
        if url in seen:
            continue
        seen.add(url)
        if not allowed_url_authority(url):
            bad_link = True
            continue
        if allowed_issue.fullmatch(url):
            continue
        if not allowed_app.fullmatch(url):
            bad_link = True
            continue
        # Split URI structure before decoding data: literal path '/' separates
        # segments, while encoded slashes, query padding and the fragment stay intact.
        parsed = urlsplit(url)
        components = parsed.path.split("/") + [part for field in parsed.query.split("&")
                                               for part in field.split("=", 1)] + [parsed.fragment]
        try:
            for component in components:
                for data in decoded_views(component, unquote):
                    # Parse nested URLs as URI structure, not one long credential.
                    # Queue even component-local views so literal '+' survives deep decoding.
                    for nested in reply_urls(data):
                        pending.append(nested)
                        if allowed_url_authority(nested) and (allowed_issue.fullmatch(nested) or allowed_app.fullmatch(nested)):
                            data = data.replace(nested, " ")
                    url_parts.append(data)
        except ValueError:
            return "url_not_allowed"
        # An allowed app link can still carry data or a redirect target in its path/query/fragment
        # (?email=..., /0912345678, ?to=evil.ai): scan the decoded components after the host.
        tail = normalize(unquote_plus(url[len(APP_ORIGIN):]))
        bad_link = bad_link or foreign_domain(tail)
        url_parts.append(re.sub(r"[/?&=#;]+", " ", tail))
    rest = " ".join(URL.sub(" ", view) for view in views)
    scanned = " ".join([rest] + url_parts)
    for name, pattern in REPLY_RULES:  # the specific data rules first, so the error names the leak
        if pattern.search(scanned):
            return name
    if bad_link or foreign_domain(rest):  # foreign, defanged or IP hosts, in or outside links
        return "url_not_allowed"
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
