# PRD: 課程管理 SaaS 化 UX 補強（暫停、補課、單堂費用）

## 0. 根因 / 背景

課程管理已具備暫停、恢復、補登、調課、單堂備註/時段等能力，但 UI 語言與狀態呈現仍偏工程實作：
- 「新增堂次」不符合主任心智模型；目前實際行為是補課/補登，且不增加總堂數。
- 暫停課程視覺層次過重，row 背景、badge、callout 疊加後不像成熟 SaaS。
- 單堂「備註 / 調整時段」已有費用預覽，但合約費率來源與課程列表主檔費用顯示可能不一致；若時段變動，主任容易誤解該堂費用。

## 1. 文件資訊

- Date: 2026-04-27
- Owner: AllTrue AI Product / UX / Engineering
- Branch target: `fix/course-management-saas-ux`
- Workflow tier: T2 Product workflow（前端 UX + 費用語意）；若發現需改 `Charge` / 扣堂 / 後端收費邏輯，升級 T3 並回 ARCH/DBA/SEC gate。

## 2. 目標 / KPI

- 主任能用補教現場語言理解操作：「補課」而不是「新增堂次」。
- 暫停課程 UI 清楚、乾淨、可恢復，不像錯誤狀態。
- 單堂備註/時段編輯時，費用預覽與課程合約語意一致，不誤導主任。

## 3. 範圍

In scope:
- 將 UI 文案「新增堂次」改為「補課」或「補課/補登」，並保留 tooltip 說明「不增加總堂數」。
- 改善 `CourseManagement.vue` 的暫停課程視覺，改成成熟 SaaS 狀態列/狀態 badge。
- 用自製 modal 取代暫停/恢復的 `confirm()`，列出影響清單。
- 檢查 `SessionEditModal.vue` 單堂備註/時段費用預覽，修正合約費率來源與顯示文案。
- 更新 `CHANGELOG.md`；若發現新防再犯規則，更新 `AI_REGRESSION_LESSONS.md`。

Out of scope:
- 不改 DB schema。
- 不改扣堂、繳費提醒、學收報表核心規則。
- 不改 `/student-classes/{id}/pause` 與 `/class-sessions/{id}` API contract，除非 DEV 偵查證明現有資料不足以正確顯示費用。
- 不合併一般課程與多科共用方案資料模型。

## 4. RACI

- Product / CEO: 確認文案與主任操作語意。
- UX: 暫停狀態、補課入口、單堂費用預覽設計。
- DEV: 前端實作，優先不碰後端。
- QA: 回歸課程暫停、恢復、補課、單堂備註/時段。
- REVIEW: 確認不破壞 R20「課程結案/暫停需取消未來 scheduled 堂次」。

## 5. User Stories + AC

- As a director, I see 「補課」 instead of 「新增堂次」, so I understand this is a makeup/extra scheduling action.
  - AC: list button, dropdown item, edit modal secondary action, modal title, error messages use consistent wording.
  - AC: tooltip/hint states whether total session count changes.
- As a director, paused courses look intentionally paused, not visually broken.
  - AC: paused row uses one compact status strip and one badge; no stacked noisy callouts.
  - AC: resume action is prominent and visually positive.
- As a director, before pausing a course I understand operational impact.
  - AC: pause modal lists: future scheduled sessions will be cancelled, course leaves todo/payment reminders as applicable, can be resumed later.
- As a director, when editing a single session note/time, the fee preview is trustworthy.
  - AC: session-mode displays fixed per-session amount and says time changes do not change fee.
  - AC: hour-mode displays actual minutes, standard minutes, delta, and expected charge impact.
  - AC: if reliable rate is unavailable, UI says 「費率未設定，無法計算」 rather than showing a misleading amount.

## 6. Functional Requirements

- FR-001: Rename quick-add session UI to makeup-oriented language without changing API behavior.
- FR-002: Pause/resume uses a custom modal, not `window.confirm`.
- FR-003: Paused course row visual style must be consistent across active and history sections.
- FR-004: Session edit fee preview must use one pricing helper or clearly documented local calculation.
- FR-005: Invalid or uncertain price data must display a safe empty state, not `$0` unless the course really has zero fee.
- FR-006: No frontend hard-coded token; continue using `supabase.auth.getSession()`.

## 14. DoD（AI 可驗證）

- [ ] PRD approved by user.
- [ ] Frontend implementation complete.
- [ ] `npm run build` passes locally or CI Vite build passes.
- [ ] PR CI green.
- [ ] Deploy workflow succeeds after merge.
- [ ] Health/version verified.
