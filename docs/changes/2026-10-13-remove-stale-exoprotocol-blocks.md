## 2026-10-13 — docs: 移除根指令檔中過時的 ExoProtocol 區塊
<!-- release-notes: silent_ship=silent-2026-10-13-remove-stale-exoprotocol-blocks -->
<!-- silent-reason: 只改 agent 指令文字，沒有任何使用者可見或執行期變更。 -->
- 刪除 `CLAUDE.md`、`AGENTS.md`、`codex.md`、`.cursorrules` 內 `<!-- exo:governance:begin -->`…`<!-- exo:governance:end -->` 區塊（session-start/finish、`exo push`、max files 12、pytest checks allowlist），與現行 PR／land queue 流程衝突。`.exo/` 工具與「Portfolio governance overlay」段落保留。Founder 2026-10-13 核准。
