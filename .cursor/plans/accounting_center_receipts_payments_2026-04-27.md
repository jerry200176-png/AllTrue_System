# 帳務中心：收款紀錄與收據查詢 PRD

## 1. 文件資訊
| 欄位 | 內容 |
|---|---|
| 功能名稱 | 帳務中心：收款紀錄與收據查詢 |
| 版本 | v1 |
| 狀態 | [PLAN] 待批准 |
| 日期 | 2026-04-27 |
| 目標角色 | 主任 / 行政 / super_admin |

## 2. 目標與業務背景
目前系統已有核帳登記、付款回報、電子收據與催繳名單，但入口分散，且「已收款」與「收據」缺少一個可直接查詢、篩選、匯出的地方。主任需要能快速回答：「某天收到多少現金與匯款？某位學生的收據在哪裡？這筆是不是預收？第一堂課是哪天？」

本功能目標是建立一個清楚的帳務工作台，不再只從催繳名單反查付款，而是提供已收款的 payment ledger（收款流水）與 receipt registry（收據台帳）。

KPI：
- 主任可在 30 秒內查到指定學生收據。
- 主任可依日期區間匯出已收款 CSV/PDF。
- 每筆收款清楚顯示現金、匯款、合計與第一堂課日期。
- 不增加側欄雜亂度。

## 3. 範圍
In Scope：
- 將既有側欄 `催繳名單` 升級/改名為 `帳務中心`，不新增更多財務側欄項目。
- `帳務中心` 內使用 tabs：`待收與核帳`、`收款紀錄`、`收據紀錄`。
- 第一版 `收款紀錄` 僅列已核帳付款，不列未繳或預計應收。
- `收款紀錄` 欄位包含：繳費日期、學生姓名、科目、第一堂課日期、現金金額、匯款金額、合計、收款狀態/標籤、收據操作。
- `收據紀錄` 支援查詢所有已確認收據，查看/下載單張 PNG。
- `收款紀錄` 支援 CSV 匯出與精美 PDF 批次匯出。
- 預收判斷：`繳費日期 < 第一堂課日期` 時顯示 `預收` 標籤。

Out of Scope：
- 不修改 `AlertController::tuition` 催繳/續課提醒列入規則。
- 不做線上金流或自動對帳。
- 不新增信用卡、刷卡、第三方支付。
- 不做未收款預測報表；未繳仍由既有催繳/待收 tab 處理。
- 不改變堂數扣除、月結計算或加購結案規則。

## 4. RACI
| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（UI/UX） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（資安審查） | `[REVIEW]` | R |
| AI Agent（Code Review） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類（可閱讀） | 使用者 | I |

## 4b. Dependencies
| 類型 | 說明 | 狀態 |
|---|---|---|
| 既有資料表 | `Payment`、`PaymentReport`、`Invoice`、`StudentClass`、`ClassSession` | 已存在 |
| 既有 UI | `TuitionCollectionPage`、`PaymentEntryModal`、`ReceiptModal` | 已存在 |
| 既有 API | `GET /api/v1/payment-reports`、`GET /api/v1/payment-reports/{id}/receipt` | 已存在但不完全符合清單需求 |
| 外部服務 | 無；PDF/CSV 可由前端或後端產生 | 待 ARCH 選型 |
| 高風險規則 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | 已讀，本次不改提醒條件 |

## 5. User Stories + AC
- As a 主任, I want 在同一個帳務中心處理待收、查收款、查收據, so that 側欄不會越來越雜亂。
  - AC：側欄不新增 `收據`、`繳費清單` 等多個新入口。
  - AC：`帳務中心` 內可切換待收、收款紀錄、收據紀錄。
- As a 主任, I want 查已收款流水, so that 我可以核對每天現金與匯款收入。
  - AC：可依日期區間、學生、科目、付款方式、分校篩選。
  - AC：列表顯示現金金額、匯款金額、合計。
  - AC：表格摘要顯示總現金、總匯款、總收入、筆數。
- As a 主任, I want 看到第一堂課日期與預收標籤, so that 我能辨識這筆錢是課前預收或課後補收。
  - AC：每筆付款顯示該課程最早 `ClassSession.SessionDate`。
  - AC：月結與堂數制都用同一個第一堂課定義。
  - AC：繳費日早於第一堂課時顯示 `預收`。
- As a 行政, I want 可查收據並批次匯出, so that 對帳與交付家長更有效率。
  - AC：可開啟單張電子收據並下載 PNG。
  - AC：可將目前篩選結果匯出 CSV。
  - AC：可將目前篩選結果匯出帶品牌樣式的 PDF。

## 5b. UI/UX 精緻化
| 面向 | 規格 |
|---|---|
| 資訊架構 | 將 `催繳名單` 側欄入口改為 `帳務中心`；頁內 tabs：`待收與核帳`、`收款紀錄`、`收據紀錄` |
| 命名 | 避免「繳費清單」過於籠統；使用 `收款紀錄` 表示已核帳實收流水，使用 `收據紀錄` 表示可查可下載的收據台帳 |
| 版面層次 | 上方 summary cards：筆數、現金、匯款、合計、預收筆數；下方篩選列；主體為可排序表格 |
| 色彩一致性 | `預收` 使用 info/blue 標籤；`已撤銷` 使用 neutral/gray；避免使用 danger 表示正常預收 |
| 互動回饋 | 匯出 CSV/PDF 按鈕需有 loading、完成 toast、失敗 inline error |
| 空狀態設計 | 無資料時顯示「此區間尚無已核帳收款」並提供「切換日期」或「前往待收與核帳」CTA |
| 載入狀態 | 初次載入使用 skeleton table；匯出時不遮住整頁 |
| 防呆設計 | PDF 匯出若超過安全筆數上限，提示縮小日期區間；撤銷/作廢不在本頁直接新增危險入口 |
| 響應式 | 桌機完整欄位；手機改為卡片式，每筆顯示學生、日期、合計、付款方式與收據操作 |
| 無障礙 | tabs 使用 `role=tablist`；匯出與收據按鈕具 `aria-label`；表格可鍵盤操作 |

## 6. 功能需求
- FR-001：側欄財務區不新增多個新入口；將既有催繳入口整理為 `帳務中心`。
- FR-002：`帳務中心` 必須保留既有催繳/核帳功能，作為 `待收與核帳` tab。
- FR-003：新增 `收款紀錄` tab，僅列已核帳付款與必要的撤銷參考，不列未繳款項。
- FR-004：收款資料需支援日期區間、學生姓名、科目、付款方式、分校篩選。
- FR-005：每筆收款需回傳並顯示 `first_session_date`；堂數制與月結制一致使用課程第一堂 `ClassSession`。
- FR-006：若 `payment_date < first_session_date`，列表顯示 `預收` 標籤。
- FR-007：金額欄位需分為 `現金`、`匯款`、`合計` 三欄；非該付款方式欄位顯示 `0` 或 `—`，總額不可重複計算。
- FR-008：`收據紀錄` tab 可查詢所有 confirmed 收據，支援學生、日期、科目、付款方式篩選。
- FR-009：單張收據可沿用現有收據預覽並下載 PNG。
- FR-010：目前篩選結果可匯出 CSV，欄位至少包含繳費日期、學生、科目、第一堂課日期、現金、匯款、合計、付款方式、收據編號。
- FR-011：目前篩選結果可匯出精美 PDF，包含品牌抬頭、日期區間、摘要卡、明細表、產生時間。
- FR-012：不可修改 `AlertController::tuition` 的查詢條件、回傳欄位與提醒文案。
- FR-013：所有查詢必須套用 `require_campus` 多校區隔離；super_admin 可依分校篩選。

## 7. 非功能需求
- NFR-001：預設查詢日期區間為本月，回應時間目標 < 2s。
- NFR-002：CSV 匯出目標支援目前篩選結果至少 5,000 筆；PDF 第一版上限 500 筆，超過時提示縮小區間或改用 CSV。
- NFR-003：表格切換 tab 與本地排序 < 500ms。
- NFR-004：不新增外部金流依賴。
- NFR-005：PDF/CSV 匯出不得包含 token、內部 session、家長電話等非必要 PII。

## 8. 技術方向
- 前端：以既有 `TuitionCollectionPage` 為基礎整理為 `帳務中心`，或新增容器頁承載既有待收 tab 與新 tabs。
- 後端：新增或擴充帳務查詢 API，資料來源以 `PaymentReport` confirmed 記錄與 `Payment` 為主，連結 `StudentClass`、`Student`、`Subject`、`ClassSession`。
- 匯出：CSV 可由前端依 API 結果產生或由後端回傳；PDF 第一版建議前端產生精美報表，避免新增伺服器套件風險，ARCH 再確認現有依賴。
- DB：優先不新增 DB 欄位；若 ARCH 發現 `Payment` 與 `PaymentReport` 關聯不足，再提出 migration。
- 權限：沿用 director/super_admin + `require_campus`。

## 8b. Decision Log
| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-27 | 使用 `帳務中心` tabs 而不是新增多個側欄項目 | 新增 `收據`、`繳費清單` 側欄入口 | 使用者擔心側欄雜亂，tabs 符合大廠 dashboard 資訊架構 |
| 2026-04-27 | 第一版 `收款紀錄` 只列已核帳實收 | 同時列未繳與預計應收 | 避免與催繳/待收混淆，符合 ledger 單一語意 |
| 2026-04-27 | 金額分 `現金`、`匯款`、`合計` | 付款方式一欄 + 金額一欄 | 符合每日對帳與使用者需求 |
| 2026-04-27 | 預收以標籤呈現 | 新增預收款流程或新表 | 第一版降低資料模型風險，可先滿足辨識需求 |
| 2026-04-27 | 不改繳費提醒規則 | 順手調整提醒邏輯 | 提醒規則為高風險且已有明確管制 |

## 9. 資安與存取控制
- Role：director / super_admin 可見；teacher 不開放帳務中心。
- 分校：director 只能查自己授權分校；super_admin 可篩選所有分校。
- PII：列表顯示學生姓名，不顯示家長電話、LINE ID、token、帳號完整資訊；匯款後五碼如需顯示，需在 ARCH 標明最小化策略。
- STRIDE 快評：
  - Spoofing：沿用 Bearer token 與 role middleware，低。
  - Tampering：匯出只讀；若 future 加撤銷需二次確認，中。
  - Repudiation：收據需保留核帳人與核帳時間，中。
  - Information Disclosure：CSV/PDF 匯出含學生姓名與金額，需分校隔離與最小欄位，中。
  - DoS：PDF 大量匯出需筆數上限，低。
  - Elevation：不可新增公開收據查詢端點，低。

## 10. QA 驗收
- Happy Path：主任進入 `帳務中心 > 收款紀錄`，本月資料顯示 summary、現金/匯款/合計與收據按鈕。
- Happy Path：點單張收據可預覽並下載 PNG。
- Happy Path：目前篩選結果可匯出 CSV。
- Happy Path：目前篩選結果可匯出 PDF，PDF 含品牌抬頭、摘要與明細。
- Edge：繳費日期早於第一堂課，顯示 `預收`。
- Edge：月結課程也能顯示第一堂課日期。
- Edge：沒有 `ClassSession` 時第一堂課顯示 `尚未排課`，不阻斷列表。
- Error：PDF 超過 500 筆時提示縮小範圍，不讓瀏覽器卡死。
- Regression：既有催繳名單 tab 仍可核帳、確認、退回與查看收據。
- Regression：`alerts/tuition` 規則不變。
- UI/UX：空狀態、loading、toast、行動版卡片、tabs 可鍵盤操作。

## 11. 上線與維運
- 部署：前端有改，PR merge 且 CI 綠後由 Deploy to Pi 自動部署；不在 feature branch deploy。
- Migration：預期不需要；若 ARCH 決定新增 export audit table 或欄位，需另進 DBA 評估。
- Feature Flag：若只整理現有主任頁與新增只讀查詢，可不加 flag；若改動既有催繳入口較大，ARCH 可加前端 flag `ACCOUNTING_CENTER_ENABLED`。
- Observability：
| 監控項目 | 指標 / log | 告警閾值 | 負責 |
|---|---|---|---|
| 帳務 API 失敗 | 5xx / `accounting-center` log | 上線 24h 內有 5xx 回報 | `[OPS]` |
| PDF 匯出失敗 | 前端 error toast / Sentry | 同版本 3 次以上 | `[OPS]` |
| Health | `/api/v1/health` | 非 200 | `[OPS]` |
- 回滾：`git revert <merge commit>`；無 migration 時可直接回滾；有 migration 時依 DBA down() 設計。

## 12. 里程碑與優先級
- P0 `[ARCH]`：確認資料來源、API 合約、是否需 migration、PDF/CSV 技術選型。
- P1 `[FEATURE]`：帳務中心 tabs 與收款紀錄 API/UI。
- P1 `[FEATURE]`：收據紀錄查詢、單張 PNG、CSV/PDF 批次匯出。
- P1 `[TEST]`：後端 feature tests：分校隔離、預收、月結第一堂課、匯出資料欄位。
- P2 `[REVIEW]`：STRIDE、PII 最小化、FR 對照。
- P2 `[DOCS]`：CHANGELOG，若新增帳務規則則補 docs。
- P2 `[OPS]`：CI、deploy、health check。

## 13. 風險 / 假設 / 開放問題
| 風險 | 等級 | 業界標準解法（來源公司） | 本專案採行方式 |
|---|---|---|---|
| 收款流水與催繳清單語意混淆 | 中 | TUIO / TuitionEP 將 invoice、payment history、fee stream 分開 | 使用 tabs 區分 `待收與核帳`、`收款紀錄`、`收據紀錄` |
| 匯出檔案欄位過多造成不可讀 | 中 | Chargebee ledger statements 支援欄位管理與摘要 | 第一版固定核心欄位 + summary；不放不必要 PII |
| PDF 批次匯出太大導致瀏覽器卡頓 | 中 | Modern Treasury 大量資料偏 CSV snapshot；PDF 用於可讀報表 | CSV 支援較大筆數，PDF 設 500 筆上限 |
| 收據格式不夠正式 | 中 | Open Ledger 強調白標品牌、logo、產生時間、頁碼 | PDF/收據使用品牌抬頭、日期區間、產生時間、頁碼 |
| 多校區資料外洩 | 高 | 財務 dashboard 必須依 entity / branch filter | 後端強制 `require_campus`，不可只靠前端篩選 |

假設：
- 既有已核帳付款皆會形成 `PaymentReport.status=confirmed` 或可由 `Payment` 關聯回收據；若 ARCH 發現舊資料不完整，需設計 backfill 顯示策略。
- `ClassSession` 是第一堂課日期的來源；若課程尚未排課，顯示 `尚未排課`。
- 第一版不提供未收款預測；若日後要做，應命名為 `應收預估` 或 `待收款`，不可混入 `收款紀錄`。

開放問題：
- `[AI-RESOLVABLE]` PDF 產生採前端套件、瀏覽器列印，或後端產生，需 ARCH 查現有依賴與 bundle 影響後決定。
- `[AI-RESOLVABLE]` CSV 是否用前端目前資料或後端 streaming export，需依資料量與既有 `ExportController` 模式決定。
- `[AI-RESOLVABLE]` `Payment` 與 `PaymentReport` 舊資料關聯完整度，需 ARCH 檢查 migrations/test fixtures。

## 14. Definition of Done
- [ ] 帳務中心入口：驗證方式：frontend build 通過且 `[REVIEW]` 確認側欄未新增多個帳務入口。
- [ ] 收款紀錄 API：驗證方式：GitHub Actions PHPUnit feature test 0 failures，覆蓋日期篩選、分校隔離、付款方式。
- [ ] 第一堂課日期：驗證方式：feature test 覆蓋堂數制與月結制皆回傳最早 `ClassSession.SessionDate`。
- [ ] 預收標籤：驗證方式：feature test 或前端測試確認 `payment_date < first_session_date` 時顯示 `預收`。
- [ ] 收據查詢：驗證方式：現有/新增 receipt API tests 0 failures，confirmed 才能查收據。
- [ ] CSV/PDF 匯出：驗證方式：frontend build 通過，`[REVIEW]` 確認匯出欄位符合 FR-010/FR-011 且不含不必要 PII。
- [ ] 催繳規則不回歸：驗證方式：`TuitionAlertsApiTest` / 相關 CI tests 0 failures。
- [ ] UI/UX 精緻化：驗證方式：`[REVIEW]` 對照第 5b 節無 ❌。
- [ ] CHANGELOG：驗證方式：diff 含 `docs/CHANGELOG.md` 一行。
- [ ] 上線健康：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `status: ok`。

## Todos（跨功能）
- `[FEATURE]` 後端 API / 資料：設計收款紀錄與收據紀錄查詢合約。
- `[FEATURE]` 前端 UI：帳務中心 tabs、收款紀錄、收據紀錄、匯出操作。
- `[FEATURE]` UI/UX 精緻化：summary cards、空狀態、loading、toast、行動版卡片、PDF 視覺。
- `[TEST]` 測試設計與自動 QA：分校隔離、預收、月結第一堂課、匯出欄位。
- `[TEST]` 自動化 QA 驗收：跑 CI 並驗證所有第 10 節情境。
- `[REVIEW]` 資安靜態審查：STRIDE、PII 最小化、PDF/CSV 匯出資料邊界。
- `[REVIEW]` Code Review：逐條對照 FR-001～FR-013。
- `[DOCS]` 文件更新：CHANGELOG；若 ARCH 發現新帳務規則，補對應 docs。
- `[OPS]` 部署與 health check：CI 綠、PR merge、Deploy to Pi、health check。
- `[N/A]` Migration：目前預期不需要；若 ARCH 推翻，進 DBA 流程。

---

# [ARCH] 技術設計

## A1. 現況判斷
- 現有收款資料有兩層：`Payment` 是付款流水，`PaymentReport` 是核帳/收據工作流。現有 `ReceiptModal` 與 `GET /api/v1/payment-reports/{id}/receipt` 都以 `PaymentReport` 產生收據。
- `PaymentReport` 已有 `payment_date`、`payment_method`、`reported_amount`、`status`、`confirmed_by`、`confirmed_at`、`payment_id`、`InvoiceID`、`backfill_note`，足以支撐「收款紀錄」與「收據紀錄」第一版。
- `Payment` 內可能有負數 `void` 抵銷付款，也可能有舊帳單付款；若直接從 `Payment` 列收款，會有重複計算與無收據可查的風險。第一版以 `PaymentReport.status=confirmed` 作為單一收據/收款來源，避免 double count。
- 現有 `TuitionCollectionPage` 已有催繳、核帳、退回、撤銷、收據預覽與 CSV 範例；適合改為 `AccountingCenterPage` 容器或原頁升級，保留既有待收能力。
- 前端 `package.json` 目前沒有 PDF 套件；新增 `jspdf` 會增加 bundle 與授權/中文字型處理成本。第一版採「精美 HTML 列印報表 + `window.print()` 另存 PDF」作為批次 PDF 匯出。

## A2. DB 異動清單
| 項目 | 結論 |
|---|---|
| 新增欄位 | 不需要 |
| 新增資料表 | 不需要 |
| Migration | 第一版不需要 |
| 索引 | 暫不新增；若後續資料量造成查詢 > 2s，再另開技術債評估 `payment_reports(status, payment_date)` 複合索引 |

理由：
- `payment_reports` 已有 `status`、`StudentID`、`StudentClassID` index。
- 第一版預設查本月 + 單分校，且只查 director 可見分校。
- 不為了報表第一版引入 DB migration 風險；若 CI/實測出現效能問題再進 DBA。

多校區隔離：
- 所有帳務查詢都必須由 `PaymentReport -> Student.CampusID` 過濾。
- director：只能查 `auth_campus_ids` 內分校；傳入不允許 `branch_id` 回 403。
- super_admin：可不傳分校查全部，也可傳 `branch_id` 篩選。

## A3. API 合約
新增 director group 內 route，沿用 `role:director + require_campus + require_password_change`：

### `GET /api/v1/accounting/payments`
用途：收款紀錄與收據紀錄共用查詢。

Query：
| 參數 | 型別 | 預設 | 說明 |
|---|---|---|---|
| `branch_id` | int | current allowed campus | 分校篩選 |
| `start` | date | 本月 1 日 | 繳費起日 |
| `end` | date | 本月月底 | 繳費迄日 |
| `student` | string | null | 學生姓名模糊搜尋 |
| `subject` | string | null | 科目名稱篩選 |
| `payment_method` | `cash` / `transfer` | null | 付款方式 |
| `status` | `confirmed` / `voided` / `all` | `confirmed` | 第一版列表預設只看 confirmed |
| `page` | int | 1 | 頁碼 |
| `per_page` | int | 50 | 最大 200 |
| `export` | `1` / omitted | omitted | 匯出時回傳較大筆數，上限 5000 |

Response：
| 欄位 | 說明 |
|---|---|
| `data[].report_id` | `PaymentReport.id`，也作為收據 ID |
| `data[].receipt_no` | `R-000001` 格式 |
| `data[].payment_date` | 繳費日期 |
| `data[].student_id` / `student_name` | 學生資訊 |
| `data[].student_class_id` | 課程 ID |
| `data[].subject` | 科目 |
| `data[].schedule_mode` | `count` / `date` |
| `data[].first_session_date` | 最早非 cancelled `ClassSession.SessionDate`；無排課回 null |
| `data[].is_prepaid` | `payment_date < first_session_date` |
| `data[].payment_method` | `cash` / `transfer` |
| `data[].cash_amount` | 現金金額；非現金為 0 |
| `data[].transfer_amount` | 匯款金額；非匯款為 0 |
| `data[].total_amount` | 合計 |
| `data[].status` | `confirmed` / `voided` |
| `data[].confirmed_at` / `confirmed_by_name` | 核帳資訊 |
| `data[].is_backfilled` | 舊資料補建標記 |
| `summary.total_count` | 筆數 |
| `summary.cash_total` | 現金合計 |
| `summary.transfer_total` | 匯款合計 |
| `summary.grand_total` | 總收入 |
| `summary.prepaid_count` | 預收筆數 |
| `meta` | pagination + filters |

狀態語意：
- `confirmed`：列入現金/匯款/合計。
- `voided`：預設不列入收入；若使用者篩選 `voided` 或 `all`，顯示灰色「已撤銷」，金額不納入 default summary。
- `pending` / `rejected`：不屬於已收款，仍留在 `待收與核帳` tab，不進 `收款紀錄` 預設資料。

### `GET /api/v1/accounting/payments/export`
用途：同一組 filter 回傳匯出用 JSON，不直接產生檔案。

設計：
- 後端套用同一個 query builder 與分校 guard。
- 回傳 `data` + `summary` + `generated_at` + `filters_label`。
- 前端用這份資料產 CSV 或 HTML print PDF。
- `format=csv|pdf-data` 可選，但第一版都回 JSON，降低後端匯出格式分歧。

不新增公開收據端點；單張收據仍用：
- `GET /api/v1/payment-reports/{id}/receipt`

## A4. 後端模組規劃
建議新增 `AccountingController`，避免把 `PaymentReportController` 再擴成多責任：
- `indexPayments(Request $request)`：分頁查詢。
- `exportPayments(Request $request)`：匯出資料查詢。
- 私有 query builder：集中處理分校 guard、日期、學生、科目、付款方式、狀態。
- 私有 transformer：統一 row 欄位與 summary 欄位。

查詢來源：
- 主表：`payment_reports pr`
- 關聯：`Student s`、`StudentClass sc`、`Subject subject`、`User confirmedBy`
- 第一堂課：用 subquery `MIN(ClassSession.SessionDate)`，條件 `ClassSession.StudentClassID = pr.StudentClassID` 且 `Status != cancelled`
- 科目名稱：優先 `StudentClass.SubjectID -> Subject.Subject_Name`；若缺失沿用既有 `displaySubjectName()` 邏輯或 fallback `課程`

注意：
- 不查 `AlertController::tuition`，不改提醒規則。
- 不從 `Payment` 直接 aggregate，以免 void/legacy record 與 `PaymentReport` 重複。
- 匯款後五碼第一版不回傳，避免不必要 PII；若日後有核帳需要，可只在單筆詳情顯示。

## A5. 前端元件規劃
入口：
- 側欄財務組將 `催繳名單` label 改為 `帳務中心`，page key 可沿用 `tuition-collect` 以降低路由風險。

頁面結構：
- 將 `TuitionCollectionPage` 升級為帳務中心容器，或新增 `AccountingCenterPage` 後把既有催繳內容抽成 `ReceivablesTab`。
- Tabs：
  - `待收與核帳`：包住既有催繳名單能力。
  - `收款紀錄`：新表格 + summary + filters + export。
  - `收據紀錄`：可復用 `收款紀錄` 資料視角，但版面更聚焦收據編號、核帳人、查看/下載。

收款紀錄 UI：
- Filters：日期區間、學生搜尋、科目、付款方式、重整。
- Summary cards：筆數、現金、匯款、合計、預收筆數。
- Table：繳費日期、學生、科目、第一堂課、現金、匯款、合計、標籤、收據。
- 預收 chip：`payment_date < first_session_date`。
- 無排課：第一堂課欄顯示 `尚未排課`，不顯示預收。

匯出：
- CSV：原生 Blob + UTF-8 BOM，欄位依 FR-010。
- PDF：開新視窗或隱藏 iframe 渲染 branded HTML report，使用 print stylesheet；由瀏覽器「另存 PDF」。內容包含 logo/標題、分校、日期區間、summary、明細、頁尾產生時間。
- 因不新增 PDF 套件，中文字型與品牌樣式可控，bundle 不增加。

## A6. 測試策略
新增或擴充 `PaymentReportApiTest`：
- `test_accounting_payments_lists_confirmed_payments_with_summary`
- `test_accounting_payments_filters_by_branch_and_rejects_cross_campus`
- `test_accounting_payments_marks_prepaid_when_payment_before_first_session`
- `test_accounting_payments_returns_first_session_for_monthly_course`
- `test_accounting_payments_excludes_pending_and_rejected_by_default`
- `test_accounting_payments_export_caps_large_result`
- `test_accounting_payments_does_not_expose_account_last5`

既有 regression：
- `PaymentReportApiTest::test_receipt_returns_data_for_confirmed`
- `TuitionAlertsApiTest` 全部維持通過。
- 前端至少跑 `npm run build`。

測試規則：
- 不在 Pi 跑 PHPUnit；只用 GitHub Actions。
- 測試資料要用 `Campus` factory / helper，避免硬寫不完整 NOT NULL 欄位。
- 今日日期若建立 future session，用 23:00 避免時間敏感錯誤。

## A7. 安全與風險
| 風險 | 等級 | 控制 |
|---|---|---|
| 財務資料跨校外洩 | HIGH | 後端以 `Student.CampusID` 強制過濾；測試覆蓋 403 |
| CSV/PDF 匯出 PII 過量 | MEDIUM | 不輸出家長電話、LINE ID、token、帳號後五碼 |
| 收款重複計算 | MEDIUM | 以 `PaymentReport.confirmed` 單一來源，不混用 `Payment` aggregate |
| PDF 大量資料卡住瀏覽器 | MEDIUM | PDF 上限 500 筆；超過要求縮小區間或用 CSV |
| 舊資料無電子收據 | LOW | 用 `is_backfilled/backfill_note` 標示，沒有 report 的 legacy payment 不強行列入第一版 |

## A8. 設計問題 Q&A
| 問題 | 決策 |
|---|---|
| 要新增側欄項目嗎？ | 不新增多個；將既有催繳入口整理為帳務中心 |
| 「繳費清單」正式命名？ | `收款紀錄`，代表已核帳實收流水 |
| 月結第一堂課怎麼算？ | 與堂數制一致：最早非 cancelled `ClassSession.SessionDate` |
| 需要 migration 嗎？ | 第一版不需要 |
| PDF 要用套件嗎？ | 第一版不用；用 branded HTML print report |
| 收據查詢來源？ | `PaymentReport.status=confirmed`，單張用既有 receipt API |
