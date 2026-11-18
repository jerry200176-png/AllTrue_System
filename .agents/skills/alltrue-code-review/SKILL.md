---
name: alltrue-code-review
description: >-
  AllTrue PR code review SOP。merge 前檢查 FR 對照、多校區隔離、
  測試覆蓋、範圍膨脹、AI_REGRESSION 已知坑。
---

# AllTrue Code Review

## 1. Purpose

merge 前確認變更**解決根因**、不引入新風險、範圍可控。

## 2. When to activate

- PR 作者自審
- 啟動 Bugbot / 人工 review 前
- 大型 PR（>200 行 deployable diff）

## 3. Required workflow

開 PR 前（含不會自動帶入範本的 MCP／API）先讀 [PR 範本](../../../.github/pull_request_template.md)，以既有 `scripts/governance/pr_declaration.py` 產生實際風險宣告。依 [教職員公告規則](../../../docs/GUIDE_STAFF_UPDATES.md) 檢查 fragment 與 `silent-reason`，先跑既有 release coverage／classification 檢查器；`ci:preflight` 不包含它們，不能當成已驗證。

CI 未出現時，先核對 live head、draft 與 mergeability：真實衝突可能只留下 `pull_request_target` 工作，須先保留雙方成果解衝突，再驗新 head；不要直接等候、重開 PR 或重複 dispatch。門檻與續接機制仍以 [MERGE_SOP](../../../docs/sop/MERGE_SOP.md) 為準。

五軸檢查（改寫自上游 code-review，AllTrue 化）：

1. **正確性**：是否對應 FR/AC？根因還是 patch？
2. **測試**：有無 RED→GREEN 回歸？只 happy path？
3. **隔離**：`CampusID` / `require_campus` / 跨校 query？
4. **範圍**：有無無關 refactor？accidental 檔案？
5. **運維**：migration 可回滾？前端需 deploy？docs-only？

Critical → 必須修；Minor → 問是否登 `TECH_DEBT.md`。

## 4. Forbidden actions

- ⛔ Critical 未清空就 merge
- ⛔ 只看 happy path 批准高風險模組
- ⛔ 略過多校區隔離

## 5. AllTrue-specific rules

- 高風險檔：`SessionDeductionService`、`AlertController::tuition`、`ApprovalSessionSyncService` — 需聚焦回歸測試與獨立審查（`CLAUDE.md` Y11）；要不要問 Founder 只看 `FOUNDER_STANDING_DECISIONS.md`
- design-hex-guard：前端不可新增 raw hex
- 模組索引：改扣堂/行事曆/繳費必查對應 §

## 6. Exit criteria

- [ ] 五軸無 Critical
- [ ] CI 綠（含 PHPUnit、Vite、design guard 若適用）
- [ ] 結論 LGTM 或列出必修項
