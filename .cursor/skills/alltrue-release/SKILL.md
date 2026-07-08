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
2. **等 `deploy.yml` success**（docs-only merge 可跳過 deploy）
3. **驗證 production HEAD**：
   ```bash
   ssh admin@pi.lifenet.com.tw 'cd /home/admin/backend && git rev-parse HEAD'
   # 必須 == merge commit
   ```
4. **Health**：`curl -sk https://daan.lifenet.com.tw/api/v1/health` → `status: ok`
5. **前端有變更**：`cat /home/admin/backend/public/version.json` 時間戳更新
6. **Smoke**：依 bug 類型 spot-check（見下方）
7. **in-app**：`resolved` + 公開白話留言 + 請回報者驗收

### Smoke 對照

| Bug 類型 | 驗證 |
|---------|------|
| 週日續約 #190 | `buildSessionsFromWeeklySchedule` 週日有堂；歷史 0 元需另案資料修復 |
| 幽靈堂次 #196 | session-dates materialized 含 leave、projected 無幽靈時段 |
| leave_requested #194 | 前後端狀態一致（deploy 後） |

## 4. Forbidden actions

- ⛔ feature branch 上 `npm run deploy`
- ⛔ CI 未綠就 merge 或回報完成
- ⛔ 未驗 production HEAD 就關 issue
- ⛔ SSH 改 Pi 程式碼

## 5. AllTrue-specific rules

- 合法路徑：WSL push → PR → merge → `deploy.yml`
- 緊急回滾：`git revert` + deploy，不是 force push main
- 公開留言禁技術術語（§3.8 CHAT_BUG_SYSTEM）

## 6. Exit criteria

- [ ] deploy workflow success（或 docs-only 跳過已確認）
- [ ] production HEAD == merge SHA
- [ ] health 200
- [ ] smoke 有命令 + 預期 + 實際 + 證據
- [ ] in-app `resolved` + 公開留言
