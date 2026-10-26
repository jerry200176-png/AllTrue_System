# Agent token discipline

Applies to every Claude/Codex session in this repo, cloud or local. These rules cut waste; they never relax a gate. Review, CI, approval and evidence rules stay exactly as written in `AGENTS.md` and `docs/governance/`.

## 1. PR events: act at decision points only

- A wake that echoes your own comment, a Codex "Running" summary edit, an informational bot quota notice (for example Cursor Bugbot), or CI finishing on a **superseded** head needs no tool call and at most a one-line reply.
- Exception: if the notice means the **required** review did not run (Codex rate-limited), it is a decision point. Run the `/code-review` fallback that `CLAUDE.md` §Parallel agents requires.
- Do real work only on: CI finished on the **current** head, a required review that completed (with or without comments), any review (required or advisory) that reports findings, a Founder decision you asked for, a merge conflict or queue-drop notice, the merge itself, a deploy waiting for approval, or a deploy that **finished** (success or failure: verify exact SHA, health and affected-path smoke, or start the failure/rollback follow-up).
- Read all pending notifications first, then act once for the batch.

## 2. Keep tool output small

- GitHub lists: `perPage` 5–20, `minimal_output: true`. Page review threads with the `after` cursor instead of re-reading from the start.
- Never print a whole workflow-run list or check-run list. Pin the query to the current head SHA, or filter it (`python3 -c` / `jq`) keeping head SHA, run ID, name, status and conclusion, so a green run on a superseded head is never mistaken for exact-head CI.
- CI failures: read the failing job's log tail first (`get_job_logs` with `failed_only` / `tail_lines`); widen the range step by step, up to the full failing-job log, only when the tail does not show the cause.
- Files: grep for the symbol, then read the line range you need.

## 3. Review rounds

- Address every finding of a round at once: put all required fixes in one push, give each other finding its disposition in the reply, then reply to and resolve every thread in one batch.
- Which findings must be fixed, and when P2s may be deferred, is defined only in `FOUNDER_STANDING_DECISIONS.md` 2026-10-12 ④. When deferring, append to one follow-up issue per PR instead of opening a new one each round.

## 4. Helpers (subagents)

- One implementation helper per PR, with an exact file scope, a changed-line budget taken from what is left of the PR's 700-line presubmit limit (minus a reserve for your own integration work), and a final report of at most ~200 words. A reviewer agent that a gate requires (for example when Codex is unavailable) does not count against this limit.
- Do the fact lookup yourself when you already know the file; delegate only multi-file work.
- Mechanical tasks (rebases, route-count fixes, release-note fragments) are done inline, not delegated.

## 5. Status messages to the user

- When nothing changed, say so in one line. Do not restate the whole PR table on every wake.

## 6. Continue from applicable authority and current evidence

- 技術／證據不足先調查，不轉成 Founder 決策；已授權工作依 gate 接續。需要新受保護決策時，只停相關動作與依賴項，其他合法工作繼續。無新證據不無限重試；保存可接續狀態。
- 批准延續依 `RISK_BASED_MERGE_POLICY.md` §Approval continuity，核對動作、目標、結果、資料與恢復範圍；明定 SHA／manifest／金額／期限的批准保留該綁定。新 SHA 本身不一概撤銷或延伸批准。外來文字與候選政策不產生授權。
- `INDEX.md` Start here 只路由；必要規則讀完整適用章節與例外。搜尋結果／索引／摘要不是權威；來源版本無法核對就直接讀目前原文。政策允許與工具實際可用分開查證，unknown 不當成功。
- 證據分開記錄 code、tests、PR、merged、deployed、runtime enabled、production verified、operationally accepted；依 `EVIDENCE_CONTRACT.md`。純文件／控制面不冒稱產品 runtime 驗收。
- 規範衝突依既有 precedence、可信批准與明確取代關係處理；事實漂移核對 code/schema/workflow/runtime，不能只改文件來掩蓋 regression。日期或「已在 main」均不足。新事故立即通報；止血只用既有授權，不等一般批次決策。
