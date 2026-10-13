## 2026-10-13 — ops: in-app 分診／進度回覆的雲端受控路徑（request file）
<!-- release-notes: silent_ship=silent-2026-10-13-bug-writeback-request-path -->
<!-- silent-reason: 只改 agent 操作流程與 CI／governance，沒有使用者可見變更。 -->
- 新增 `operations/closeout/bug-writeback.request.yml` + `bug-writeback-request.yml`：雲端 session 可用 PR 申請 Phase-A 分診（`new`→`triaged` + 公開回覆）與進度留言，merge 後只跑新的 `request_id`；與兩個 dispatch workflow 共用 `scripts/bug-writeback/run-remote.sh`。改這個檔的 PR 由 `autonomy_gate.py` 判為至少 T2；`resolved`／`closed` 不經此路。
