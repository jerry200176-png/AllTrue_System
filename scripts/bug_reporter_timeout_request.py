#!/usr/bin/env python3
"""Resolve operations/closeout/bug-reporter-timeout.request.yml for bug-reporter-timeout.yml.

Cloud sessions cannot workflow_dispatch (Founder 2026-10-13), so a merged edit of the
request file is the trigger. This script decides, before any production access:

* whether the request runs at all: ``mode: idle`` never runs, a ``request_id`` that
  any earlier version of the file already carried never runs again (one run per id), and a
  re-run attempt of the push run fails without running (it would see the same commit as new);
* the workflow mode (``auto-dry-run`` or ``reviewed-apply``) and the reviewed ID list;
* the evidence SHA: the newest commit that changed the code the command runs. A
  reviewed apply requires production to contain it (the request commit itself is not
  deployed yet when the push run starts).

Output is ``key=value`` lines for $GITHUB_OUTPUT: IDs and counts only.
"""
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path

REQUEST_PATH = "operations/closeout/bug-reporter-timeout.request.yml"
# Code the remote command runs. Production must contain the newest change to any of them.
EVIDENCE_PATHS = (
    "backend/app/Console/Commands/CloseStaleResolvedBugsCommand.php",
    "backend/app/Services/BugReportService.php",
    "backend/app/Models/BugReport.php",
    "backend/app/Models/BugReportComment.php",
    "backend/app/Models/BugReportStatusLog.php",
)
MODES = ("idle", "auto-dry-run", "reviewed-apply")
_KEYS = ("request_id", "mode", "reviewed_ids")
_REQUEST_ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,79}")
_ID_LIST = re.compile(r"\[\s*(?:[1-9][0-9]*\s*(?:,\s*[1-9][0-9]*\s*)*)?\]")


class RequestError(ValueError):
    pass


def parse_request(text: str) -> dict[str, object]:
    """Strict subset of YAML: three ``key: value`` lines, comments and blank lines."""
    values: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.split(" #", 1)[0].rstrip() if not raw.lstrip().startswith("#") else ""
        if not line.strip():
            continue
        match = re.fullmatch(r"([a-z_]+):[ \t]*(.*)", line)
        if match is None or match.group(1) not in _KEYS:
            raise RequestError("each line must be one of request_id/mode/reviewed_ids")
        key, value = match.group(1), match.group(2).strip()
        if key in values:
            raise RequestError(f"duplicate key {key}")
        values[key] = value
    missing = [key for key in _KEYS if key not in values]
    if missing:
        raise RequestError("missing " + ", ".join(missing))
    request_id, mode, raw_ids = values["request_id"], values["mode"], values["reviewed_ids"]
    if _REQUEST_ID.fullmatch(request_id) is None:
        raise RequestError("request_id must be 1-80 of [A-Za-z0-9._-]")
    if mode not in MODES:
        raise RequestError("mode must be " + " | ".join(MODES))
    if _ID_LIST.fullmatch(raw_ids) is None:
        raise RequestError("reviewed_ids must be a [ID, ID] list of positive integers")
    ids = [int(part) for part in raw_ids.strip("[] ").split(",") if part.strip()]
    if len(ids) != len(set(ids)):
        raise RequestError("reviewed_ids must be unique")
    if mode == "reviewed-apply" and not ids:
        raise RequestError("reviewed-apply needs a non-empty reviewed_ids list")
    if mode != "reviewed-apply" and ids:
        raise RequestError("reviewed_ids is for reviewed-apply only")
    return {"request_id": request_id, "mode": mode, "reviewed_ids": ids}


def _git(repo: Path, *args: str) -> str:
    return subprocess.run(["git", "-C", str(repo), *args], check=True, capture_output=True, text=True).stdout


def prior_request_ids(repo: Path, head: str) -> set[str]:
    """request_ids carried by every version of the file older than the one at ``head``."""
    commits = _git(repo, "rev-list", head, "--", REQUEST_PATH).split()
    seen: set[str] = set()
    for commit in commits[1:]:  # commits[0] produced the current version
        shown = subprocess.run(
            ["git", "-C", str(repo), "show", f"{commit}:{REQUEST_PATH}"], capture_output=True, text=True
        )
        if shown.returncode != 0:
            continue  # the file was deleted in that commit
        for match in re.finditer(r"^request_id:[ \t]*([^\s#]+)", shown.stdout, re.M):
            seen.add(match.group(1))
    return seen


def evidence_sha(repo: Path, head: str) -> str:
    sha = _git(repo, "rev-list", "-1", head, "--", *EVIDENCE_PATHS).strip()
    if re.fullmatch(r"[0-9a-f]{40}", sha) is None:
        raise RequestError("no evidence commit for the timeout command")
    return sha


def resolve(repo: Path, head: str, run_attempt: int = 1) -> dict[str, str]:
    request = parse_request(_git(repo, "show", f"{head}:{REQUEST_PATH}"))
    request_id = str(request["request_id"])
    ids = [str(i) for i in request["reviewed_ids"]]  # type: ignore[union-attr]
    out = {"request_id": request_id, "mode": str(request["mode"]), "reviewed_ids": ",".join(ids), "reviewed_count": str(len(ids))}
    if request["mode"] == "idle":
        out.update(run="false", reason="idle")
    elif run_attempt != 1:
        # "Re-run jobs" replays the same push SHA, where the request still looks new: never
        # apply twice, and fail so a rerun never looks like a recovered request.
        raise RequestError("re-run attempts never replay a request; merge a new request_id instead")
    elif request_id in prior_request_ids(repo, head):
        out.update(run="false", reason="request_id_already_used")
    else:
        out.update(run="true", reason="new_request", evidence_sha=evidence_sha(repo, head))
    return out


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--repo", default=".")
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--run-attempt", default=os.environ.get("GITHUB_RUN_ATTEMPT", "1"))
    args = parser.parse_args(argv)
    try:
        if re.fullmatch(r"[1-9][0-9]*", str(args.run_attempt)) is None:
            raise RequestError("run attempt must be a positive integer")
        for key, value in resolve(Path(args.repo), args.head, int(args.run_attempt)).items():
            print(f"{key}={value}")
    except (RequestError, subprocess.CalledProcessError, OSError) as exc:
        print(f"::error::bug-reporter-timeout request: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
