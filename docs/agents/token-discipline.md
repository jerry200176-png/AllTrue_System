# Agent token discipline

Applies to every Claude/Codex session in this repo, cloud or local. These rules cut waste; they never relax a gate. Review, CI, approval and evidence rules stay exactly as written in `AGENTS.md` and `docs/governance/`.

## 1. PR events: act at decision points only

- A wake that echoes your own comment, a Codex "Running" summary edit, an informational bot quota notice (for example Cursor Bugbot), or CI finishing on a **superseded** head needs no tool call and at most a one-line reply.
- Exception: if the notice means the **required** review did not run (Codex rate-limited), it is a decision point. Run the `/code-review` fallback that `CLAUDE.md` §Parallel agents requires.
- Do real work only on: CI finished on the **current** head, a required review that completed (with or without comments), a merge conflict or queue-drop notice, the merge itself, a deploy waiting for approval, or a deploy that **finished** (success or failure: verify exact SHA, health and affected-path smoke, or start the failure/rollback follow-up).
- Read all pending notifications first, then act once for the batch.

## 2. Keep tool output small

- GitHub lists: `perPage` 5–20, `minimal_output: true`. Page review threads with the `after` cursor instead of re-reading from the start.
- Never print a whole workflow-run list or check-run list. Pin the query to the current head SHA, or filter it (`python3 -c` / `jq`) keeping head SHA, run ID, name, status and conclusion, so a green run on a superseded head is never mistaken for exact-head CI.
- CI failures: read the failing job's log tail only (`get_job_logs` with `failed_only` / `tail_lines`), not the full log.
- Files: grep for the symbol, then read the line range you need.

## 3. Review rounds

- Address every finding of a round at once: put all required fixes in one push, give each other finding its disposition in the reply, then reply to and resolve every thread in one batch.
- P2 handling follows `FOUNDER_STANDING_DECISIONS.md` 2026-10-12 ④ unchanged: one remediation round; later P2s go to one follow-up issue per PR (append to it rather than opening a new one each round), **except** a regression this PR introduced, which is fixed before merge whatever its severity or round.

## 4. Helpers (subagents)

- One helper per PR, with an exact file scope, a changed-line budget below the 700-line presubmit limit, and a final report of at most ~200 words.
- Do the fact lookup yourself when you already know the file; delegate only multi-file work.
- Mechanical tasks (rebases, route-count fixes, release-note fragments) are done inline, not delegated.

## 5. Status messages to the user

- When nothing changed, say so in one line. Do not restate the whole PR table on every wake.
