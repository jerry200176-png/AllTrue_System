# Bug intake → production (wrapper)

## Freshness gate (mandatory)

Never triage from an old `bug-queue-dump` artifact. A queue dump is only valid
for 15 minutes and must be paired with the detail dump for the same bug ID.

Required sequence:

1. Run `bug-queue-dump.yml` (prefer `-f target_bug_id=<id>`) and download
   `meta.json` plus `open-bugs.json`.
2. Confirm the target ID is present exactly once with status `new`, `triaged`,
   or `in_progress`; do not infer the target from `max_id` or an old report.
3. Run `bug-detail-dump.yml` with that exact `bug_id`.
4. Confirm attachments, the reporter's complete bug history, all comments, and
   all status logs are present before reading the symptom or proposing a fix.
5. Run `scripts/validate-bug-intake-evidence.py`; a failure is a stop-the-line
   condition, not a reason to continue with best-effort triage.

## Triggering the dumps from a cloud/remote session (no SSH, no `gh`)

A Claude Code on the web / cloud-agent session has no SSH key or DB
credential mounted (checked: `~/.ssh`, `PI_SSH_*` env are empty by design —
see `CLAUDE.md` §G-012) and `workflow_dispatch` returns 403 for cloud agents.
The only working trigger is the `push: branches:[main]` path filter on each
**read-only** dump workflow:

1. Branch from latest `origin/main` with an allowed prefix —
   **`chore/…`** (not `ops/…`; `scripts/ci/branch-policy.mjs` only allows
   `chore|ci|fix`, and `Presubmit Checks` fails the PR otherwise, e.g. PR
   #1668 → had to be closed and reopened as PR #1669 with a `chore/` branch).
2. Append one line to the target request file:
   `operations/closeout/bug-queue-dump.request.md` (no parameters — always
   dumps the current open queue) and/or
   `operations/closeout/bug-detail-dump.request.md` (this one **is** read by
   the workflow: it parses the `bug_id: <n>` line near the top of the file
   via regex, so you must edit that line to the target ID, not just append a
   note — see the file's `Parse bug id` step). Append a
   `# kickoff <UTC ISO8601> — <why>` line either way for the audit trail.
3. Commit, push, open a PR into `main`, wait for CI green, merge.
4. The merge's push to `main` fires the workflow. Find the run with
   `mcp__github__actions_list` (`list_workflow_runs`, branch `main`, sorted
   by `created_at` — results are **not** guaranteed newest-first and
   `per_page`/branch filters may be silently ignored, so sort client-side and
   match on the merge commit SHA).
5. **Do not try to download the artifact zip directly** — its `blob.core.windows.net`
   URL is blocked by this session's egress policy (403 at the proxy, confirmed
   via `curl $HTTPS_PROXY/__agentproxy/status`) and that is a real policy
   denial, not something to retry or route around. Instead pull the job log
   with `mcp__github__get_job_logs` (`return_content: true`) — every dump
   step `echo json_encode(...)`s its full payload to stdout before uploading
   the artifact, so the same JSON is sitting in the log. The tool truncates
   to the tail, and the JSON line can fall outside that window once the
   upload/cleanup steps add their own noise — if the first fetch doesn't
   contain it, re-fetch with a larger `tail_lines` (2000+) rather than
   assuming the data isn't there. If the response itself exceeds the tool's
   token cap, it's saved to a file — `grep`/slice that file for the
   `{"ok":true...}` or `{"id":...,"status":...}` line rather than reading it
   whole.
6. Immediately (same 15-minute window) repeat steps 1–5 for the paired dump,
   then run `scripts/validate-bug-intake-evidence.py` against the two JSON
   payloads (reconstructed from the job logs is fine — the validator only
   checks field shape and timestamps, not artifact provenance).

This is slow (branch → PR → CI → merge → workflow run, twice, back to back)
by design — it is the price of never letting an AI agent hold Pi SSH
credentials directly. Do not shortcut it by SSHing manually, guessing at bug
IDs, or reusing a stale artifact.

## The write-back step has no cloud-agent path — plan for a human

`bug-phase-a-triage.yml` (posts the `new`→`triaged` transition + public
reply) and `bug-followup-comment.yml` (posts a follow-up without changing
status) are **`workflow_dispatch`-only, no `push` fallback** — unlike the
read-only dumps above. This is intentional: they write to production, so
they are not given the request-file trigger that lets a 403'd cloud agent
route around `workflow_dispatch`. A cloud session can do everything up to
opening the GitHub issue, but cannot flip the in-app status or post the
reply itself.

When this happens: hand the human (or any session with `workflow_dispatch`
access) the exact three inputs — `bug_id`, `github_issue_url`, and a
§3.8-compliant `public_reply` that contains the issue URL verbatim (the
workflow rejects the run otherwise) — rather than leaving Phase A half-done
or silently skipping the write-back. `bug-phase-c-allowlist.yml` is the one
exception with a `push` fallback, but it is explicitly scoped to bugs whose
fix is *already deployed* (Phase C) — never repurpose it for a fresh,
unverified Phase A triage.

The validator intentionally fails when the queue is stale, the IDs differ, the
bug is no longer open, or the reporter-history evidence is absent. This prevents
a previously resolved bug from being mistaken for the newest report.

Canonical long-form:

1. [`CHAT_BUG_SYSTEM.md`](../CHAT_BUG_SYSTEM.md) §3.6–§3.7  
2. [`GUIDE_BUG_CLOSURE_GATE.md`](../GUIDE_BUG_CLOSURE_GATE.md)  
3. [`AI_REGRESSION_LESSONS.md`](../AI_REGRESSION_LESSONS.md) R51/R53 + module index  

Success = Evidence Contract, not CI alone. Reporter-verify timeout = Evidence Contract §Reporter-verify timeout.
