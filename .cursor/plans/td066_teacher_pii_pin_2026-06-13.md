# P3 清償計畫 — TD-066：老師頁 PII 後端 PIN 強制

> Tech-debt 清償（非完整 PRD）：[ARCH] → [DEV] → [TEST] → [REVIEW]。對應 `docs/TECH_DEBT.md` TD-066。

## 1. 問題（[ARCH]）
#769 D2 把「老師管理」列為敏感頁，但其資料端點 `GET /api/v1/teachers`（= `ProfileController::index` role=teacher）與 `GET /profiles*` 被 **CourseManagement／StudentsList／LearningRecordsPage** 等非敏感頁當老師下拉/篩選共用。整路掛 `require_pin` 會在已設 PIN 的主任於那些頁取老師清單時被 423 誤擋（Phase C 因此刻意未掛）。後果：老師 PII（phone／line_id／rfid）只靠 Phase B 前端 gate 保護，繞過前端直打端點仍可取得 → 與 #769 KPI「後端 100% 擋下」有缺口。

## 2. 方案比較（[ARCH]）
| 方案 | 作法 | 取捨 |
|---|---|---|
| A 拆專屬端點 | 新增 `teachers/directory`（掛 require_pin）+ 瘦身共享 `/teachers` | 需改 4 個前端呼叫點 + 改共享回應形狀，回歸風險高；且 `/profiles/{id}` 詳情也漏 PII，需多點 gate |
| **B 欄位級遮罩（採用）** | 控制器層依 `PinGate::isUnlocked()` 遮罩 PII 欄位 | 單一 choke point、零前端改動；下拉本就不讀 PII → 不受影響；soft（未設 PIN）零回歸 |

採 **B**：大廠常見做法（field-level authorization / response shaping by policy，如 GitHub API 對未授權者隱去 email、Stripe 對 restricted key 遮罩欄位），比「複製一條平行端點」維護成本低、漏點少。

## 3. 實作（[DEV]）
- `app/Support/PinGate::isUnlocked(Request): bool` — 抽出「本 request 是否通過 PIN（或豁免）」單一真相：super_admin／未設 PIN／token `pin_verified_until` 未過期 → true。
- `RequirePin` middleware 改為委派 `PinGate::isUnlocked()`（行為不變，去重）。
- `ProfileController::index`：頂部算 `$pinUnlocked`，於三個輸出點（single／list-all／paginate）經 `redactTeacherPii()` 在未通過時清空 `phone/line_id/rfid/rfid_by_branch`。

## 4. 測試（[TEST]）
- `PinVerificationTest`（既有 14 綠，RequirePin 改委派後仍綠 → 驗證 PinGate 行為等價）。
- `TeacherPiiPinRedactionTest`（新增 3）：未設 PIN→phone 可見（零回歸）；已設未驗證→phone 遮罩；驗證後→phone 恢復。
- PHPStan：PinGate 的 `AuthToken::where()` Eloquent magic 假陽性納入 baseline（與既有機制一致，零刪除）。

## 5. 風險 / 回滾
- soft：production 目前無人設 PIN → 行為完全不變，零回歸。
- 影響面僅「已設 PIN 且未驗證」之非 super_admin 主任的 PII 欄位；下拉頁不讀這些欄位故無感。
- 單一 PR 可獨立 revert。

## 6. DoD
- TD-066 由前端-only 防護升級為後端欄位級強制；CI 綠；標記 TD-066 為 Resolved。
