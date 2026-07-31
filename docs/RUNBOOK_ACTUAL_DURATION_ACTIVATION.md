---
owner: platform
review_cycle: quarterly
last_reviewed: 2026-07-31
---

# RUNBOOK — 依實際時長扣堂：上線啟用與回滾

> **REFERENCE ONLY — NO DECISION OR EXECUTION AUTHORITY.**
> 產品契約：[`docs/architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md`](architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md)。
> 決策：Founder。**啟用執行：`.github/workflows/actual-duration-activation.yml`（Founder-gated，唯一啟用/回滾路徑）**。
> 一般部署：`deploy.yml`。回滾通則：[`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md)。

## 現況（會隨每次啟用動作更新，不要用記憶判斷，永遠以下方 §1.5 的即時查詢結果為準）

`deployed`（程式碼已到 Pi）／`migrated`（欄位已存在）／`backend flag enabled`（環境旗標）／`frontend option visible`（前端可選）／`production course verified`（有一門課走完全流程驗證）是**五件不同的事**，不要混用：

| | 狀態 | 依據 |
|---|---|---|
| **deployed** | ✅ 是 | `deploy.yml` 在每次 merge 到 `main` 後自動觸發並執行；已對本功能的每個 backend/frontend 變動 PR 執行過，health + 完整 authenticated smoke 皆通過 |
| **migrated** | ✅ 是 | `standard_lesson_minutes`／`deduction_basis` 已於 schema PR 合併時，隨同一次自動部署一併於正式環境套用（`php artisan migrate --force`，含事前 `mysqldump` 備份）|
| **backend flag enabled** | ❌ 否（除非下方 §1.5 查出來是 true） | `PERF_ACTUAL_DURATION_DEDUCTION` 從未被設過；預設 `false` |
| **frontend option visible** | ❌ 否 | `ACTUAL_DURATION_DEDUCTION_ENABLED` 編譯期常數仍為 `false`，尚未有啟用 PR 合併 |
| **production course verified** | ❌ 否 | 尚未有任何 `actual_duration` 課程在正式環境被建立與走完點名流程 |

**"deployed" ≠ "production 已啟用"。** 程式碼與 migration 已經在正式環境，但這只代表引擎「已就位」，不代表任何課程或使用者行為受影響——兩個旗標仍是 `false`，所有既有課程仍是 `fixed_session`，行為零變化。

---

## 0. 這個功能到底改了什麼（一段話）

每一門課可以自己定義「標準一堂 = 幾分鐘」，點名時依**實際上課時長**按比例扣除額度。
「一堂」是**該課程自己的**定義，不是全公司的規定：同樣一次 180 分鐘的課，在標準 90 / 120 / 180 分鐘的課程裡分別扣 **2.00 / 1.50 / 1.00** 堂。
沒有選擇這個模式的課程，行為完全沒有改變。

---

## 1. 兩個開關，缺一不可

| 開關 | 位置 | 意義 | 預設 |
|---|---|---|---|
| `PERF_ACTUAL_DURATION_DEDUCTION` | Pi `/home/admin/backend/.env` → `config/perfflags.php` | 這個環境有沒有這個功能 | **false** |
| `StudentClass.deduction_basis` | 每門課一列 | 這門課要不要用 | `fixed_session` |
| `ACTUAL_DURATION_DEDUCTION_ENABLED` | `frontend/src/lib/perfFlags.js`（編譯期） | 建課表單要不要顯示這個選項 | **false** |

**Fail-safe，不是 fail-open。** 環境旗標為 false 時，就算某門課已經被標記成 `actual_duration`，它的扣堂行為仍然**完全等同** `fixed_session`。因此關掉旗標就是完整回滾，不需要任何資料遷移，也沒有半套狀態要清。

建課 API 也受環境旗標管制：旗標關閉時，API 會直接 422 拒絕建立 `actual_duration` 課程，而不是建立一門「契約寫著按時長計費、實際卻整堂扣」的課。

### 1.5 如何不猜、直接查目前旗標是不是真的是 false

不要相信「上次設的是 false」這種記憶。跑：

```
Actions → Actual-Duration Billing Activation (Founder-gated) → Run workflow
  action = verify_backend
```

這是唯讀動作，不需要確認字串。回報：production HEAD、**effective config value**（不是 .env 文字，是 Laravel 實際載入後算出來的值）、health、schema 欄位是否存在、現有課程的 `deduction_basis` 分布（應該全部是 `fixed_session`，除非已有指定測試課程）。

---

## 2. 啟用前必須先看的東西（Phase 0A 盤點）

**先跑唯讀盤點，再決定要不要開。** 走同一個 workflow：

```
Actions → Actual-Duration Billing Activation (Founder-gated) → Run workflow
  action  = inventory
  details = false（除非明確需要 StudentClassID／ClassSessionID／CampusID，才打開）
```

這個底層指令 `sessions:report-nonstandard-duration` 不寫任何資料，輸出開頭明示 `READ_ONLY=true`；workflow 會把整段輸出連同 production HEAD、時間戳記、workflow run ID 一起存成 90 天的 audit artifact。`--details` 只會帶出 ID，**不會**輸出學生姓名、電話或 RFID。

輸出範例：

```
RFC non-standard-duration Phase 0A inventory (READ_ONLY=true)
generated_at=... courses_scanned=2
B1 mismatch vs each course own contract: courses_with_SessionDuration=2 affected_courses=0 | happened: sessions=0 courses=0 | planned: sessions=0 courses=0
B2 ledger adoption: rows_with_minutes=0 courses_with_minutes=0 courses_partial=0 reverse_net_minutes_nonzero=0
```

B1 是「排課時長與該課程自己的契約時長不符」的規模，並且分成**已發生**（happened，已經扣過堂）與**尚未發生**（planned，還能改）。B2 是分鐘制 ledger 的採用情形。

判讀：
- 數量為 0 → 沒有現存受影響課程，啟用風險最低（只影響之後新建的課）。
- 數量不為 0 → 這些是**現存的**不一致，**不會**被這次啟用自動改變（既有課程一律維持 `fixed_session`）。它們是另一個題目，不是這次啟用的前置條件，但 Founder 應該知道規模。

---

## 3. 啟用步驟

> ⛔ **不透過本 workflow 的任何啟用方式一律禁止**：不 SSH 進 Pi 手動改 `.env`、不對 `.env` 做 `echo ... >>` 之類的直接追加、不在 Pi 上跑 `config:clear`／`route:clear`／`cache:clear`／`optimize:clear`（事故 B 教訓——用 `optimize` 重建，不是清除）。以下每一步都需要 Founder 明確授權；`enable_backend`／`disable_backend` 需要在 workflow 輸入打上完全一致的確認字串才會執行。

1. **確認 migration 已套用。**（已完成——見上方現況表；本步驟只是留給往後從頭啟用另一個環境時參考，不是本次待辦。）

2. **開啟後端旗標。**

   ```
   Actions → Actual-Duration Billing Activation (Founder-gated) → Run workflow
     action             = enable_backend
     confirm            = ENABLE ACTUAL DURATION BILLING     # 一字不差
     expected_head_sha  = <Founder 核准要生效的那個 main commit 的完整 40 碼 SHA>
   ```

   這個 workflow 會（全部稽核存證，見 workflow 檔內註解與 §「稽核與可回溯」）：
   - 先比對 production HEAD 是否等於 `expected_head_sha`，不符就直接拒絕，`.env` 不動
   - 備份 `.env`（含時間戳記與 checksum），**只**替換或新增這一行，絕不整檔覆寫，絕不留下重複的 key
   - 用既有安全序列重建快取（`php artisan optimize` + `opcache:reset`），不是 `config:clear`
   - 讀 Laravel **實際生效**的 config 值（不是只看 `.env` 文字）確認真的變成 `true`
   - 跑 health + 完整 authenticated smoke suite
   - 唯讀比對現有課程的 `deduction_basis` 分布，確認沒有意外的非 `fixed_session` 課程
   - **任何一步沒過，自動還原剛才備份的 `.env`、重建快取，並讓 workflow 失敗**——不會停在半套狀態

   此時：後端引擎已就緒，但沒有任何課程是 `actual_duration`，所以**行為上什麼都不會變**。這一步是為了讓引擎先在正式環境待命，而不是引擎與課程同時出現。

3. **驗證什麼都沒變。**

   ```
   Actions → Actual-Duration Billing Activation (Founder-gated) → Run workflow
     action = verify_backend
   ```

   確認：effective config = `true`、health = `ok`、`deduction_basis` 分布仍是全 `fixed_session`（除非已進到步驟 5 的指定測試課程階段）。

4. **開前端旗標。**
   `frontend/src/lib/perfFlags.js` 的 `ACTUAL_DURATION_DEDUCTION_ENABLED` 改為 `true` → PR → CI 全綠 → **等步驟 2 的 `enable_backend` 回報成功後才 merge** → 等 `deploy.yml` 自動部署 → 驗 `version.json`（Y3）。
   此時建課表單才會出現「扣堂方式」選項。**在後端旗標確認開啟之前合併這個 PR，會讓老師在 UI 選了選項卻 100% 收到 422**，不是「提前上線」，是壞掉的 UX——順序不可反。

5. **第一門課由 Founder 指定，並且全程有人看著。**
   需要 Founder 提供：學生 ID／老師 ID／分校 ID（或明確授權使用某個現成的指定測試身分）。**不得**使用一般真實付費課程的學生，也不得由 AI 自行代選一個「看起來像測試」的現有學生。
   建課後立即比對：
   - 課程列表顯示的 `remaining_lesson_equivalent` 與 `remaining_hours`
   - 每一次點名後 `RemainingMinutes` 的變化量 = 該次實際分鐘數
   - `session_deduction_ledger.minutes` 記到的是分鐘，不是堂數
   - 額度用盡後點名依然成功（D5：超額不擋點名）
   - 扣堂後修改標準堂長回 422（`BillingContractLockGuard`）

---

## 4. 回滾

**回滾 = 把環境旗標關掉，走同一個 Founder-gated workflow。** 沒有資料要還原。

```
Actions → Actual-Duration Billing Activation (Founder-gated) → Run workflow
  action  = disable_backend
  confirm = DISABLE ACTUAL DURATION BILLING     # 一字不差
```

這是**主要的執行期回滾路徑**：備份 `.env`、把 `PERF_ACTUAL_DURATION_DEDUCTION` 改回 `false`、重建快取、驗證 effective config 真的變回 `false`、驗證 health。不需要 `expected_head_sha`（緊急回滾不應該被這道檢查卡住）。

關掉之後：
- 已經標記為 `actual_duration` 的課程，扣堂行為立刻回到 `fixed_session`
- 已經寫入 ledger 的分鐘數**不會**被改寫（那是歷史事實，不是要清理的髒資料）
- 建課 API 會開始拒絕新的 `actual_duration` 課程

如果需要連程式碼一起退掉，走 [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) §3a 的 revert PR 路徑。migration 本身是 additive 的，留著不會影響任何既有行為；**不建議**為了回滾而 drop 欄位（會讓已建立的課程失去契約紀錄）。

---

## 5. 已知限制（v1 刻意不做）

| 項目 | 狀態 | 原因 |
|---|---|---|
| 跨期借用／自動拆堂 | 不做 | D3。額度用完就是用完，不會回頭從別的期間補 |
| 共用課程包（CoursePackage） | 雙向排除，422 明確拒絕 | D4。共用池 ledger 只能表示整堂（TD-059） |
| 月結制課程 | 尚不支援，422 拒絕 | 月結的額度語意還沒定義 |
| 扣堂後修改契約 | **不提供任何修正管道** | 額度仍由 `SessionCount × standard_lesson_minutes` 推導，事後改標準堂長會**重新解釋歷史**。宣稱「只影響未來」會是假保證。要改就結掉重開 |
| 額度授予 ledger | 延後 | D7 |

第一筆扣堂 ledger 寫入後，`standard_lesson_minutes`、`deduction_basis`、`SessionCount` 由 `BillingContractLockGuard` 在**後端**鎖定。前端把欄位變灰只是 UX，後端才是權威。

---

## 6. 監看什麼

| 訊號 | 正常 | 不正常時怎麼辦 |
|---|---|---|
| `RemainingMinutes` 為負 | 不應發生（floor 在 0） | 立刻跑 `disable_backend`，保留現場，開 issue |
| 點名被擋 | **不應發生** | D5 明訂超額不擋點名。若被擋 = bug，立刻 `disable_backend` |
| `remaining_lesson_equivalent` 與 `RemainingMinutes` 不一致 | 不應發生 | 兩者同源，不一致代表換算有問題 |
| 建課 422 `overage_confirmation_required` | 正常，這是設計 | 老師勾確認即可繼續 |

---

## 7. 稽核與可回溯

`enable_backend`／`disable_backend`／`inventory`／`verify_backend` 每次執行都會：
- 只能由對此 repo 有 write 權限的人觸發（GitHub 對 `workflow_dispatch` 的內建限制）
- 在 Pi 上把 `.env` 備份到 `/home/admin/backups/perfflag-activation/`（含時間戳記與 checksum，備份檔本身不會被印出或當成 artifact 內容）
- 產生一份 GitHub Actions artifact（保留 90 天），內容含 production HEAD、觸發者、時間戳記、確認字串是否符合、effective config 結果、health/smoke 結果——**不含**密碼、DSN 或學生個資

目前本 repo 沒有設定 GitHub Environment 保護規則（已逐一檢查 `.github/workflows/*.yml`，沒有任何一個引用 `environment:` 且設有 required reviewers）。本 workflow 引用 `environment: production-activation`，這個名稱本身**目前不提供額外門檻**——如果之後有 repo admin 到 Settings → Environments 幫這個名稱加上 required reviewers，這個 workflow 會自動、不需改任何程式碼地開始要求那個核准。在那之前，實際的門檻是：write 權限 + 一字不差的確認字串 + （`enable_backend` 專屬）SHA 比對。

---

## 8. 相關文件

- 產品契約與決策 D1–D7：[`architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md`](architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md)
- 啟用/回滾 workflow：[`.github/workflows/actual-duration-activation.yml`](../.github/workflows/actual-duration-activation.yml)
- 回滾通則：[`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md)
- 危險操作紅線：[`DANGEROUS_OPERATIONS.md`](DANGEROUS_OPERATIONS.md)
- 部署流程：[`DEPLOYMENT.md`](DEPLOYMENT.md)
