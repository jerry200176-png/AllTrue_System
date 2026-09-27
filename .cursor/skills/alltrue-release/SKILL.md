---
name: alltrue-release
description: >-
  AllTrue 上線與 production 驗證 SOP。PR merge 後、宣告 bug 修復完成前必啟用。
  deploy.yml、health check、version.json、in-app resolved 留言。
---

# AllTrue Release

## 1. Purpose

證明修復**真的在 production**，不是「CI 綠了就算」。

## 2. When to activate

- PR merge 後
- 宣告 in-app bug `resolved` 前
- 使用者問「上線了嗎」

## 3. Required workflow

1. **等 CI 全綠**（自己 `gh run view`，不叫使用者去看）
2. **等 canonical `deploy.yml` success**（docs-only merge 可跳過 deploy；確認 **Deploy to Production** job 真的 success，勿只看 Release state）
3. **驗證 production 雙端身分**（公開 endpoint；**禁止 Pi SSH** — 與 `AGENTS.md` 機器禁令一致）：
   ```bash
   curl -fsS https://daan.lifenet.com.tw/version.json
   curl -fsS https://daan.lifenet.com.tw/deployment.json
   # build_sha / backend_sha / frontend_sha 必須 == 批准的 merge／activation SHA
   ```
4. **Health**：`curl -fsS https://daan.lifenet.com.tw/api/v1/health` → `status: ok`
5. **Smoke**：依 bug 類型 spot-check（見下方；只用已授權測試通道）
6. **in-app**：`resolved` + production SHA 證據 + 公開白話留言；**不**偽造 `reporter-verify`

### Smoke 對照

| Bug 類型 | 驗證 |
|---------|------|
| 週日續約 #190 | `buildSessionsFromWeeklySchedule` 週日有堂；歷史 0 元需另案資料修復 |
| 幽靈堂次 #196 | session-dates materialized 含 leave、projected 無幽靈時段 |
| leave_requested #194 | 前後端狀態一致（deploy 後） |

## 4. Forbidden actions

- ⛔ feature branch 上 `npm run deploy`
- ⛔ CI 未綠就 merge 或回報完成
- ⛔ 未驗公開 `version.json` / `deployment.json` 就關 issue 或宣稱 shipped
- ⛔ **任何 Pi SSH**（含 `git rev-parse`／讀 host 檔）；改 Pi 程式碼
- ⛔ 把 GitHub environment 核准當成會自動喚醒本機 agent

## 5. AllTrue-specific rules

- 合法路徑：WSL push → PR → merge → `deploy.yml`（Founder `production-activation` 依現行 gate）
- 回滾：只用現行 workflow 已證明可執行的路徑；「有舊 SHA」≠「可 post-success rollback」
- 公開留言禁技術術語（`CHAT_BUG_SYSTEM` 回覆規範）
- merged ≠ deployed ≠ runtime verified ≠ 已回覆

## 6. Exit criteria

- [ ] deploy workflow 中實際 Deploy job success（或 docs-only 跳過已確認）
- [ ] `version.json` build_sha 與 `deployment.json` backend/frontend SHA == 批准 SHA
- [ ] health ok
- [ ] smoke 有命令 + 預期 + 實際 + 證據（若該項需要）
- [ ] in-app `resolved` + 公開留言（不偽造 reporter confirmation）
