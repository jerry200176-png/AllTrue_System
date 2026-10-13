#!/usr/bin/env python3
"""Governed request-file path for in-app bug write-backs (cloud agents, no workflow_dispatch).

`operations/closeout/bug-writeback.request.yml` holds `requests:`, a list of entries:
  request_id        unique slug, never reused (lowercase letters, digits, dashes)
  bug_id            in-app bug ID
  action            triage   -> new/triaged becomes triaged + public reply (Phase-A, never close_as_logged)
                    followup -> public comment only; expected_status must be triaged or in_progress
  github_issue_url  an issue in this repository; must appear in public_reply
  public_reply      §3.8 plain-language reply (docs/CHAT_BUG_SYSTEM.md)
  disposition       triage only, optional (default bug)
  expected_status   followup only, required

Entries are append-only: removing or changing an existing entry is refused, here and in CI.
Only request_ids absent from the last applied version of the file run (the workflow diffs against
its last successful run);
the server-side script also skips a reply that is already posted. `resolved`/`closed` never
pass through here: those stay on the evidence-gated Phase-C paths. The log carries request IDs,
bug IDs, actions and counts only: the repository is public.
"""
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Callable, Iterable

ACTIONS = {"triage": "phase-a", "followup": "followup"}
FOLLOWUP_STATUSES = {"triaged", "in_progress"}
REPLY_MIN_CHARS = 20
REPLY_MAX_CHARS = 1500
REQUEST_ID_RE = re.compile(r"^[a-z0-9][a-z0-9-]{2,63}$")
FIELDS = {"request_id", "bug_id", "action", "github_issue_url", "public_reply", "disposition", "expected_status"}
REMOTE_SCRIPT = Path(__file__).resolve().parent / "bug-writeback" / "run-remote.sh"
# Each remote call is bounded so one hung SSH/tinker call cannot let the 20-minute job timeout kill
# the run before later requests are attempted: MAX_NEW_PER_PUSH * REMOTE_TIMEOUT_S stays below it.
REMOTE_TIMEOUT_S = 180
MAX_NEW_PER_PUSH = 5

# §3.8 forbidden in a public reply: field/table names, code paths, SQL/tooling, internal shorthand.
# re.A: CJK text is not a word character, so "看StudentClass表" still matches.
JARGON = [
    ("field/table name", re.compile(r"\b(?:rate_unit|VoidedAt|ClassSession|StudentClass|StudentSingIn|schedules|bug_reports?)\b", re.A)),
    ("code path", re.compile(r"\b[A-Z][A-Za-z0-9]+(?:Controller|Service|Observer|Command|Middleware)\b|::", re.A)),
    ("SQL/tooling", re.compile(r"\b(?:SELECT|INSERT|UPDATE|DELETE|CI)\b|(?i:\b(?:Laravel|migration|tinker|artisan|deploy\.yml)\b)", re.A)),
    ("internal shorthand", re.compile(r"\bPhase\s*[ABC]\b|\bR\d{2,}\b|\bB1\b|\bP[0-3]\b|\bPR\s*#?\d+", re.A)),
]
# The request file is public git history. These catch identifiers; names cannot be detected,
# so a reply addresses the reporter as 您 and the T2 review of the request PR is the control.
PII = [
    ("email address or handle", re.compile(r"@", re.A)),
    ("phone number", re.compile(r"(?<!\d)09\d{2}[- ]?\d{3}[- ]?\d{3}(?!\d)")),
    ("long number (ID, phone, account)", re.compile(r"\d{6,}", re.A)),
]
SERVICE_PHP = Path(__file__).resolve().parents[1] / "backend/app/Services/BugReportService.php"


def logged_close_kinds(source: Path = SERVICE_PHP) -> set[str]:
    """Dispositions that must close as logged (F12); read from the service, never copied."""
    try:
        match = re.search(r"LOGGED_CLOSE_KINDS\s*=\s*\[([^\]]*)\]", source.read_text(encoding="utf-8"))
    except OSError as exc:
        raise RequestError("cannot read BugReportService::LOGGED_CLOSE_KINDS") from exc
    kinds = set(re.findall(r"'([a-z_]+)'", match.group(1))) if match else set()
    if not kinds:
        raise RequestError("cannot read BugReportService::LOGGED_CLOSE_KINDS")
    return kinds


class RequestError(ValueError):
    """Any problem with the request file; the workflow fails closed on it."""


def issue_url_pattern(repo: str) -> re.Pattern[str]:
    return re.compile(r"^https://github\.com/" + re.escape(repo) + r"/issues/[1-9][0-9]*$")


def reply_problems(reply: str, issue_url: str) -> list[str]:
    """§3.8 checks for a public reply. Returns problem labels, never the reply text."""
    problems = []
    text = reply.replace(issue_url, " ")
    # The minimum counts the words around the link; the maximum counts the whole reply.
    if len(text.strip()) < REPLY_MIN_CHARS or len(reply) > REPLY_MAX_CHARS:
        problems.append(f"length outside {REPLY_MIN_CHARS}-{REPLY_MAX_CHARS} chars")
    if issue_url not in reply:
        problems.append("does not contain github_issue_url")
    problems += [f"jargon: {label}" for label, pattern in JARGON if pattern.search(text)]
    problems += [f"personal data: {label}" for label, pattern in PII if pattern.search(text)]
    return problems


def load_requests(text: str | None) -> list[dict]:
    """Parse the request file; structure errors fail closed, an absent file is empty."""
    if not text or not text.strip():
        return []
    import yaml

    class Loader(yaml.SafeLoader):
        def construct_mapping(self, node, deep=False):
            # SafeLoader keeps the last of two equal keys; a duplicated bug_id/action/public_reply
            # must not silently retarget the write-back, so any duplicate key fails closed.
            if isinstance(node, yaml.MappingNode):
                self.flatten_mapping(node)
                seen = []
                for key_node, _ in node.value:
                    key = self.construct_object(key_node, deep=deep)
                    if key in seen:
                        label = key if key in FIELDS or key == "requests" else "(key)"
                        raise RequestError(f"duplicate mapping key {label}")
                    seen.append(key)
            return super().construct_mapping(node, deep=deep)

    def strict_int(loader, node):
        # YAML 1.1 reads 0512 as octal 330: only plain decimal integers may name a bug.
        value = loader.construct_scalar(node)
        if not re.fullmatch(r"-?(?:0|[1-9][0-9]*)", value):
            raise RequestError("integers must be plain decimal (no leading zero, 0x, 0o, _ or :)")
        return int(value)

    Loader.add_constructor("tag:yaml.org,2002:int", strict_int)
    try:
        doc = yaml.load(text, Loader=Loader)  # noqa: S506 - SafeLoader subclass
    except yaml.YAMLError as exc:
        raise RequestError("request file is not valid YAML") from exc
    if not isinstance(doc, dict) or set(doc) != {"requests"}:
        raise RequestError("request file must have exactly one top-level key: requests")
    entries = doc["requests"] or []
    if not isinstance(entries, list) or not all(isinstance(e, dict) for e in entries):
        raise RequestError("requests must be a list of mappings")
    seen: set[str] = set()
    for index, entry in enumerate(entries):
        rid = entry.get("request_id")
        if not isinstance(rid, str) or not REQUEST_ID_RE.match(rid):
            raise RequestError(f"entry {index}: request_id must match {REQUEST_ID_RE.pattern}")
        if rid in seen:
            raise RequestError(f"entry {index}: duplicate request_id {rid}")
        seen.add(rid)
    return entries


def validate_entry(entry: dict, repo: str) -> dict:
    """Return the normalized entry or raise RequestError naming only IDs and rule labels."""
    rid = entry["request_id"]
    unknown = set(entry) - FIELDS
    if unknown:
        raise RequestError(f"{rid}: unknown fields {sorted(unknown)}")
    bug_id = entry.get("bug_id")
    if isinstance(bug_id, bool) or not isinstance(bug_id, int) or bug_id <= 0:
        raise RequestError(f"{rid}: bug_id must be a positive integer")
    action = entry.get("action")
    if action not in ACTIONS:
        raise RequestError(f"{rid}: action must be one of {sorted(ACTIONS)}")
    url = entry.get("github_issue_url")
    if not isinstance(url, str) or not issue_url_pattern(repo).match(url):
        raise RequestError(f"{rid}: github_issue_url must be an issue in {repo}")
    reply = entry.get("public_reply")
    if not isinstance(reply, str):
        raise RequestError(f"{rid}: public_reply must be text")
    problems = reply_problems(reply, url)
    if problems:
        raise RequestError(f"{rid}: public_reply fails §3.8: {', '.join(problems)}")
    env = {"BUG_ID": str(bug_id), "GITHUB_ISSUE_URL": url, "PUBLIC_REPLY": reply}
    if action == "triage":
        if "expected_status" in entry:
            raise RequestError(f"{rid}: expected_status is only for followup")
        disposition = entry.get("disposition", "bug")
        if not isinstance(disposition, str) or not re.fullmatch(r"[a-z_]+", disposition):
            raise RequestError(f"{rid}: invalid disposition")
        if disposition in logged_close_kinds():
            raise RequestError(f"{rid}: disposition {disposition} must close as logged (dispatch-only)")
        env.update(DISPOSITION=disposition, CLOSE_AS_LOGGED="false")
    else:
        if "disposition" in entry:
            raise RequestError(f"{rid}: disposition is only for triage")
        status = entry.get("expected_status")
        if status not in FOLLOWUP_STATUSES:
            raise RequestError(f"{rid}: followup expected_status must be one of {sorted(FOLLOWUP_STATUSES)}")
        env.update(EXPECTED_STATUS=status, PRODUCTION_REVISION="", DEPLOY_RUN_ID="")
    return {"request_id": rid, "bug_id": bug_id, "action": action, "mode": ACTIONS[action], "env": env}


def validate_all(text: str | None, repo: str) -> list[dict]:
    """Validate every entry of a file (CI); the per-push batch limit does not apply to history."""
    return [validate_entry(e, repo) for e in load_requests(text)]


def plan(before_text: str | None, after_text: str | None, repo: str) -> list[dict]:
    """Validate every new request before any write; one bad entry stops the whole run."""
    before, after = load_requests(before_text), load_requests(after_text)
    after_by_id = {e["request_id"]: e for e in after}
    for e in before:  # append-only: a removed and later re-added ID would otherwise run again
        if after_by_id.get(e["request_id"]) != e:
            raise RequestError(f"{e['request_id']}: request entries are append-only (removed or changed)")
    before_ids = {e["request_id"] for e in before}
    new = [e for e in after if e["request_id"] not in before_ids]
    if len(new) > MAX_NEW_PER_PUSH:
        raise RequestError(f"{len(new)} new requests in one push; at most {MAX_NEW_PER_PUSH} (split the PR)")
    return [validate_entry(e, repo) for e in new]


Runner = Callable[[str, dict], int]


def run_remote(mode: str, env: dict) -> int:
    """Call the shared write-back script; its output may hold reply text, so it is discarded."""
    try:
        result = subprocess.run(
            ["bash", str(REMOTE_SCRIPT), mode],
            env={**os.environ, **env},
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=REMOTE_TIMEOUT_S,
        )
    except subprocess.TimeoutExpired:
        return 124  # reported as failed; later requests still run
    return result.returncode


def apply(items: Iterable[dict], runner: Runner = run_remote, log: Callable[[str], None] = print) -> int:
    """Attempt every request (each is independent), then fail if any failed."""
    done, failed = 0, []
    for item in items:
        code = runner(item["mode"], item["env"])
        status = "ok" if code == 0 else f"failed exit={code}"
        log(f"request_id={item['request_id']} bug_id={item['bug_id']} action={item['action']} {status}")
        if code == 0:
            done += 1
        else:
            failed.append(item["request_id"])
    if failed:
        raise RequestError(f"{len(failed)} failed ({', '.join(failed)}); {done} succeeded")
    return done


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--before", type=Path, help="previous version of the request file (absent = empty)")
    ap.add_argument("--after", type=Path, required=True)
    ap.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", ""))
    ap.add_argument("--apply", action="store_true", help="write to production (workflow only)")
    args = ap.parse_args(argv)
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", args.repo):
        print("invalid --repo")
        return 1
    read = lambda p: p.read_text(encoding="utf-8") if p and p.is_file() else None  # noqa: E731
    try:
        items = plan(read(args.before), read(args.after), args.repo)
        print(f"new requests: {len(items)} ({', '.join(i['request_id'] for i in items) or 'none'})")
        if os.environ.get("GITHUB_OUTPUT") and not args.apply:
            with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as out:
                out.write(f"count={len(items)}\n")
        if args.apply:
            print(f"processed: {apply(items)}")
    except RequestError as exc:
        print(f"refused: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
