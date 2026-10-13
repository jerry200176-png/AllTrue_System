# Bug intake → production (wrapper)

## 每個階段：誰／哪個 workflow／觸發方式／cloud session 能否自行完成

本表是 in-app 回報各階段自動化的**唯一權威對照**；其他文件只連到這裡。真相以 `.github/workflows/` 為準，有出入時改本表。

| 階段 | Workflow（做什麼） | 觸發 | Cloud session（無 SSH、`workflow_dispatch` 403） |
|------|--------------------|------|------------------------------------------------|
| 收件建單 + 收到回覆 | `bug-auto-intake.yml`：每筆 `new` 開／重用 IDs-only GitHub issue（SourceRef 去重）、加 `area:<類別>`、同類提醒、留固定「收到，我們正在查」回覆（Founder 6A）。狀態**維持 `new`**，等 Phase A | schedule 每小時 :23（dispatch 只做 dry-run） | 自動，不需動作 |
| 撈佇列 | `bug-queue-dump.yml`：log／明文 artifact 只有 ID／狀態／數量；全文在加密 `*.full.tar.gz.enc`（#3605）；附 in-app ↔ issue reconcile step summary | push request-file `operations/closeout/bug-queue-dump.request.md`；或 dispatch | 可觸發；只看得到 ID／數量，全文要本機解密 |
| 撈單筆 | `bug-detail-dump.yml`（同上加密規則） | push request-file `bug-detail-dump.request.md`（改檔內 `bug_id:` 行）；或 dispatch | 同上 |
| Phase A 分診回寫 | `bug-phase-a-triage.yml`：`new`→`triaged` + 公開回覆；建議類 `close_as_logged` | **dispatch-only** | 不行：交出 `bug_id`、`github_issue_url`、含 issue URL 的 §3.8 `public_reply` 給有 dispatch 權限的人 |
| 進度補充／建議上線通知 | `bug-followup-comment.yml`：只留言、不改狀態 | **dispatch-only** | 不行：同上交出輸入 |
| Phase C 上線後回寫 | `bug-phase-c-allowlist.yml`：已上線修復 → `resolved` + 公開回覆，並自動關對應 issue（F14）。`bug-phase-c-autodispatch.yml` 在 allowlist 有新增 ID 合併進 main 後逐筆 dispatch 並驗證 | push：PR 在 allowlist 新增條目（`reply`／`rev`／`deploy`）→ merge；或 push request-file `bug-phase-c-allowlist.request.md` | 可以（開 PR → CI → merge）；只限 `rev` 已在 production 的修復，不可拿來做 Phase A |
| `resolved`→`closed` 逾時 | `bug-reporter-timeout.yml` `auto`：14 天無回報者回覆即結案並白話回覆；回報者回覆會重開（Founder 2A） | schedule 每日 10:40 台北；dry-run／apply 為人工 dispatch | 自動 |
| 週報 | `bug-sla-weekly-report.yml`：「in-app 週報 YYYY-Www」issue | schedule 週一 09:00 台北 | 自動 |

**關 GitHub issue（Founder 常設決定 2026-10-12 ③）**：merge + 上線驗證（版本＋health＋smoke，見下方 Deployment evidence gate）+ 已回覆 in-app → 即可關，不等回報者確認；復發再開。In-app 的 `resolved`→`closed` 仍只由回報者 `reporter-verify` 或上表逾時流程完成，Agent 不代按。

## Freshness gate (mandatory)

Never triage from an old dump. A queue dump is valid for 15 minutes and must be
paired with the detail dump for the same bug ID.

1. Run `bug-queue-dump.yml` (dispatch: prefer `-f target_bug_id=<id>`). Check
   `meta.counts` against `open-bugs.json` and `resolved-bugs.json` (the open file
   is capped at 50 and partial above that; `resolved` rows are engineering-resolved,
   not reporter-accepted).
2. Confirm the target ID appears exactly once with status `new`, `triaged`, or
   `in_progress`; never infer it from `max_id` or an old report.
3. Run `bug-detail-dump.yml` for that exact `bug_id`; confirm attachments, the
   reporter's bug history, all comments and status logs are present.
4. Run `scripts/validate-bug-intake-evidence.py` on the two decrypted payloads. A
   failure (stale queue, mismatched IDs, bug no longer open, missing history) is
   stop-the-line, not a reason for best-effort triage.

## Triggering the dumps from a cloud session

1. Branch from latest `origin/main` as **`chore/…`** (`scripts/ci/branch-policy.mjs`
   only allows `chore|ci|fix`; an `ops/` branch fails Presubmit, e.g. PR #1668).
2. Edit the request file from the table and append `# kickoff <UTC ISO8601> — <why>`.
   For the detail dump the workflow parses the `bug_id: <n>` line, so change it.
3. PR → CI green → merge. The push to `main` fires the workflow; find the run by
   the merge commit SHA (list results are not reliably newest-first).
4. Do both dumps in the same 15-minute window (one PR may change both files).

The log and plaintext artifacts carry IDs, statuses, timestamps and counts only.
The full JSON is in `*.full.tar.gz.enc`, encrypted with `DUMP_ARTIFACT_KEY`; the
workflows fail closed without it. Cloud sessions cannot download or decrypt it, so
a local agent does (key in an ignored `chmod 600` file, never committed):

```bash
gh run download <run-id> -R jerry200176-png/AllTrue_System -n bug-detail-dump -D dump
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass file:$HOME/.alltrue-dump-key \
  -in dump/bug-detail.full.tar.gz.enc | tar xz -C dump   # bug-queue-dump.full.tar.gz.enc for the queue
```

Decrypted files stay local; never paste them into issues, PRs or comments. Do not
shortcut any of this with SSH, guessed IDs or stale artifacts.

## Deployment evidence gate (merge ≠ production)

For a code fix, keep the merge, deployment, and runtime evidence tied to one
exact commit. A green CI run proves only that the commit can be merged.

1. Record the merge SHA and confirm the `main` CI run succeeded for that same
   SHA.
2. Locate the `Deploy to Pi` run triggered by that merge and confirm its
   `head_sha` is the same SHA. Do not select a run only because it is newest.
3. If the deploy run is `pending` with `jobs=[]`, the workflow has not been
   scheduled and **nothing has been deployed**. Do not mark the bug resolved,
   close it, or tell the reporter that production is updated. Record the run
   URL, target SHA, and state, then escalate the Actions scheduler/runner
   blockage to the Founder or maintainer.
4. Do not respond to this queue state by rerunning, cancelling, dispatching a
   production workflow, or changing its gate. Protected manual activation, if
   required, must use the current `main` SHA and the exact confirmation
   `ACTIVATE_PRODUCTION:<sha>` through the repository's protected procedure.
5. After a successful deploy, collect read-only health and version evidence:

   ```text
   gh run view <deploy-run-id> --json status,conclusion,headSha,jobs,url
   curl --fail https://<production>/api/v1/health
   curl --fail https://<production>/deployment.json
   curl --fail https://<production>/version.json
   ```

   `deployment.json.backend_sha` must equal the target SHA for backend
   identity. `deployment.json.frontend_build_sha` and `version.json.build_sha`
   identify the frontend bundle when applicable. A healthy response alone does
   not prove that the target build is live.

Canonical long-form:

1. [`CHAT_BUG_SYSTEM.md`](../CHAT_BUG_SYSTEM.md) §3.6–§3.8
2. [`GUIDE_BUG_CLOSURE_GATE.md`](../GUIDE_BUG_CLOSURE_GATE.md)
3. [`AI_REGRESSION_LESSONS.md`](../AI_REGRESSION_LESSONS.md) R51/R53 + module index

Success = Evidence Contract, not CI alone. Reporter-verify timeout = Evidence Contract §Reporter-verify timeout.
