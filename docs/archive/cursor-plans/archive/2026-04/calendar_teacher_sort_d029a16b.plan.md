---
name: Calendar Teacher Sort
overview: 在智慧排課日檢視中，將「當天有排課」的老師欄自動排到最左側，空白老師欄排到右側，減少橫向捲動負擔。
todos:
  - id: sort-logic
    content: 修改 SmartCalendar.vue visibleTeachers sort：加入 hasCoursesToday 為第一排序鍵（有課=0，無課=1），保留原 roomLabel → username 為第二、三鍵
    status: completed
  - id: hide-toggle
    content: （P1）實作「隱藏今日無課老師」開關：新增 hideEmptyTeacherColumns ref、localStorage 保存、篩選列 UI
    status: completed
  - id: qa-validation
    content: QA 驗收：切換日期有課置左、全員無課退化、過濾器相容性、隱藏開關邊界
    status: completed
  - id: ux-signoff
    content: UI/UX sign-off：確認空狀態、切換無 layout shift、開關說明文字清晰
    status: completed
  - id: deploy
    content: npm run deploy，確認 index.html + assets 同輪更新
    status: completed
  - id: changelog
    content: 更新 docs/CHANGELOG.md
    status: completed
  - id: pm-signoff
    content: PM sign-off：DoD checklist 全部打勾
    status: completed
isProject: false
---

# 智慧排課日檢視：有課老師欄置左排序

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 智慧排課日檢視：當日有課老師欄自動置左 |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 主任、老師（使用智慧排課日檢視的所有角色） |

## 2. 目標與業務背景

**痛點：** 日檢視採多欄並排模式，欄位順序目前依「教室 → 姓名」字母排序，與「今天是否有排課」完全無關。當天無課的老師佔據最左側欄位，有課的老師反而被推到右側，使用者需要橫向捲動才能看到有意義的資訊。

**業務價值：** 降低主任確認當日排課時的操作摩擦；老師登入後的日視圖也能直接看到自己的課，無需捲動。

**成功指標：** 日檢視載入後，有課老師的欄位 100% 位於無課老師欄位左側；`[TODO: 需確認]` 如有使用者行為分析工具，可量化橫向捲動距離下降。

## 3. 範圍

**In Scope（本次）：**
- 日檢視（`isWeekOverview === false`）中 `visibleTeachers` 的排序邏輯，加入「當日是否有課」為首要排序鍵
- 「當日有課」定義：`filteredCourses` 中存在 `teacher_id === t.id && day_of_week === selectedDow` 且未被 `isSessionCancelledOnDate` 排除的課程

**Out of Scope（本次不做）：**
- 週檢視（週檢視沒有老師欄，不受影響）
- 隱藏空白老師欄（見 P1 可選項）
- 老師欄的手動拖曳重新排序
- 後端 API 變更

## 4. RACI

| 角色 | R/A/C/I |
|---|---|
| PM | A |
| 前端工程師 | R |
| UI/UX Designer | R（驗收 5b 節項目） |
| QA | R |
| 資安 | I |
| IT / Ops | I |

## 5. User Stories

**US-01（主要）**
> As a 主任, I want 日檢視開啟後當天有排課的老師欄自動出現在最左側, so that 我不需要橫向捲動就能確認今日課表。
>
> Acceptance Criteria：
> - [ ] 選定任一天後，`visibleTeachers` 陣列中「當日有課」的老師全部排在「當日無課」老師之前
> - [ ] 同為「有課」者，維持原有 roomLabel → username 排序
> - [ ] 同為「無課」者，維持原有 roomLabel → username 排序
> - [ ] 切換日期後排序即時更新，無需手動重整

**US-02（可選 P1）**
> As a 主任, I want 一個「隱藏今日無課老師」開關, so that 欄位數量大幅縮減，不需橫向捲動。
>
> Acceptance Criteria：
> - [ ] 開關預設關閉（顯示所有老師，但有課優先排左）
> - [ ] 開啟後隱藏當日無課老師欄，同時更新欄數顯示
> - [ ] 開關狀態存入 `localStorage` 供下次保留

## 5b. UI/UX 精緻化需求

| 面向 | 要求 |
|---|---|
| 版面層次 | 排序為純邏輯變更，不新增視覺元素；「有課 / 無課」分界不需加分隔線（避免雜訊） |
| 色彩一致性 | 沿用現有老師欄標題色彩，無需新增 token |
| 互動回饋 | 切換日期時欄位順序應跟隨 `selectedDayIdx` 即時更新（`visibleTeachers` 為 computed，天然響應式），無需額外動畫 |
| 空狀態設計 | 若全部老師當天都無課（例如例假日），欄位仍完整顯示，不出現空白頁；現有「無排課」空狀態訊息保持不變 |
| 載入狀態 | 排序為純前端計算，無 API 額外呼叫，不新增 loading 狀態 |
| 防呆設計 | P1 隱藏開關（若實作）：開關說明文字應為「隱藏今日無課老師」，不得僅用圖示；切換後若欄位數歸零，顯示引導文字「今日無已排課老師」 |
| 響應式 | 日檢視本身為橫向捲動佈局，排序改善後橫向捲動距離縮短即視為改善，不需新增斷點 |

## 6. 功能需求（FR）

- **FR-001**：`visibleTeachers` 排序邏輯修改為三層：`hasCoursesToday`（有課=0優先，無課=1）→ `roomLabel`（localeCompare）→ `username`（localeCompare）
- **FR-002**：`hasCoursesToday` 的判斷以 `filteredCourses` 為準（已含 `day_of_week`、取消狀態過濾），確保與欄位實際顯示內容一致
- **FR-003（P1）**：新增 `hideEmptyTeacherColumns` ref（Boolean，預設 `false`）；為 `true` 時在 `visibleTeachers` 過濾掉 `!hasCoursesToday` 的老師；此 ref 狀態存入 `localStorage.smart_calendar_hide_empty_teachers`
- **FR-004（P1）**：隱藏開關 UI 放置於日檢視頂部篩選列（與現有 `teacherSearch`、`roomFilter` 同區），樣式與現有篩選器一致

## 7. 非功能需求（NFR）

- 排序為純 computed 運算，不增加任何 API 呼叫，效能影響可忽略
- `visibleTeachers` 已是 `computed`，切換日期後自動響應，無需額外 watch
- 不得破壞現有 `roomFilter`、`teacherSearch`、`studentSearch`、`filterTeacherId`、`isTeacher` 等過濾邏輯

## 8. 技術方向

**受影響頁面：** `frontend/src/pages/SmartCalendar.vue`（僅此一個檔案）

**受影響邏輯區塊（行數為當前版本參考）：**
- `visibleTeachers` computed：第 1575–1629 行，修改最後的 `.sort()` 比較子，加入 `hasCoursesToday` 分群

**方法說明：**
- 在 sort 前，為每個 `t` 計算一個布林值：`filteredCourses.value` 中是否存在 `teacher_id === t.id && day_of_week === selectedDow.value`（且未取消）
- 將布林值轉為排序數字（`0` = 有課，`1` = 無課），作為第一鍵
- 保留原來 `roomLabel` → `username` 作為第二、三鍵

**無需 migration，純前端改動。**

子任務派發：
- `[FEATURE]` → 修改 `visibleTeachers` sort 邏輯（P0）、實作隱藏開關（P1）
- `[TEST]` → 驗收測試（手動 + 邊界案例）
- `[DOCS]` → 更新 `docs/CHANGELOG.md`

## 9. 資安與存取控制

- 純前端排序邏輯，不涉及新 API 路由或新資料存取
- `localStorage` 僅存 UI 偏好（Boolean），無 PII
- STRIDE 評估：無新增風險

## 10. QA 驗收標準

**FR-001 / FR-002：**
- Happy Path：選定週一，A 老師有課、B 老師無課 → A 欄在 B 欄左側
- Edge：當天所有老師都有課 → 排序退化為 roomLabel → username，行為與舊版一致
- Edge：當天所有老師都無課（例假日）→ 排序退化為舊版，無崩潰
- Edge：切換至週二，B 老師有課、A 老師無課 → 欄位順序反轉
- Regression：`roomFilter`、`teacherSearch`、`studentSearch` 過濾後結果仍符合「有課優先左」

**FR-003 / FR-004（P1）：**
- Happy Path：開啟隱藏開關 → 無課老師欄位消失，有課老師欄位保留
- Edge：隱藏後當天無課 → 顯示引導文字「今日無已排課老師」
- Edge：重新整理頁面 → 開關狀態從 localStorage 恢復

**UI/UX 驗收清單：**
- [ ] 切換日期後欄位順序即時更新，無 layout shift
- [ ] 隱藏開關（P1）說明文字清晰，非純圖示
- [ ] 所有老師欄仍保留正確的課程 block（排序不影響課程資料）
- [ ] 色彩 / 間距 / 字型層次無視覺突兀點

## 11. 上線與維運

1. 修改 `SmartCalendar.vue` 後執行 `cd frontend && npm run deploy`
2. 確認 `backend/public/index.html` 與 `assets/` 已同輪更新（防 MIME 錯誤，見 `docs/AI_REGRESSION_LESSONS.md`）
3. 無 migration，無需後端重啟
4. 回滾：git revert 該 commit，重新 deploy

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| P0 | 修改 `visibleTeachers` sort：有課優先左 | 0.5h | `[FEATURE]` |
| P0 | 更新 CHANGELOG | 0.1h | `[DOCS]` |
| P1 | 隱藏空白老師欄開關（含 localStorage 保存） | 1h | `[FEATURE]` |

## 13. 風險、假設、開放問題

- **假設**：`filteredCourses` 是判斷「有課」最準確的資料來源（已含取消過濾），直接用此判斷可確保排序與欄位實際顯示一致
- **風險（低）**：若某老師只有取消的課（全天 `cancelled`），判斷結果為「無課」，欄位仍存在但排至右側 — 此為預期行為
- **已解決：P1 隱藏開關預設值 → 預設關閉（顯示所有老師，有課優先左）**
  - **根因**：日檢視空白時間格為可點擊區域（`SmartCalendar.vue` 第 182 行 `@click="!isTeacher && onSlotClick(...)"`），主任點擊空格即可為該老師新增排課。若預設隱藏空白老師欄，主任將無法看到目標老師的欄位來點選時間格，破壞「快速排課」核心工作流程
  - **結論**：預設 OFF（顯示全部 + 排序），P1 開關開啟時視為「純覽模式」（review-only）；開關 label 應明示：「只顯示今日有課老師」，提醒使用者開啟後無法透過點擊空格新增排課
  - P0 排序改善本身已解決主要捲動痛點，隱藏開關為錦上添花

## 14. Definition of Done

- [ ] FR-001、FR-002 通過 QA 手動驗收
- [ ] UI/UX 驗收清單全部打勾，UI/UX Designer sign-off
- [ ] 資安審查無阻擋項（本次無風險）
- [ ] `npm run deploy` 完成，`index.html` + assets 同輪
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM sign-off
