# Bug intake → production (wrapper)

## 每個階段：誰／哪個 workflow／觸發方式／cloud session 能否自行完成

本表是 in-app 回報各階段自動化的**唯一權威對照**；其他文件只連到這裡。真相以 `.github/workflows/` 為準，有出入時改本表。

| 階段 | Workflow（做什麼） | 觸發 | Cloud session（無 SSH、`workflow_dispatch` 403） |
|------|--------------------|------|------------------------------------------------|
| 收件建單 + 收到回覆 | `bug-auto-intake.yml`：每筆 `new` 開／重用 IDs-only GitHub issue（SourceRef 去重）、加 `area:<類別>`、同類提醒、留固定「收到，我們正在查」回覆（Founder 6A）。狀態**維持 `new`**，等 Phase A | schedule 每小時 :23；人工 dispatch 預設 dry-run，`dry_run=false` 會真的建單並寫 production | 自動，不需動作 |
| 撈佇列 | `bug-queue-dump.yml`：log 只有 ID／數量；明文 artifact 另含 metadata（嚴重度、分校 ID、page key、時間、回報者 user ID／角色、回饋類型），標題／描述／留言不在其中；文字在加密 `*.full.tar.gz.enc`（#3605；queue 不含描述／留言）；附 in-app ↔ issue reconcile step summary | push request-file `operations/closeout/bug-queue-dump.request.md`；或 dispatch | 可觸發；看得到 log 的 ID／數量；artifact 與全文要本機下載並解密 |
| 撈單筆 | `bug-detail-dump.yml`（`summary.json` 明文含分校／page／回報者 metadata；加密 JSON 會截斷：描述 4000、留言 1500、狀態備註 1000 字，超過要標 coverage gap；附件只有 metadata、沒有圖檔） | push request-file `operations/closeout/bug-detail-dump.request.md`（改檔內 `bug_id:` 行）；或 dispatch | 同上 |
| 即時唯讀（金鑰） | 無 workflow：`GET /api/v1/triage/bug-reports…`，帶 `ALLTRUE_TRIAGE_TOKEN`（見下方「Reading reports with `ALLTRUE_TRIAGE_TOKEN`」） | 直接 HTTPS | 可以：已設定金鑰且網路允許正式站 host 時，讀全文、留言、附件；不能寫 |
| Phase A 分診回寫 | `bug-phase-a-triage.yml`：`new`→`triaged` + 公開回覆；建議類 `close_as_logged` | **dispatch-only** | 不行：交出 `bug_id`、`github_issue_url`、含 issue URL 的 §3.8 `public_reply`；建議類另加 `disposition` 與 `close_as_logged=true`（預設是 `bug`／`false`） |
| 進度補充／建議上線通知 | `bug-followup-comment.yml`：只留言、不改狀態；`expected_status=closed`（已上線的建議）會記 production evidence 並關 GitHub issue（守門同 Phase C） | **dispatch-only** | 不行：交出 `bug_id`、`expected_status`、`github_issue_url`、`public_reply`；`expected_status=closed`（已上線的建議）另需 40 字元 `production_revision` 與 `deploy_run_id` |
| Phase C 上線後回寫 | `bug-phase-c-allowlist.yml`：已上線修復 → `resolved` + 公開回覆，並自動關對應 issue（F14；`type:epic`、`lifecycle:frozen` 不關，issue 標題或 SourceRef 對到的任何 in-app 回報不在本次已檢查清單內就不關）。`bug-phase-c-autodispatch.yml` 在 allowlist 有新增 ID 合併進 main 後逐筆 dispatch 並驗證 | push：PR 在 allowlist 新增條目（`reply`／`rev`／`deploy`）→ merge；或 push request-file `operations/closeout/bug-phase-c-allowlist.request.md`；已在 allowlist 的 ID 可直接 dispatch（`bug_id`） | 可以（開 PR → CI → merge）；只限 `rev` 已在 production 的修復，不可拿來做 Phase A |
| `resolved`→`closed` 逾時 | `bug-reporter-timeout.yml` `auto`（排程）：只看 `resolved`，14 天無回報者回覆即結案並白話回覆；回報者回覆會重開（Founder 2A）。符合者超過 20 筆時整批拒絕、**一筆都不關**，要先人工 dry-run 處理。人工 `dry-run`／`apply` 是另一條路：`days` 預設 **7**（只作用於 `resolved`），另外也檢查固定 14 天的 `triaged` 等回報者佇列；`apply` 必填 `confirmation=CLOSE_STALE_RESOLVED` 與逐筆審過的 `reviewed_ids` | `auto`：schedule 每日 10:40 台北（亦可 dispatch `auto-dry-run`／`auto`）；`dry-run`／`apply`：只限人工 dispatch | `auto` 自動；人工 `apply` 不行：交出 dry-run 審過的 `reviewed_ids` 與 `days` |
| 週報 | `bug-sla-weekly-report.yml`：「in-app 週報 YYYY-Www」issue | schedule 週一 09:00 台北；可人工 dispatch 重產 | 自動 |

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

The log carries IDs and counts; the plaintext artifacts add non-text metadata
(see the table). Titles, descriptions and comments are only in `*.full.tar.gz.enc`, encrypted with `DUMP_ARTIFACT_KEY`; the
workflows fail closed without it. Cloud sessions cannot download or decrypt it, so
a local agent does (key in an ignored `chmod 600` file, never committed):

```bash
gh run download <run-id> -R jerry200176-png/AllTrue_System -n bug-detail-dump -D dump
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass file:$HOME/.alltrue-dump-key \
  -in dump/bug-detail.full.tar.gz.enc | tar xz -C dump   # bug-queue-dump.full.tar.gz.enc for the queue
```

`production-case-dump.yml` works the same way: its log shows only (it writes no step summary) the case key, exit status,
completeness flag/reason tokens, byte lengths and the encrypted file's sha256. The artifact
`production-case-<case_key>` holds only `production-case.full.tar.gz.enc` plus its `.sha256`; check the hash
(`sha256sum -c`) and decrypt it with the same command.

Decrypted files stay local; never paste them into issues, PRs or comments. Do not
shortcut any of this with SSH, guessed IDs or stale artifacts.

## Reading reports with `ALLTRUE_TRIAGE_TOKEN` (cloud sessions, read-only)

A super admin creates the token in 個人資料 → 安全性 → **AI 分診唯讀金鑰**. The
plaintext is shown once and expires after `config/triage_tokens.php` `ttl_days`
(default 90). Store it only as the cloud environment secret
`ALLTRUE_TRIAGE_TOKEN`; the environment's network policy must allow the
production host. The token reads live data, so the 15-minute dump freshness gate
does not apply to it; the dumps above stay the fallback when no token is set.

Run each block as one shell call. It keeps the token out of argv and `ps`
(`printf` is a bash builtin; curl reads the header from a 0600 config via `-K`),
writes report data only inside a private `mktemp -d` directory that is deleted
when the block ends, and exits non-zero when any fetch fails. The queue block
prints the counts and one `id status campus title` line per report to your own
terminal (never to GitHub) before the directory goes, so you can pick the IDs
for the detail block.

```bash
( set -euo pipefail; umask 077
  T=$(mktemp -d); trap 'rm -rf "$T"' EXIT   # 0700 dir, deleted when the block ends
  printf 'header = "Authorization: Bearer %s"\n' "$ALLTRUE_TRIAGE_TOKEN" > "$T/auth.cfg"
  B=https://daan.lifenet.com.tw/api/v1/triage
  # Full live snapshot = open AND resolved (reporter-confirmation queue). Page each until data is empty.
  for S in new,triaged,in_progress resolved; do N=0; while :; do
    P=$(curl -sf -K "$T/auth.cfg" "$B/bug-reports?status=$S&since_id=$N&limit=100") || { echo "INCOMPLETE: fetch failed ($S since_id=$N)" >&2; exit 1; }
    L=$(jq -e '.data|length' <<<"$P") || { echo "INCOMPLETE: malformed page ($S since_id=$N)" >&2; exit 1; }
    [ "$L" = 0 ] && break
    jq -c '.data[]' <<<"$P"; N=$(jq '.next_since_id' <<<"$P"); done; done > "$T/live-queue.jsonl"
  jq -r .status "$T/live-queue.jsonl" | sort | uniq -c   # per-status counts, only after both groups paged to empty (resolved may legitimately be 0)
  jq -r '[.id, .status, .campus_id, .title] | @tsv' "$T/live-queue.jsonl"   # the queue itself, read it here before $T is deleted
)
```

Then one report: description, comments (incl. internal notes), status logs and
`attachments[].download_path`. Set `ID` to an id from the list above.

```bash
( set -euo pipefail; umask 077; ID=<id>
  T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
  printf 'header = "Authorization: Bearer %s"\n' "$ALLTRUE_TRIAGE_TOKEN" > "$T/auth.cfg"
  curl -sf -K "$T/auth.cfg" "https://daan.lifenet.com.tw/api/v1/triage/bug-reports/$ID" || { echo "FAILED: detail fetch for $ID" >&2; exit 1; }
)
```

An attachment has to outlive the shell call so you can open it, so it gets its
own private directory that you delete yourself after viewing:

```bash
umask 077; D=$(mktemp -d)
printf 'header = "Authorization: Bearer %s"\n' "$ALLTRUE_TRIAGE_TOKEN" > "$D/auth.cfg"
if curl -sf -K "$D/auth.cfg" -o "$D/a.png" "https://daan.lifenet.com.tw/api/v1/triage/bug-reports/<id>/attachments/<attachment_id>"; then
  rm -f "$D/auth.cfg"; echo "$D"
else
  rc=$?; rm -rf "$D"; echo "FAILED: attachment download (curl exit $rc); the attachment was NOT reviewed" >&2; false
fi
# view "$D/a.png", then: rm -rf "$D"
```

- **Completeness.** Only a run that pages both status groups to an empty page is a
  full snapshot. A single page, or the open statuses alone, is partial: say so, or
  fall back to the queue dump.
- **Read-only.** Any other route answers 403 to this token, and there is no write
  endpoint. Write-back (`new`→`triaged`, public replies, Phase C) still goes
  through the dispatch workflows in the table above.
- **Never post report content to GitHub.** The repo is public. Issues, PRs, commits
  and comments carry only IDs (in-app #, attachment ID, campus ID), counts and your
  own technical findings: never reporter or student names, phone numbers, report
  text, comments, or screenshots. Delete downloaded attachments after viewing.
- Every request is logged in `security_audit_events` (`triage_token.read`: route,
  status, time; token, report, attachment and IP as `SecurityAuditEvent::ref()`
  HMACs, so match a known ID/IP by computing its ref). A 401 `token_expired` means: ask a super admin for a
  new token. Revoke it in the same screen if it may have leaked.

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
