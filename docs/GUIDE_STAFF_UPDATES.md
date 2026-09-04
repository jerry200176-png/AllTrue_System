# 教職員「版本更新」指南

> **權威**：`docs/STAFF_UPDATES.yml` ≠ `docs/CHANGELOG.md`  
> **家長**：只讀 `docs/PARENT_UPDATES.yml`（R45）；STAFF 禁止 `parent`（R85）。

## 流程

1. 在 PR merge 前判斷是否為 director/teacher-facing 變更；若是，於同一 PR
   寫入 `CHANGELOG.md`、`STAFF_UPDATES.yml` 與 PR 的「新增什麼／在哪裡／怎麼用」。
2. （可選）參考 `changelogDraft.generated.js` 起草；不可把未核准草稿當成公告。
3. `cd frontend && npm run sync-release-notes && npm run test:release-notes`
4. Presubmit 會 fail-closed 檢查版本紀錄、staff id、公告來源與 PR 欄位，通過後才可 merge。
5. merge + deploy + production 驗證後，確認同一份已發布的 staff update 已在產品公告入口可見；這是驗證，不是首次補公告。

## 不漏公告的強制規則

每一筆近期 `CHANGELOG.md` 產品變更都必須在標題下方放一個決策標記：

```md
<!-- release-notes: staff_update=staff-YYYY-MM-DD-short-name -->
```

若是只影響內部治理、CI、文件或安全作業，不能直接省略，必須改用：

```md
<!-- release-notes: silent_ship=silent-YYYY-MM-DD-short-name -->
```

並在 `docs/RELEASE_NOTES_EXEMPTIONS.yml` 寫明不公告的原因。`staff_update` 的 id 必須存在於 `STAFF_UPDATES.yml`，`silent_ship` 的 id 必須存在於例外清單；Presubmit 的 CHECK 4A 會 fail-closed 檢查，沒有決策就不能 merge。

因此每次完成 production 修正時，PR checklist 必須同時完成：

1. 寫 `CHANGELOG`。
2. 判斷教職員是否需要知道；需要就寫 `STAFF_UPDATES.yml`，不需要就寫例外清單。
3. director/teacher-facing 變更填妥 PR 的 `Staff release note id`、`What changed`、`Where`、`How to use`。
4. 跑 `npm run sync-release-notes`、`npm run test:release-notes-coverage`。
5. 確認 generated JS、CI、deploy、production smoke 都以同一個 merge SHA 通過。

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
