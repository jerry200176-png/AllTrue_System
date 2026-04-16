# 手動排課日期語意（產品規則，勿擅自更動）

本檔為 **AllTrue 補習班管理系統** 之**固定業務規則**，供工程師與 AI Agent 實作／重構時對照。  
**未經產品／營運方明確同意，不得修改下列語意或繞過行為。**

---

## 1. 手動點選月曆上的日期（`confirmed_dates` 來源）

使用者於 **`UniversalClassScheduler.vue`**（課程／批次排課）月曆上**手動勾選**的日期，會經 `isManualDateConfirmed(ymd)` 分成兩類送後端：

（**註**：前端正向入口**已無**「新生入班精靈」；舊 `EnrollmentWizard.vue` 已自 repo 移除。若日後重做第二套 UI，手動日語意仍須與本節一致。）

| 條件 | 語意 | 後端欄位 |
|------|------|----------|
| **日期早於「今天」**（日曆日 `< 今天 0 點）** | **已上完／補登** | 進 **`confirmed_dates`** → `EnrollmentService` 建立 **`completed`** 堂次並走已核准評量與扣堂流程（與既有補登一致）。 |
| **日期等於今天** | 若**已過該日課程下課時間** → 視為已上完；否則視為預排（與未來日相同邏輯分支）。 | 同上函式內 `isTodaySessionEnded()`。 |
| **日期晚於今天** | **預排** | 進 **`future_dates`**（與系統依星期推算之未來日一併送出）。 |

### 為何不能改成「過去手動＝預排」

營運方明確要求：**手動點過去＝就是要當成已上完**（補登／已發生堂次），不可用「隔天建課、首堂在昨天」等理由自動改語意，否則會與現場補登習慣衝突。

若未來要支援「錨點在昨天但仍未上」的另一種流程，須**另開 UI／明確選項**（例如獨立勾選「僅錨點不扣堂」），**不可**偷偷改寫現有 `isManualDateConfirmed` 規則。

---

## 2. 後端 `EnrollmentService` 與 `future_dates`

- **`future_dates`** 內之日期仍須 **≥ 今天**（驗證拒絕更早日期）。
- **`confirmed_dates`** 僅能為 **今天或過去**（且「今天」須已過下課時間才可標已上）。

與上節前端規則對齊；**勿**為單一案例在 `create`／`enrollment` mode 放寬「過去日放進 future_dates」等例外，除非產品書面規格更新並同步本檔。

---

## 3. 已移除之前端「入班精靈」

- 產品**不再提供**前端正向「新生入班精靈」；`StudentsList` 等頁面**不應**再掛載舊精靈元件。
- **`POST /api/v1/enrollments`** 與 `EnrollmentController`／`EnrollmentService` 仍為後端能力（測試、腳本或日後新 UI 可用）；**手動日期語意**若經任何新前端呼叫，仍須與第 1 節一致。

---

## 4. 關聯程式位置（修改前必讀本檔）

| 區塊 | 路徑 |
|------|------|
| 排課 modal 手動日判斷 | `frontend/src/components/UniversalClassScheduler.vue` → `isManualDateConfirmed` |
| 批次／入班 API | `backend/app/Services/EnrollmentService.php` |
| 批次路由驗證 | `backend/app/Http/Controllers/ClassSessionController.php`、`EnrollmentController.php` |

---

## 5. 開課日欄位（`course_start_date`）

2026-04-16 新增。在 `UniversalClassScheduler.vue`（`mode="create"`）中新增「開課日」date picker，預設值為今天。

### 前端行為

- **自動排課起算日**：`futureSessionOccurrences` 掃描起始日改為 `max(今天, 開課日)`，確保開課日前不會自動產生預排堂次。
- **月曆跳轉**：設定開課日後，月曆自動切換至開課日所在月份。
- **早於開課日警示**：手動點選未來日期且 `ymd < course_start_date` 時，前端彈出確認框提示「此日早於開課日，將視為補登」。

### 後端行為

- `POST /api/v1/class-sessions/batch` 接受 optional `course_start_date`（`nullable|date`）。
- `EnrollmentService::store` 對 `kind='future'` 的 session_plan 資料驗證 `session_date >= course_start_date`；不符回 422。
- `course_start_date` 缺失時行為與現行完全一致（向下相容）。

### 與 `isManualDateConfirmed` 的關係

- 開課日欄位**不改變** `isManualDateConfirmed` 的語意。過去日仍為「補登已上」，未來日仍為「預排」。
- 開課日僅影響**自動排課起算點**與**防禦性驗證**，不影響手動日期的 confirmed/future 分類邏輯。

---

## 6. 變更紀律

- 調整上述語意＝**變更產品契約**，須：更新本檔、更新／新增測試、並在 `docs/CHANGELOG.md` 或 release note 說明。
- AI Agent：**禁止**以「避免誤扣堂」等理由單方面改回「過去＝預排」；若遇堂數爭議，應以營運流程或**新選項**處理，而非覆寫本規則。
