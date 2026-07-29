# 教職員「版本更新」撰寫與核准指南

> **權威來源**：`docs/STAFF_UPDATES.yml`（使用者公告）≠ `docs/CHANGELOG.md`（工程紀錄）  
> **家長**：仍只讀 `docs/PARENT_UPDATES.yml`（R45）；禁止在 STAFF 檔使用 `parent` audience。

## 一句話

Engineering Changelog 回答「程式改了什麼」；Staff Updates 回答「這對主任／老師的工作有什麼影響」。後者必須顯式撰寫並核准，不可由 CHANGELOG 自動發布。

## 日常流程

1. 功能 merge + deploy + production 驗證完成後，才考慮公告。
2. （可選）讀 `frontend/src/lib/changelogDraft.generated.js` 或當週 CHANGELOG，由 AI 起草候選。
3. 人工改寫成使用者結果文案，寫入 `docs/STAFF_UPDATES.yml`。
4. `cd frontend && npm run sync-release-notes`
5. 跑 `npm run test:release-notes`
6. commit YAML + generated JS；PR merge 後才算發布。

AI **不得**在未核准時把草稿直接寫進 `STAFF_UPDATES.yml` 並宣稱已發布。

## Schema（最小）

| 欄位 | 必填 | 說明 |
|------|------|------|
| `id` | ✓ | 穩定字串，如 `staff-2026-07-week-30` |
| `published_at` | ✓ | 使用者時間軸排序權威（YYYY-MM-DD） |
| `audiences` | ✓ | `director` / `teacher`（可多選；禁 parent） |
| `importance` | ✓ | `digest` \| `major` \| `action_required` |
| `title` | ✓ | ≤ 18 字 |
| `summary` | ✓ | ≤ 45 字 |
| `items` | ✓ | 1–3 條；`category` + `text`（≤ 60 字） |
| `effective_at` | | 與公告日不同且重要時才填 |
| `source_refs` | | 對應 CHANGELOG 日期，供稽核 |

`category`：`added` \| `fixed` \| `improved` \| `action_required`  
UI 映射：你現在可以／我們修好了／操作更順手／需要你注意。

## 何時單獨成卡（major / action_required）

- 使用者獲得明確新能力
- 會造成實際工作錯誤的問題已修正
- 操作流程顯著改變
- 使用者需要採取行動
- 政策／計費／排課／資料可見性重大改變

其餘進當週 `digest`，或只留 CHANGELOG。沒有值得講的內容時可不發卡。

## Silent ship（只留 CHANGELOG）

docs-only、test/CI、refactor、logging、ADR/Phase、read-only／default-off、尚未 production verified、無可感知差異的效能等。

## 發布 gate

全部成立才進 YAML：code merged → deploy success → runtime enabled → production verified → 文案 approved。

## 語言閘門

`scripts/lib/userFacingCopyGate.mjs` 會擋內部 ID、class／table、API path、Phase/ADR/TD、空話與殘詞。偵測到 = 建置失敗，不自動刮字改寫。

## 相關指令

```bash
cd frontend && npm run sync-release-notes
cd frontend && npm run test:release-notes
npm run sync:generated:check
```
