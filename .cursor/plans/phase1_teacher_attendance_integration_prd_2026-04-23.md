# 老師出缺勤打卡整合 — 主計畫書

## 1. 文件資訊
- 功能名稱：老師出缺勤打卡整合（併入現有出缺勤管理頁）
- 版本：v1.2 (ARCH 完成後修訂)
- 狀態：**⏳ Phase 2 [ARCH] 完成，等待 Q1/Q2 決策後批准進入 Phase 2b [UX] + 2c [DBA]**
- 目標角色：老師、主任、超級管理員
- 文件日期：2026-04-23
- 關聯文件：[`.cursor/plans/arch_teacher_attendance_2026-04-23.md`](./arch_teacher_attendance_2026-04-23.md)

### 進度追蹤
| Phase | 角色 | 狀態 |
|---|---|---|
| Phase 1 [PLAN] | Product Manager | ✅ 完成（v1.1 → v1.2） |
| Phase 2 [ARCH] | Tech Lead | ✅ 完成（Q1=B, Q2=A 已確認） |
| Phase 2b [UX] | UI/UX Designer | ✅ 完成 |
| Phase 2c [DBA] | DBA | ✅ 完成（Migration 已建立，待非尖峰執行） |
| Phase 3 [DEV] | 全端工程師 | ✅ 完成 |
| Phase 4 [TEST] | QA 工程師 | ⬜ 未開始 |
| Phase 4b [SEC] | Security Engineer | ⬜ 未開始 |
| Phase 5 [REVIEW] | Staff Engineer | ⬜ 未開始 |
| Phase 6 [DOCS] | Technical Writer | ⬜ 未開始 |
| Phase 7 [OPS] | DevOps Engineer | ⬜ 未開始 |

## 2. 目標與業務背景
- 目前系統已能透過 RFID 寫入老師打卡（`TeacherSingIn`），但尚未形成完整的「可查、可補、可稽核」老師出缺勤流程。
- 現有 `AttendancePage` 已是主任日常 SOP 的工作中心，將老師打卡整合進同頁可降低切換與漏檢。
- 參考業界（K12 時勤系統、ADP/Workday 實務）後，確立「事件紀錄 + 例外審核 + 審計留痕」原則。

### 可量化 KPI（上線後 4 週）
> Baseline：目前老師打卡覆蓋率待上線後第一週測量，再以此為基準計算改善幅度。

- 老師打卡覆蓋率 >= 95%（以當天有課的老師為分母）
- 漏刷人工處理時間下降 >= 50%（對比上線前主任手動核對耗時）
- 打卡爭議件數下降 >= 30%
- 主任每日結班檢查時間 <= 5 分鐘

## 3. 範圍

### In Scope
- 在 `AttendancePage` 新增「學生/老師」分頁整合模式。
- 老師打卡事件查詢（簽到/簽退）與當日狀態呈現。
- 老師異常清單（遲到、早退、漏刷）與補卡流程。
- 補卡審計欄位（原因、操作者、調整時間）。
- 主任端日報/月報匯出（打卡與異常）。
- `TeacherHomePage` 新增「今日打卡狀態」快速卡片（老師自查入口）。

### Out of Scope
- 薪資計算引擎重構。
- GPS / geofence 打卡（本期僅保留擴充點）。
- 新增生物辨識硬體整合（指紋/臉辨）。
- Telegram 打卡通知（保留 P2 擴充點，現有 RFID API 已回傳 token）。

## 4. RACI
| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類（閱讀/決策） | 你（CEO/管理者） | A/I |

## 4b. Dependencies
| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置功能 | `POST /api/v1/swipe-rfid` 與 `TeacherSingIn` 已存在 | 已完成 |
| 外部服務/API | RFID 裝置呼叫既有 API | 可用 |
| 環境/資料前提 | 需 migration 補齊 `TeacherSingIn` 審計欄位（見 §8 資料層規格） | 待完成 |
| 課表資料 | 異常判定依賴 `schedules` 表存在當日有效排課 | 待確認（見 NFR-004） |
| 跨分校場景 | 代課老師跨分校打卡歸屬規則已在 `SwipeRfidController` 實作，本期沿用 | 已存在 |

## 5. User Stories
1. 作為老師，我要在工作台（`TeacherHomePage`）看到我今天是否已簽到/簽退，避免漏刷。
   - AC：登入後首頁卡片在 1 秒內顯示今日打卡狀態（已簽到、已簽退、異常、未打卡）。
2. 作為主任，我要在同一頁查看學生與老師出缺勤，減少切頁。
   - AC：`AttendancePage` 可切換「學生/老師」tab，並保留分校過濾；切換 tab 不重置分校選擇。
3. 作為主任，我要能補卡，且所有變更可追溯。
   - AC：補卡必填原因，並記錄調整人、調整時間，查詢時可回放；原始 RFID 紀錄不被覆蓋。
4. 作為營運，我要能匯出老師打卡與異常報表給薪資或稽核。
   - AC：可匯出 CSV/JSON，欄位與畫面一致，且分校隔離正確。

## 5b. UI/UX 精緻化（前端必填）
| 面向 | 規格（具體到頁面） |
|---|---|
| `TeacherHomePage` 卡片 | 今日打卡狀態卡（已簽到時間 / 已簽退時間 / 異常提示），點擊導向 `AttendancePage` 老師 tab |
| 版面層次 | `AttendancePage` 增加「學生/老師」頂層切換；老師區塊順序：今日狀態 → 異常待處理 → 今日紀錄 |
| 色彩一致性 | 沿用現有 token：成功綠、警示橘、錯誤紅；不新增自定義主色 |
| 互動回饋 | 補卡/覆核按鈕需 loading；成功/失敗 toast 右上，3 秒 |
| 空狀態設計 | 無異常時顯示「今日無老師打卡異常」+「查看全部紀錄」CTA |
| 載入狀態 | 首屏 skeleton；列表採既有 table/card loading 樣式 |
| 防呆設計 | 補卡原因必填；跨日補卡需二次確認 dialog |
| 響應式 | 主任 desktop first；老師 mobile first，觸控 >= 44px |
| 無障礙 | 對比 >= 4.5:1；鍵盤可操作；`aria-label` 補齊 |

## 6. 功能需求 FR
- FR-001：保留既有 `swipe-rfid` 老師簽到/簽退行為，新增標準化狀態欄位（normal/late/early_leave/missed/adjusted）。
- FR-002：新增老師當日打卡查詢 API（老師自查）。
- FR-003：新增主任老師打卡總覽 API（日期/老師/異常過濾）。
- FR-004：新增補卡 API（僅授權角色），且補卡必留審計資訊。
- FR-005：新增異常判定規則：**以老師當天 `schedules` 表中第一堂課的 `StartTime` 為遲到基準，門檻預設 10 分鐘**（選 A）。
  - 當天無排課但有刷卡：標記 `source_only`，不判定異常，列為「待核對」。
  - 遲到門檻：10 分鐘（可於 admin 設定調整，本期 hard-code 為預設）。
- FR-006：新增每日結班檢查 API（有簽到但截止時間前未簽退的名單）。
- FR-007：新增匯出 API（CSV/JSON）。
- FR-008：所有老師出缺勤 API 必須有 role + campus 存取限制。
- FR-009：所有修改動作必須可追溯（audit trail）。

## 7. 非功能需求 NFR
- NFR-001：打卡寫入 API P95 < 500ms。
- NFR-002：總覽查詢 API P95 < 800ms（1000 筆內）。
- NFR-003：避免重複事件（同一老師短時間重複刷卡需防重入，窗口 60 秒）。
- NFR-004：當天排課資料不可用時，先落原始事件，異常狀態標記為 `pending_review`，不中斷打卡流程。
- NFR-005：補卡審計資料不可被無痕覆蓋；原始事件保留，補卡另開 `teacher_signin_adjustments` 記錄。

## 8. 技術方向（無程式碼）

### 資料層（Migration 規格）
現有 `TeacherSingIn` 欄位：`id, TeacherID, CampusID, SignInDT, SignOutDT, MDT`

**新增欄位（加在主表）：**
| 欄位 | 類型 | 說明 |
|---|---|---|
| `Source` | enum('rfid','manual') | 打卡來源 |
| `Status` | enum('normal','late','early_leave','missed','adjusted','pending_review','source_only') | 異常狀態 |

**新增審計表 `teacher_signin_adjustments`：**
| 欄位 | 類型 | 說明 |
|---|---|---|
| `id` | bigint PK | |
| `TeacherSignInID` | bigint FK | 對應主表 |
| `AdjustedByUserID` | int | 操作者 |
| `AdjustReason` | text | 補卡原因（必填） |
| `OriginalSignInDT` | datetime | 原始簽到時間 |
| `OriginalSignOutDT` | datetime nullable | 原始簽退時間 |
| `NewSignInDT` | datetime | 補正後簽到時間 |
| `NewSignOutDT` | datetime nullable | 補正後簽退時間 |
| `CreatedAt` | datetime | |

### 後端
- 新增老師出缺勤查詢/補卡/匯出 API；RFID 寫入邏輯維持向後相容。
- 異常判定：寫入時比對 `schedules` 第一堂課 `StartTime`，無資料則標記 `pending_review`。

### 前端
- `TeacherHomePage`：新增今日打卡狀態卡片。
- `AttendancePage`：增加「老師」tab，共用既有列表、訊息、刷新框架；tab 切換不影響分校過濾器狀態。

### 權限
- 沿用既有 middleware；老師 API 加 `role:teacher` 守衛，主任 API 加 `role:director` + `require_campus`。

## 8b. Decision Log
| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-23 | 採「整合現有 `AttendancePage`」 | 開新獨立老師頁 | 符合現有 SOP、降低切換成本、減少回歸風險 |
| 2026-04-23 | 沿用 `TeacherSingIn` 擴充 + 開新審計表 | 全新老師打卡主表 | 主表擴充風險低；審計需求用獨立表隔離，避免主表膨脹 |
| 2026-04-23 | 採「例外審核」而非「超窗拒打」 | 異常一律拒絕寫入 | 現場彈性高，先記錄可保全證據與營運連續性 |
| 2026-04-23 | 異常判定基準：當天第一堂課 StartTime | 固定班表、主任手動設定班表 | MVP 最快；排課資料已存在；不需新增老師班表資料結構 |
| 2026-04-23 | 遲到門檻預設 10 分鐘 | 5 分鐘 / 15 分鐘 | 業界 K-12 常見值；未來可由 admin 調整 |
| 2026-04-23 | Controller 獨立：新建 `TeacherAttendanceController` | 擴充 `AttendanceController` | 關注點分離；避免 `AttendanceController` 繼續膨脹 |
| 2026-04-23 | 異常判定在 `SwipeRfidController::handleTeacherSwipe()` 同步執行 | 非同步 Job | 批次判定延遲高；同步判定含防禦性 try/catch，失敗不中斷打卡 |
| 2026-04-23 | Q1：本期不自動判定 `early_leave` | A. 最後一堂課 end_time 比較 | B（MVP 簡化）；避免補課調課誤判 |
| 2026-04-23 | Q2：老師只能查自己打卡 | B. 可看同分校全部老師 | A（最小權限）；JWT 自動帶 teacher_id |

## 9. 資安與存取控制
- 老師僅可查本人資料（`/api/v1/teacher-attendance/today` 由 JWT 識別）。
- 主任僅可查所屬分校資料（`require_campus` middleware）。
- 超管可跨分校查核。
- 補卡需記錄操作者與原因，保留原始事件不可覆蓋（`teacher_signin_adjustments` 只增不改）。

### STRIDE 快評
- Spoofing：RFID 冒刷風險 → 來源標記 + 異常告警
- Tampering：補卡竄改 → 審計表唯寫 + 原始欄位不可更新
- Repudiation：否認操作 → 操作者/時間戳完整紀錄
- Information Disclosure：資料外洩 → 最小化回傳欄位 + 分校隔離
- DoS：刷卡洪流 → 60 秒防重入窗口 + 節流告警
- Elevation of Privilege：越權補卡 → middleware + server-side role 再驗證

## 10. QA 驗收

### Happy Path
- 老師 RFID 簽到/簽退後即時可見（主表 + 老師首頁卡片）。
- 主任可於 `AttendancePage` 同頁查老師異常。
- 補卡後可查到 reason/adjusted_by/adjusted_at，原始紀錄未改變。

### Edge
- 重複刷卡（60 秒內）防重複寫入。
- 無排課但有刷卡：標記 `source_only`，不中斷流程，列入「待核對」清單。
- 跨日未簽退：列入異常清單（`missed`）。
- 切換「學生/老師」tab 不重置分校過濾器。

### Error
- 無權限查詢他校資料回 403。
- 補卡缺原因回 422。
- 補卡嘗試覆蓋原始紀錄（非新增）回 405。

### UI/UX 檢查
- 空狀態、loading、toast、防呆、響應式、無障礙逐項符合第 5b。
- `TeacherHomePage` 打卡狀態卡片正確反映當日第一筆/最後一筆 `TeacherSingIn`。

## 11. 上線與維運

### 部署流程
1. 後端：新增 migration → 跑 `php artisan migrate` → 部署新 API
2. 前端：新增 `TeacherHomePage` 卡片 + `AttendancePage` 老師 tab → `npm run deploy`
3. Smoke test：確認老師打卡可查、主任 tab 可切換、補卡流程正常

### Feature Flag
`teacher_attendance_v1`（內部校先開 → 試點校 → 全量）

### Observability
| 監控項目 | 指標/log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 老師打卡 API 延遲 | `teacher_clock_api_latency_p95` | > 500ms 持續 5 分鐘 | `[OPS]` |
| 老師補卡量異常 | `teacher_adjustment_count` | 日增幅 > 2x | `[OPS]` |
| 重複刷卡拒絕率 | `teacher_clock_duplicate_reject_count` | > 10%/日 | `[OPS]` |
| 異常待處理堆積 | `teacher_attendance_exception_open` | > 20 筆/校/日 | `[OPS]` |

- 回滾：關閉 feature flag，前端隱藏入口；API 退回舊行為；`teacher_signin_adjustments` 表可保留（無害）。

## 12. 里程碑與優先級
- **P0**：後端 migration + 老師 API + `AttendancePage` 老師 tab + 基本異常清單 + `TeacherHomePage` 打卡卡片
- **P1**：補卡審計 + 匯出 + 每日結班檢查 API
- **P2**：遲到門檻 admin 可調整 + 代課/跨校進階規則 + Telegram 打卡通知

## 13. 風險 / 假設 / 開放問題
| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|---|---|---|---|
| 補卡濫用 | 高 | 管理者核准 + 審計留痕（ADP / Workday） | 補卡強制 reason + adjusted_by + adjusted_at；審計表唯寫 |
| 現場驗證不足 | 中 | 多因子驗證、位置限制（Frontline / Truein） | 先 RFID + 例外審核；保留 geofence 擴充點 |
| 漏刷導致爭議 | 中 | 提交/核准週期 + 鎖單（Workday） | 每日結班清單 + 補卡期限：次日 18:00 前可補，逾期須主任解鎖 |
| 排課資料不完整 | 中 | 系統先落事件再補 metadata | 無排課標 `pending_review`，不拒絕寫入 |

### 假設
- RFID 設備可穩定上送資料；若中斷可由補卡流程補救。
- `schedules` 表中老師排課資料存在且完整；不存在時先標記 `pending_review`。
- 代課跨分校打卡歸屬邏輯沿用現有 `SwipeRfidController`，本期不另做調整。

### 開放問題
- ~~[AI-RESOLVABLE] 遲到門檻~~ → **確定：10 分鐘**（2026-04-23）
- ~~[AI-RESOLVABLE] 補卡允許期限~~ → **確定：次日 18:00 前可補，逾期須主任手動解鎖**（2026-04-23）
- ~~[ARCH-Q1] 早退判定基準~~ → **確定：B — 本期不自動判定早退**（2026-04-23）
- ~~[ARCH-Q2] 老師視角範圍~~ → **確定：A — 老師只能查自己**（2026-04-23）

## 14. Definition of Done
- [ ] 老師打卡事件可查：`GET /api/v1/teacher-attendance/today` 回 200 且資料正確
- [ ] 主任總覽可查：`GET /api/v1/teacher-attendance` 回 200 且分校隔離正確
- [ ] 補卡審計完整：補卡後 `teacher_signin_adjustments` 可查到 reason/adjusted_by/adjusted_at，主表原始欄位未變
- [ ] 前端整合完成：`AttendancePage` 可切學生/老師，切換不影響分校過濾器狀態
- [ ] `TeacherHomePage` 打卡卡片：正確顯示當日簽到/簽退時間或「未打卡」
- [ ] 資安審查通過：STRIDE 靜態審查 HIGH=0
- [ ] 文件更新完成：`docs/CHANGELOG.md` 含本功能條目
- [ ] 監控可用：health 與關鍵監控項目可讀
- [ ] CI 通過：PHPUnit 全綠 + Vite build 成功

---

## Todos（九類）
- 後端 API/資料（含 migration）：`[FEATURE]`
- 前端 UI 功能（AttendancePage + TeacherHomePage）：`[FEATURE]`
- UI/UX 精緻化：`[FEATURE]`
- 測試設計與自動執行（PHPUnit Feature Tests）：`[TEST]`
- 自動化 QA 驗收：`[TEST]`
- 資安靜態審查：`[REVIEW]`
- Code Review：`[REVIEW]`
- 文件更新（CHANGELOG）：`[DOCS]`
- 部署與 health check（`npm run deploy` + smoke test）：`[OPS]`

---

## 異動記錄
| 版本 | 日期 | 修訂內容 |
|---|---|---|
| v1.0 | 2026-04-23 | 初版 PRD（PLAN Phase） |
| v1.1 | 2026-04-23 | PM 審查後修訂：加 KPI baseline、ARCH-Q1 決策（選 A：第一堂課 StartTime）、審計表欄位、TeacherHomePage UX、Observability |
| v1.2 | 2026-04-23 | ARCH Phase 完成：更新進度追蹤、Decision Log 新增 ARCH 決策、標記 Q1/Q2 待決、關聯 ARCH 設計文件 |
| v1.3 | 2026-04-23 | Q1=B、Q2=A 確認；進入 Phase 3 [DEV] |
| v1.4 | 2026-04-23 | Phase 3 [DEV] 完成；npm run deploy 已執行 |

---

> 📎 技術設計文件（ARCH）：[`.cursor/plans/arch_teacher_attendance_2026-04-23.md`](./arch_teacher_attendance_2026-04-23.md)
