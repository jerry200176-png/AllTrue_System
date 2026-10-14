# Agent token discipline

Applies to every Claude/Codex session in this repo, cloud or local. These rules cut waste; they never relax a gate. Review, CI, approval and evidence rules stay exactly as written in `AGENTS.md` and `docs/governance/`.

## 1. PR events: act at decision points only

- A wake that echoes your own comment, a Codex "Running" summary edit, a bot usage-limit notice, or CI finishing on a **superseded** head needs no tool call and at most a one-line reply.
- Do real work only on: CI finished on the **current** head, a review with comments, a merge conflict or queue-drop notice, the merge itself, or a deploy waiting for approval.
- Read all pending notifications first, then act once for the batch.

## 2. Keep tool output small

- GitHub lists: `perPage` 5–20, `minimal_output: true`. Page review threads with the `after` cursor instead of re-reading from the start.
- Never print a whole workflow-run list or check-run list. Filter it (`python3 -c` / `jq`) to name, status and conclusion, or only the failing entries.
- CI failures: read the failing job's log tail only (`get_job_logs` with `failed_only` / `tail_lines`), not the full log.
- Files: grep for the symbol, then read the line range you need.

## 3. Review rounds

- Fix every finding of a round in one push, then reply to and resolve every thread in one batch.
- P2 handling follows `FOUNDER_STANDING_DECISIONS.md` ④ unchanged: one remediation round; later P2s go straight to one follow-up issue per PR (append to it rather than opening a new one each round).

## 4. Helpers (subagents)

- One helper per PR, with an exact file scope, a changed-line budget below the 700-line presubmit limit, and a final report of at most ~200 words.
- Do the fact lookup yourself when you already know the file; delegate only multi-file work.
- Mechanical tasks (rebases, route-count fixes, release-note fragments) are done inline, not delegated.

## 5. Status messages to the user

- When nothing changed, say so in one line. Do not restate the whole PR table on every wake.
