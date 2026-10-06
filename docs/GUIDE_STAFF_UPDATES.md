# 教職員「版本更新」指南

> **權威**：教職員卡 = `docs/STAFF_UPDATES.yml`（凍結歷史）+ `docs/staff-updates/*.yml`；≠ `docs/CHANGELOG.md`  
> **家長**：只讀 `docs/PARENT_UPDATES.yml` + `docs/parent-updates/*.yml`（R45）；STAFF 禁止 `parent`（R85）。

## 規則：PR 只「新增檔案」，不改共用清單

`docs/CHANGELOG.md`、`RELEASE_NOTES_EXEMPTIONS.yml`、`STAFF_UPDATES.yml`、`PARENT_UPDATES.yml` 已**凍結為歷史**，不要再改（改了會跟其他 PR 衝突）。`*.generated.js` 不進 git（`npm run build` / `postinstall` / `dev` / `test:unit` 會自動產生）。

| 要做的事 | 新增檔案 |
| --- | --- |
| CHANGELOG 條目 | `docs/changes/<YYYY-MM-DD>-<slug>.md` |
| 教職員版本卡 | `docs/staff-updates/<id>.yml` |
| 家長更新卡 | `docs/parent-updates/<id>.yml` |
| 不公告（silent ship） | 同一份 fragment 內的 `<!-- silent-reason: ... -->` |

範例：`docs/changes/2026-10-06-change-fragments.md`。fragment 格式與舊 CHANGELOG 條目完全相同（標題行 + 標記 + 條列）：

```md
## 2026-10-06 — chore(ci): 標題
<!-- release-notes: staff_update=staff-2026-10-06-short-name -->
<!-- 或：release-notes: silent_ship=silent-2026-10-06-short-name + 下一行 -->
<!-- silent-reason: 一句白話原因（教職員看不到差異） -->
- 變更內容
```

卡片檔 `docs/staff-updates/<id>.yml` / `docs/parent-updates/<id>.yml` 與舊清單的單筆項目 schema 相同（含 `updates:` 開頭，只放一筆）。

## 流程

1. merge + deploy + production 驗證後才公告。  
2. 人工核准後新增 `docs/staff-updates/<id>.yml`。  
3. `cd frontend && npm run sync-release-notes && npm run test:release-notes`
4. PR merge 才算發布（不要 commit generated JS）。

## 不漏公告的強制規則

每一筆近期產品變更（fragment）都必須在標題下方放一個決策標記：`staff_update=<id>`，或（只影響內部治理、CI、文件、安全作業時）`silent_ship=<id>` 加 `silent-reason`。`staff_update` 的 id 必須存在於 `STAFF_UPDATES.yml` 或 `docs/staff-updates/`；Presubmit 的 CHECK 4A 會 fail-closed 檢查，沒有決策就不能 merge。

PR checklist：

1. 新增 `docs/changes/` fragment。
2. 教職員需要知道 → 新增 `docs/staff-updates/<id>.yml`；不需要 → `silent-reason`。
3. 跑 `npm run sync-release-notes`、`npm run test:release-notes-coverage`。
4. CI、deploy、production smoke 都以同一個 merge SHA 通過。

AI 不得把未核准草稿寫進 YAML 並宣稱已發布。

## Schema

必填：`id`、`published_at`、`audiences`（director|teacher）、`importance`（digest|major|action_required）、`title`（≤18）、`summary`（≤45）、`items`（1–3；`category`+`text`≤60）。  
可選：`effective_at`、`source_refs`。  
`category`：added|fixed|improved|action_required → UI：你現在可以／我們修好了／操作更順手／需要你注意。

## 節奏與閘門

- 預設週 `digest`；重大能力／高影響修正／流程顯著改變／需行動 → `major` 或 `action_required`。  
- Silent ship：docs/test/CI/refactor/ADR/default-off／未 verified。  
- 發布需：merged + deployed + enabled + verified + 文案 approved。  
- 語言閘門：`scripts/lib/userFacingCopyGate.mjs`（失敗即停，不刮字）。
