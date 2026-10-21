# Bug intake → production (wrapper)

## 每個階段：誰／哪個 workflow／觸發方式／cloud session 能否自行完成

本表是 in-app 回報各階段自動化的**唯一權威對照**；其他文件只連到這裡。真相以 `.github/workflows/` 為準，有出入時改本表。

| 階段 | Workflow（做什麼） | 觸發 | Cloud session（無 SSH；dispatch 能力與操作授權分開，見表下說明） |
|------|--------------------|------|------------------------------------------------|
| 收件建單 + 收到回覆 | `bug-auto-intake.yml`：每筆 `new` 開／重用 IDs-only GitHub issue（SourceRef 去重）、加 `area:<類別>`、同類提醒、留固定「收到，我們正在查」回覆（Founder 6A）。狀態**維持 `new`**，等 Phase A | schedule 每小時 :23；人工 dispatch 預設 dry-run，`dry_run=false` 會真的建單並寫 production | 自動，不需動作 |
| 撈佇列 | `bug-queue-dump.yml`：log 只有 ID／數量；明文 artifact 另含 metadata（嚴重度、分校 ID、page key、時間、回報者 user ID／角色、回饋類型），標題／描述／留言不在其中；文字在加密 `*.full.tar.gz.enc`（#3605；queue 不含描述／留言）；附 in-app ↔ issue reconcile step summary | push request-file `operations/closeout/bug-queue-dump.request.md`；或 dispatch | 可觸發；看得到 log 的 ID／數量；artifact 與全文由已授權且具解密 secret 的受控環境下載與解密（見 Cloud decryption custody） |
| 撈單筆 | `bug-detail-dump.yml`（`summary.json` 明文含分校／page／回報者 metadata；加密 JSON 會截斷：描述 4000、留言 1500、狀態備註 1000 字，超過要標 coverage gap；附件只有 metadata、沒有圖檔） | push request-file `operations/closeout/bug-detail-dump.request.md`（改檔內 `bug_id:` 行）；或 dispatch | 同上 |
| 即時唯讀（金鑰） | 無 workflow：`GET /api/v1/triage/bug-reports…`，帶 `ALLTRUE_TRIAGE_TOKEN`（見下方「Reading reports with `ALLTRUE_TRIAGE_TOKEN`」） | 直接 HTTPS | 可以：已設定金鑰且網路允許正式站 host 時，讀全文、留言、附件；不能寫 |
| Phase A 分診回寫 | `bug-phase-a-triage.yml`：`new`→`triaged` + 公開回覆；建議類 `close_as_logged` | **dispatch-only、main-only**；preview → write（Founder 2026-10-21 起不逐次核准） | 備齊證據後可直接 dispatch；`rows_json` 每筆準備 `bug_id`、`github_issue_url`、含 issue URL 且 ≤600 字元的 §3.8 `public_reply`；建議類另加 `disposition` 與 `close_as_logged: true`（預設是 `bug`／`false`）；一次 ≤20 筆 |
| 進度補充／建議上線通知 | `bug-followup-comment.yml`：只留言、不改狀態；`expected_status=closed`（已上線的建議）會記 production evidence 並關 GitHub issue（守門同 Phase C） | **dispatch-only、main-only**；preview → write（Founder 2026-10-21 起不逐次核准） | 同上；`rows_json` 每筆準備 `bug_id`、`expected_status`、`github_issue_url`、`public_reply`；`expected_status=closed`（已上線的建議）另需 40 字元 `production_revision` 與 `deploy_run_id` |
| Phase C 上線後回寫 | **SSH allowlist path retired 2026-10-19（ADR-POP-015 / #4289）**：`bug-phase-c-allowlist.yml` 遠端步驟在任何 `BugReportService` 寫入前拋 `legacy_ssh_phase_c_retired`；`bug-phase-c-autodispatch.yml` 對新增 allowlist ID **fail closed**。**目前可跑的替代路徑只有** `bug-followup-comment.yml`（preview→write；只留言、不改狀態）。ADR-POP-015 規劃的 Pi-local `inapp-report-resolve-after-deploy` **尚未**寫入 `operations/catalog.yaml`／standing policy（現有 in-app lifecycle 僅 `inapp-report-reporter-timeout`），不得當成已可執行。Allowlist 檔保留供契約測試 | 勿再靠 allowlist PR／request-file 期望寫入；用 follow-up | follow-up：可提交 preview；勿假設未 catalog 的 resolve 操作可跑 |
| `resolved`→`closed` 逾時 | `bug-reporter-timeout.yml` `auto`（排程）：只看 `resolved`，14 天無回報者回覆即結案並白話回覆；回報者回覆會重開（Founder 2A）。符合者超過 20 筆時整批拒絕、**一筆都不關**，要先人工 dry-run 處理。人工 `dry-run`／`apply` 是另一條路：`days` 預設 **7**（只作用於 `resolved`），另外也檢查固定 14 天的 `triaged` 等回報者佇列；`apply` 必填 `confirmation=CLOSE_STALE_RESOLVED` 與逐筆審過的 `reviewed_ids` | `auto`：schedule 每日 10:40 台北（亦可 dispatch `auto-dry-run`／`auto`）；`dry-run`／`apply`：只限人工 dispatch | `auto` 自動；人工 `apply` 不行：交出 dry-run 審過的 `reviewed_ids` 與 `days` |
| 週報 | `bug-sla-weekly-report.yml`：「in-app 週報 YYYY-Www」issue | schedule 週一 09:00 台北；可人工 dispatch 重產 | 自動 |

**雲端 dispatch 現況（更正舊說法「一律 403」）**：2026-10-15 的原始觀測是在當時那一個 Claude cloud session、以當時的權限，成功呼叫 `workflow_dispatch`（`land-queue.yml` 的 no-op）及 `repository_dispatch`（未觸發任何寫入）；不代表所有 session 或日後權限皆可觸發。**可 dispatch 不等於已授權**：既有 Founder 常設批准及其條件以 [`FOUNDER_STANDING_DECISIONS.md`](../governance/FOUNDER_STANDING_DECISIONS.md) 為準，本表不新增 token、正式資料存取或政策例外。

**Phase A／follow-up 的 guard 已合併**：[PR #4196](https://github.com/jerry200176-png/AllTrue_System/pull/4196)（2026-10-16）建立兩個 dispatch-only workflow；**Founder 2026-10-21 起移除逐次 `production-activation` 審核**（[`FOUNDER_STANDING_DECISIONS.md`](../governance/FOUNDER_STANDING_DECISIONS.md) 2026-10-21 第 1 條），現為 `preview` → write。輸入是 `rows_json`：JSON 陣列 1–20 筆，Phase A 每筆 `{"bug_id","github_issue_url","public_reply","disposition"?,"close_as_logged"?}`，follow-up 每筆 `{"bug_id","expected_status","github_issue_url","public_reply","production_revision"?,"deploy_run_id"?}`；單筆就送一個元素的陣列。`.github/scripts/inapp-writeback-rows.py` 在 preview 與寫入 job 各驗一次，任一筆不合格（含 bug_id 重複、回覆超過 600 字元、未知欄位，以及回覆含電話／身分證字號／email／金鑰樣式字串，或本 repo issue 與正式站 `https://daan.lifenet.com.tw` 以外的連結）整批在寫入前失敗；錯誤只寫第幾筆與欄位名。公開 summary 只列每筆 bug_id／issue／disposition 或狀態／長度與精確 UTF-8 `sha256(public_reply)`，不顯示全文。只能在 `main` 執行；寫入 job 使用 main-only `production-ssh`，依序在同一個 SSH session 逐筆寫入，遇到失敗的那筆即停（前面已寫的保留，重跑每筆冪等）。兩個 workflow 共用 `inapp-production-writeback` concurrency（`cancel-in-progress: false`、`queue: max`）。不得僅因 guard 已合併就宣稱案件已回寫；Phase C、部署、資料修復與其他正式寫入維持原關卡。

回覆仍須符合 §3.8、含 issue URL、避免重複送出；私下審核清單也不貼公開 GitHub。各階段仍須先備齊真實案件、讀取新鮮度及完整留言／附件 coverage；宣稱已上線時另須上線與受影響路徑證據。缺證據就停止該項，不以猜測內容或舊 dump 補足。`expected_status=closed` 仍限已上線的 logged suggestion，並提供完整 `production_revision` 與 `deploy_run_id`；不得代替回報者驗收。

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
workflows fail closed without it. Artifact download depends on the current connection
and network policy; a session without the existing authorized decryption key cannot
read the full text. A past cloud download 403 is not a universal capability rule,
and an egress block must not be bypassed. Decryption follows the approved
custody and migration checks below; a local workstation is not a required runtime.
Dispatch capability alone grants no key transfer or new data access. The following
file-based command is the legacy local option, not a prerequisite for cloud work:

```bash
gh run download <run-id> -R jerry200176-png/AllTrue_System -n bug-detail-dump -D dump
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass file:$HOME/.alltrue-dump-key \
  -in dump/bug-detail.full.tar.gz.enc -out dump/private.tar.gz
# Apply the complete archive validation below before extracting expected files.
# Use bug-queue-dump.full.tar.gz.enc for the queue.
```

`production-case-dump.yml` works the same way: its log shows only (it writes no step summary) the case key, exit status,
completeness flag/reason tokens, byte lengths and the encrypted file's sha256. The artifact
`production-case-<case_key>` holds only `production-case.full.tar.gz.enc` plus its `.sha256`; check the hash
(`sha256sum -c`) and decrypt it with the same command.

Decrypted files stay inside the authorized private execution environment; never
paste them into issues, PRs or comments. Do not shortcut any of this with SSH,
guessed IDs or stale artifacts.

### Cloud decryption custody

The custody boundary is an authorized environment and task scope, not the physical
location of the agent. The local workstation may retire after the acceptance
checks below. This procedure is a migration specification, not evidence that a
cloud binding exists or that decryption has already succeeded.

The Founder approved only the existing `DUMP_ARTIFACT_KEY` custody migration
for the controlled AllTrue_System cloud environment; see the narrow
[2026-10-19 decision](../governance/FOUNDER_STANDING_DECISIONS.md).
This named exception does not authorize other cloud credentials, repositories,
record scopes, production writes or deployments.

Preserve the **same key** through the supported Personal vault binding, scoped
only to AllTrue_System and using the **direct environment-variable** secret type.
The custodian enters it into the vault, never chat, GitHub, argv, logs or an
ordinary non-secret environment setting. This deliberately makes the bytes
available to processes in that approved environment; do not claim it is an
agent-unreadable credential. A network-secret proxy placeholder cannot decrypt
OpenSSL data. Do not dump the environment, inspect/print key bytes, pass them to
untrusted PR code, export GitHub Actions secrets or rotate the producer key.

Before enabling access, confirm the recipient environment's access controls,
workspace visibility, log retention and deletion behavior are appropriate for the
authorized report/case data. Restrict the binding to that environment and task;
the shared key can decrypt more than the current case. Do not bind it to unrelated
projects or untrusted PR jobs. Use supported encrypted secret injection, preserve
TLS and the configured network policy, and use a real secret binding rather than
committing a key file or copying an environment directory.

Acceptance and retirement checklist:

1. Record the approved destination and read-only data scope in a private handoff.
   The product's existing financial write, deploy and identity controls still apply.
2. Establish the task's approved AllTrue_System scope from current status and
   private handoff metadata. Distinguish `environment_id` (managed runtime
   instance) from `source_config_id` (reusable configuration) and
   `source_config_version_id`/spec revision (published version); unequal IDs of
   different types alone do not prove a scope mismatch. Resolve their mapping
   through supported metadata and the preserved handoff marker before key use.
   Claim readiness only with `observations_current: true` and the individual
   binding state `ready`; a variable name or presence alone is insufficient.
   Unknown telemetry does not prove absence. Once the authorized scope is
   established, a bounded, captured OpenSSL/archive diagnostic may use the
   configured variable normally without exposing its value. Record telemetry
   separately from actual diagnostic PASS/FAIL; missing variable is `MISSING`,
   artifact-access failure is a separate download error, and successful decrypt
   does not invent a `ready` observation. Unresolved scope stops key use. A
   missing binding or unusable proxy placeholder requires supported configuration
   repair, never secret extraction or an improvised fallback. A new conversation
   alone does not prove a new configuration.
3. Download an authorized encrypted artifact through a permitted connector or CLI.
   Compare its ciphertext hash with the trusted run evidence and `.sha256` file.
   Do not expose signed download URLs. The checksum checks integrity; it does not
   authenticate AES-CBC ciphertext from an untrusted source.
4. In a private directory (`umask 077`), disable shell tracing and use the existing
   OpenSSL AES-256-CBC/PBKDF2 format with 600000 iterations. Within the established approved scope, with the configured
   direct environment-variable secret, use `-pass env:DUMP_ARTIFACT_KEY`.
   Never put key bytes in argv. Keep the original local mode-600 key unchanged.
   Decrypt to a private temporary archive first; on failure remove it and stop.
   Inspect the complete archive before extraction: allow only expected regular
   files and safe relative directories; reject duplicate paths, absolute paths,
   traversal, links and special files, and bound member counts and total expanded
   bytes. Extract only validated expected files into the private directory, never
   by blindly piping to tar. Never auto-execute archive content.
5. Validate the expected JSON schema, subject IDs, scope, completeness and
   freshness before use. Successful decryption or a green dump workflow does not
   make a PARTIAL snapshot complete. Report only PASS/FAIL for custody acceptance;
   financial payloads, private evidence and artifact references stay private.
6. Prove a fresh cloud session can repeat the read-only download/decryption without
   the local workstation. Keep only non-sensitive acceptance status publicly;
   specific production artifact references and findings stay in the private handoff
   unless explicitly authorized for disclosure. Remove temporary plaintext after
   use and retain the key only in the approved secret store with a controlled
   recovery copy. Only then retire the local copy/agent.

Recovery: if readiness or decryption fails, stop this read path, remove partial
plaintext and correct the binding through supported configuration. Do not delete
the last recoverable key, rotate the producer, fetch secrets through a workflow,
or fall back to production SSH. Reverting these documentation changes does not
revoke an installed secret; revoke the cloud binding separately if access must be
rolled back. Live in-app reads may alternatively use the already scoped
`ALLTRUE_TRIAGE_TOKEN` path below when separately provisioned and authorized; that
token cannot decrypt old financial case artifacts.

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
