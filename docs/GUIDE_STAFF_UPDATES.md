# 教職員「版本更新」指南

> **權威**：`docs/STAFF_UPDATES.yml` ≠ `docs/CHANGELOG.md`  
> **家長**：只讀 `docs/PARENT_UPDATES.yml`（R45）；STAFF 禁止 `parent`（R85）。

## 流程

1. merge + deploy + production 驗證後才公告。  
2. （可選）參考 `changelogDraft.generated.js` 起草。  
3. 人工核准後寫入 `STAFF_UPDATES.yml`。  
4. `cd frontend && npm run sync-release-notes && npm run test:release-notes`  
5. commit YAML + generated JS → PR merge 才算發布。

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
