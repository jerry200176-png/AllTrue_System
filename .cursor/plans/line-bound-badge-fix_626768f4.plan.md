---
name: line-bound-badge-fix
overview: 修正兩個 bug：(1) 解除綁定後前端 students 列表 line_bound 未即時更新；(2) 後端 line_bound 讀 Student.LineID 而非 student_line_bindings，導致多家長情境下顯示不準。
todos:
  - id: fix-backend-source
    content: "[FEATURE] StudentController index()/show() 改用 student_line_bindings 批次查詢決定 line_bound，取代 Student.LineID"
    status: completed
  - id: fix-frontend-cache
    content: "[FEATURE] StudentsList.vue removeLineBinding() 成功後同步更新 students.value 中對應學生的 line_bound"
    status: completed
  - id: qa-verify
    content: "[QA] 驗收：解除後徽章消失、多家長任一解除後其餘仍顯示、重新整理結果一致"
    status: completed
  - id: changelog-fix
    content: "[DOCS] 更新 docs/CHANGELOG.md 記錄此 bug fix"
    status: completed
isProject: false
---

# PRD — LINE 綁定徽章解除後仍顯示（Bug Fix）

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | LINE 綁定徽章即時同步修正 |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 主任（操作解除綁定後，列表應即時反映） |

---

## 2. 目標與業務背景

**痛點**：主任在學生管理頁點擊編輯 modal，解除 LINE 綁定後，關閉 modal，學生列表的綠色 `LINE` 徽章仍然顯示，與實際綁定狀態不符，導致主任無法信任畫面資訊。

**業務價值**：
- 主任操作後畫面立即反映結果，不需重新整理頁面
- 多家長情境（爸爸、媽媽各自綁定）下，解除其中一筆後另一筆仍正確顯示

**成功指標**：
- 解除最後一筆綁定 → 徽章消失，不需重整
- 解除其中一筆（另一筆仍存在）→ 徽章保留
- 重新整理頁面 → 結果與操作後一致

---

## 3. 範圍

**In Scope**：
- 前端 `removeLineBinding()` 成功後同步更新 `students.value[x].line_bound`
- 後端 `StudentController::index()` 與 `show()` 改用 `student_line_bindings` 批次查詢判斷 `line_bound`，取代 `Student.LineID`

**Out of Scope**：
- 解綁 UI 外觀調整
- `Student.LineID` 欄位本身的清除邏輯（已在前次實作，不動）

---

## 4. RACI

| 角色 | R/A/C/I |
|---|---|
| PM | A |
| CTO / 工程 | R |
| UI/UX Designer | I |
| QA | R |
| 資安 | I |
| IT / Ops | I |

---

## 5. User Stories

**US-01**（解除後徽章即時消失）
> As a 主任, I want 解除學生最後一筆 LINE 綁定後關閉 modal，列表徽章立即消失, so that 我確認操作已生效。
>
> Acceptance Criteria：
> - [ ] 解除最後一筆 → `line_bound` badge 立即消失，不需重整頁面
> - [ ] 解除其中一筆（仍有其他綁定）→ badge 保留

**US-02**（重整後結果一致）
> As a 主任, I want 重新整理頁面後的 line_bound 狀態與 student_line_bindings 一致, so that 系統資料可信。
>
> Acceptance Criteria：
> - [ ] 重整後 `line_bound` 依 `student_line_bindings` 是否有記錄決定，而非 `Student.LineID`

---

## 5b. UI/UX 精緻化需求

本次為 bug fix，UI 外觀不變。`line_bound` 徽章邏輯修正後行為與視覺規格不變（綠色 `LINE` 標籤，顯示/不顯示）。

---

## 6. 功能需求（FR）

| 編號 | 描述 |
|---|---|
| **FR-001** | `StudentController::transformStudent()` 的 `line_bound` 改為接受外部傳入的 `$boundIds` 集合（`Collection::flip()` 結果），用 `isset($boundIds[$s->id])` 判斷 |
| **FR-002** | `StudentController::index()` 在 map 前批次查詢 `StudentLineBinding::whereIn('student_id', $ids)->pluck('student_id')->flip()` 並傳入 transform |
| **FR-003** | `StudentController::show()` 改用 `StudentLineBinding::where('student_id', $student->id)->exists()` |
| **FR-004** | `StudentsList.vue::removeLineBinding()` 成功後，找到 `students.value` 中對應學生並以 `lineBindings.value.length > 0` 更新其 `line_bound` |

---

## 7. 非功能需求

- **效能**：FR-002 為一次 `whereIn` 批次查詢，不產生 N+1；列表 500 筆時仍只有 1 條額外 SQL
- **向下相容**：`Student.LineID` 欄位不異動；其他讀取 `Student.LineID` 的地方（LINE webhook、家長入口）不受影響

---

## 8. 技術方向

**根本原因分析**：

```
Bug 1（前端）：removeLineBinding() 只更新 lineBindings.value（modal 清單）
               → 未同步 students.value[x].line_bound
               → 關閉 modal 後徽章仍顯示舊快取值

Bug 2（後端）：transformStudent() 讀 Student.LineID
               → Student.LineID 只記錄最後綁定者
               → 解除最後綁定者 → LineID 清空 → line_bound=false（正確）
               → 但解除非最後綁定者 → LineID 仍有值 → line_bound=true（誤）
               → 重整後仍不準
```

**受影響檔案**：
- [`backend/app/Http/Controllers/StudentController.php`](backend/app/Http/Controllers/StudentController.php)（FR-001～FR-003）
- [`frontend/src/pages/StudentsList.vue`](frontend/src/pages/StudentsList.vue)（FR-004）

**後端修改要點**（`index()`）：
```php
// students 取得後，在 map 之前：
$boundIds = \App\Models\StudentLineBinding
    ::whereIn('student_id', $students->pluck('id'))
    ->pluck('student_id')->flip();
return $students->map(fn($s) => $this->transformStudent($s, $boundIds));
```

**前端修改要點**（`removeLineBinding()` 成功分支）：
```javascript
lineBindings.value = lineBindings.value.filter(b => b.id !== bindingId);
const idx = students.value.findIndex(
  s => s.id === studentId || s._laravelId === laravelId
);
if (idx !== -1) {
  students.value[idx] = {
    ...students.value[idx],
    line_bound: lineBindings.value.length > 0,
  };
}
```

---

## 9. 資安與存取控制

- 不新增 API，不改權限設定
- `line_bound` 為公開的列表欄位，不含 `line_user_id` 完整值，無隱私疑慮

---

## 10. QA 驗收標準

### FR-001～FR-003（後端）
- Happy Path：`GET /api/v1/students?branch_id=X` 回傳的 `line_bound` 與 `student_line_bindings` 筆數一致
- Edge：`Student.LineID` 有值但 `student_line_bindings` 無記錄 → `line_bound = false`
- Edge：`Student.LineID` 為 null 但 `student_line_bindings` 有記錄 → `line_bound = true`

### FR-004（前端）
- Happy Path：解除最後一筆 → 不重整，徽章立即消失
- Edge：解除其中一筆（仍有剩餘）→ 徽章保留
- Edge：取消 confirm dialog → 徽章不變

### UI/UX 驗收清單
- [ ] 解除後不重整，徽章狀態即時正確
- [ ] 重整後結果與操作後一致

---

## 11. 上線與維運

1. 部署後端（`StudentController` 修改）
2. 執行 `cd frontend && npm run deploy`
3. 驗證：開啟有 LINE 綁定學生的 modal，解除後關閉，確認徽章消失

---

## 12. 里程碑與優先級

| 優先級 | 項目 | Agent |
|---|---|---|
| P0 | 後端 `line_bound` 改用 `student_line_bindings` | `[FEATURE]` |
| P0 | 前端 `removeLineBinding()` 同步 `students.value` | `[FEATURE]` |
| P1 | QA 驗收 | QA |
| P2 | CHANGELOG 更新 | `[DOCS]` |

---

## 13. 風險 / 假設 / 開放問題

**風險**：
- 低：`index()` 加一條 `whereIn` 批次查詢，學生數 ≤ 500 時效能無影響

**假設**：
- `student_line_bindings` 為 `line_bound` 的唯一可信來源（已有 unique index 防重）

---

## 14. Definition of Done

- [ ] FR-001～FR-004 全數通過 QA 驗收
- [ ] 解除後不重整，徽章即時反映
- [ ] 重整後後端回傳結果與 `student_line_bindings` 一致
- [ ] `npm run deploy` 完成
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM / 工程 Lead sign-off
