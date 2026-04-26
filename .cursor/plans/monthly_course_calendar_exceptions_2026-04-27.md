# 月結一般課程建立日曆選課 PRD

## 1. 文件資訊
| 欄位 | 內容 |
|---|---|
| 功能名稱 | 月結一般課程建立時可使用日曆調整初始堂次 |
| 版本 | v1 |
| 狀態 | [PLAN] 待批准 |
| 日期 | 2026-04-27 |
| 目標角色 | 主任 / 行政 |

## 2. 目標與業務背景
主任建立「一般課程」時，月結制目前只看到自動排課預覽，無法像堂數制一樣在右側日曆先點選實際需要建立的日期。實務上舊課程或已開始課程常有請假、調課、臨時異動；若只能先建立完整自動排課，再逐堂去請假或調課，操作成本高且容易漏改。

KPI：月結課程建立後需要再進行單堂請假/調課修正的次數下降；建立當下可直接看到將建立的堂次數與日期；不改變繳費提醒規則。

## 3. 範圍
In Scope：
- `UniversalClassScheduler` 一般課程建立流程，月結制也顯示可操作日曆。
- 月結制可在建立前手動選擇/取消初始堂次日期，用於反映過去請假、調課、補課等已知異動。
- 保留固定星期、結算日、課程結束日與月結堂數摘要。
- 後端接受月結制 `session_plan` 作為明確堂次清單，建立對應 `ClassSession`。

Out of Scope：
- 不修改 `AlertController::tuition` 或繳費/續課提醒列入條件。
- 不新增請假/調課正式紀錄的批次編輯功能。
- 不處理多科方案月結日曆。
- 不新增 DB 欄位或 migration，除非 ARCH 發現現有資料無法表達。

## 4. RACI
| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類（可閱讀） | 使用者 | I |

## 4b. Dependencies
| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR | 無 |
| 外部服務 | 無 |
| 環境 | 既有 `POST /api/v1/class-sessions/batch`、`EnrollmentService`、`ClassSession` | 已存在 |
| 高風險規則 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | 已讀，本次不改提醒條件 |

## 5. User Stories + AC
- As a 主任, I want 月結制建立課程時也能看到日曆, so that 我可以建立前先排除或補入已知異動日期。
  - AC：繳費方式選「月結制」後，右側仍顯示日曆。
  - AC：可切換月份並點選日期，日曆摘要即時更新。
- As a 主任, I want 系統仍依固定星期和結束日產生預設排課, so that 我只需要調整例外日期。
  - AC：設定固定星期與結束日後，日曆顯示系統預排。
  - AC：取消某個預排日期後，送出不建立該日期堂次。
  - AC：手動加入非固定星期日期時，送出會建立該堂次並使用預設時間。
- As a 行政, I want 月結提醒邏輯不受影響, so that 催繳/將屆提醒仍照現有規則。
  - AC：建立後 `ScheduleMode=date`、`settlement_day` 正確，提醒規則不變。

## 5b. UI/UX 精緻化
| 面向 | 規格 |
|---|---|
| 版面層次 | 月結制右側改為「排課日曆 + 摘要」，沿用堂數制日曆卡片；月結預覽文字放在日曆摘要上方 |
| 色彩一致性 | 系統預排、手動預排、補登已上沿用既有 chip 顏色；取消/排除日期使用 warning 樣式，不使用 danger |
| 互動回饋 | 點選日期立即切換狀態；送出按鈕沿用現有 loading spinner |
| 空狀態設計 | 未選固定星期或未填結束日時，右側顯示說明：「請先選固定星期與結束日，系統會在日曆產生預排」 |
| 載入狀態 | 本功能無額外遠端載入；送出時沿用既有狀態 |
| 防呆設計 | 月結仍必填結算日；結束日早於開課日 inline warning；跨 2 年由後端 guard |
| 響應式 | 96vw modal 內不新增水平 overflow；日曆按鈕觸控目標至少 44px |
| 無障礙 | 日曆日期按鈕加 `aria-label` 描述日期與狀態；鍵盤可 tab 操作 |

## 6. 功能需求
- FR-001：月結制一般課程建立時，右側日曆不可被隱藏。
- FR-002：月結制日曆需顯示固定星期 + 開課日 + 結束日推算出的系統預排日期。
- FR-003：使用者可取消系統預排日期，送出時該日期不建立 `ClassSession`。
- FR-004：使用者可手動加入非固定星期日期，送出時以手動預排建立 `ClassSession`。
- FR-005：若選擇過去且已下課日期，行為須沿用既有補登/自動核准規則，不引入新的扣堂邏輯。
- FR-006：月結 `monthly_sessions` 應等於最終建立堂次數，避免後端「堂數需與本月預排堂數一致」錯誤。
- FR-007：不修改 `alerts/tuition` 的任何查詢條件、回傳欄位、提醒文案。

## 7. 非功能需求
- NFR-001：日曆狀態切換在 500ms 內完成，僅前端 state 更新。
- NFR-002：單次建立最多 500 堂，沿用後端 validation。
- NFR-003：失敗時顯示可理解錯誤，不顯示 raw HTTP/HTML。
- NFR-004：無新增外部依賴。

## 8. 技術方向
- 前端：調整 `frontend/src/components/UniversalClassScheduler.vue` 的月結右側 panel，重用既有日曆 state 與摘要。
- 後端：調整 `backend/app/Services/EnrollmentService.php` 月結 recurring path，使明確 `session_plan` 可優先代表最終堂次清單；若沒有手動調整則維持既有 end_date 自動產生。
- API：沿用 `POST /api/v1/class-sessions/batch`，不新增路由。
- DB：預期不新增欄位；仍以 `StudentClass` + `ClassSession` 表達初始排課。

## 8b. Decision Log
| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-27 | 月結建立流程顯示日曆並送出明確堂次 | 建立後再逐堂調課 | 符合使用者痛點，降低漏調整 |
| 2026-04-27 | 不改繳費提醒 | 同步調整 alert 顯示 | 高風險且非需求範圍 |
| 2026-04-27 | 不新增 exception table | 新增 recurrence exception schema | 現有系統已 materialize `ClassSession`，小範圍改動風險較低 |

## 9. 資安與存取控制
- Role：沿用 `class-sessions/batch` 既有 auth、role、branch guard。
- PII：不新增學生/家長 PII 欄位。
- STRIDE 快評：Spoofing 低（Bearer token）；Tampering 中（排課資料需後端驗證分校/老師/日期）；Repudiation 低（沿用既有建立流程）；Information Disclosure 低；DoS 低（500 筆上限）；Elevation 低（不新增權限）。

## 10. QA 驗收
- Happy Path：月結、固定週二四、結束日 +1 個月，日曆顯示所有預排，送出建立成功。
- Edge：取消一個固定星期日期，建立後該日期不存在 `ClassSession`。
- Edge：新增一個非固定星期日期，建立後該日期存在 `ClassSession`。
- Edge：過去已下課日期符合既有補登規則，不讓未下課的今天標記為已上。
- Error：未選結算日送出被擋。
- Error：結束日早於開課日被擋。
- Regression：堂數制建立日曆行為不變。
- Regression：月結 `alerts/tuition` 測試不變。

## 11. 上線與維運
- 部署：前端有改，PR merge 且 CI 綠後走既有 Deploy to Pi；不在 feature branch deploy。
- Feature Flag：不新增 flag，因為此為建立流程 UI 修正且可由 PR/rollback 控制。
- Observability：
| 監控項目 | 指標 / log | 告警閾值 | 負責 |
|---|---|---|---|
| 建課失敗 | `排課失敗` / 422 response | 合併後 24h 使用者回報 | `[OPS]` |
| Health | `/api/v1/health` | 非 200 | `[OPS]` |
- 回滾：`git revert <merge commit>`；無 migration rollback。

## 12. 里程碑與優先級
- P0 `[FEATURE]`：ARCH 確認現有 payload 與月結 session_plan 可行性。
- P1 `[FEATURE]`：前端月結日曆與送出 payload。
- P1 `[TEST]`：補後端 feature test 與前端 build。
- P2 `[DOCS]`：CHANGELOG。
- P2 `[OPS]`：merge 後 health check。

## 13. 風險 / 假設 / 開放問題
| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|---|---|---|---|
| recurring schedule 例外過多造成資料模型複雜 | 中 | SchoolCloud 先產生 recurring 預覽並標示衝突；The Events Calendar 支援 recurrence exceptions；calendar design 常用 exceptions/overrides | 本次只在建立當下 materialize 最終 `ClassSession`，不新增長期 recurrence model |
| 使用者以為取消日期等於正式請假紀錄 | 中 | 日曆產品會區分 delete occurrence 與 leave/reschedule record | UI 文案標明「建立時不產生該堂」，不是請假紀錄 |
| 月結堂數與實際建立堂次不一致 | 中 | 送出前以最終清單計算 count | `monthly_sessions` 由最終 session list 決定 |

假設：
- 既有 `ClassSession` materialized 模型仍是系統主要排課來源；若 ARCH 發現需正式 exception record，停止 DEV 並回報。
- 本需求只針對一般課程，不含多科方案；若多科方案也需要，另開 PRD。

開放問題：
- `[AI-RESOLVABLE]` 是否需要新增後端測試覆蓋月結 `session_plan` 優先行為，ARCH/TEST 讀現有測試後決定檔案位置。

## 14. Definition of Done
- [ ] 月結日曆顯示：驗證方式：frontend build 通過，且 `[REVIEW]` 對照 FR-001～FR-004 無缺漏。
- [ ] 月結手動日期建立：驗證方式：GitHub Actions PHPUnit 對應 feature test 0 failures。
- [ ] 堂數制不回歸：驗證方式：現有排課相關 tests 0 failures。
- [ ] 繳費提醒不回歸：驗證方式：`TuitionAlertsApiTest` / 相關 CI tests 0 failures。
- [ ] UI/UX：驗證方式：`[REVIEW]` 對照第 5b 節無 ❌。
- [ ] CHANGELOG：驗證方式：diff 含 `docs/CHANGELOG.md` 一行。
- [ ] 上線健康：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `status: ok`。

## Todos（跨功能）
- `[FEATURE]` 後端 API / 資料：確認並實作月結 `session_plan` 明確清單。
- `[FEATURE]` 前端 UI：月結一般課程建立顯示日曆。
- `[FEATURE]` UI/UX 精緻化：補文案、legend、aria-label、響應式。
- `[TEST]` 測試設計與自動 QA：月結取消/新增日期、堂數制不回歸。
- `[REVIEW]` 資安靜態審查：role/branch guard、STRIDE 無 HIGH。
- `[REVIEW]` Code Review：逐條對照 FR。
- `[DOCS]` 文件更新：CHANGELOG。
- `[OPS]` 部署與 health check：CI 綠、merge、deploy、health。
- `[N/A]` Migration：預期不需要；ARCH 若推翻需進 DBA。

---

# [ARCH] 技術設計

## A1. 現況判斷
- 前端 `UniversalClassScheduler` 已有日曆 state：`form.confirmed_dates`、`manualDates`、`futureSessionOccurrences`、`sessionPlan` 組裝；但模板以 `v-if="form.payment_type === 'monthly'"` 顯示月結預覽，日曆只在 `v-else` 的堂數制顯示。
- 前端 `onDateClick()` 目前限制月結只能選同一月份，且未來非固定星期會被擋；此限制與「建立時先處理調課/補課異動」衝突，需調整語意。
- 後端 `EnrollmentService` 月結只要有 `payment_type=monthly + end_date + days_of_week` 就進 recurring path，自動生成 `futureDates`，因此會忽略前端送出的 `session_plan`。
- 現有 `EnrollmentServiceMonthlyRecurringTest` 已覆蓋月結自動區間排課；可直接新增「明確 `session_plan` 優先」測試。

## A2. DB 異動清單
| 項目 | 結論 |
|---|---|
| 新增欄位 | 不需要 |
| 新增資料表 | 不需要 |
| Migration | 不需要 |
| 既有表 | `StudentClass` 保存合約與月結資訊；`ClassSession` 保存最終 materialized 堂次 |

多校區隔離：沿用 `EnrollmentService` 的 `branch_id`、學生 `CampusID`、`UserCampus`、`room_id` 分校檢查，不新增跨校 query。

## A3. API 合約
沿用 `POST /api/v1/class-sessions/batch`。

### Request 語意
| 欄位 | 月結自動排課 | 月結手動日曆 |
|---|---|---|
| `payment_type` | `monthly` | `monthly` |
| `settlement_day` | required | required |
| `end_date` | required | 保留，寫入 `StudentClass.EndDate` |
| `days_of_week` | required | required，保存合約星期 |
| `session_plan` | empty / omitted | 明確最終堂次清單 |
| `monthly_sessions` | 後端依自動產生數計算 | 前端送最終堂次數，後端驗證等於 `session_plan` 筆數 |

### 後端決策
- 若 `session_plan` 非空：優先用 `session_plan` 建立堂次，即使有 `end_date`。
- 若 `session_plan` 空且有 `end_date + days_of_week`：維持現有 recurring auto-generation。
- 若兩者都沒有足夠日期：維持現有 422。

## A4. 後端模組規劃
目標檔案：`backend/app/Services/EnrollmentService.php`。

設計：
- 在判斷 `$isMonthlyRecurring` 前先檢查 `session_plan` 是否為非空 array。
- 月結且 `session_plan` 非空時：
  - 不覆蓋 `$futureDates`。
  - 不進自動 recurring 產生。
  - `EndDate` 仍以 request `end_date` 或最終堂次最後日期保存。
  - 保留結算日、月結模式、分校/老師/教室 guard。
- 允許未來手動日期不一定落在固定星期；但保存 `StudentClass.week*` 時仍以合約 `days_of_week` 為主，避免臨時補課日污染固定合約。

需特別注意：
- 不修改 `SessionDeductionService`。
- 不修改 `AlertController::tuition`。
- 不讓月結 `RemainingSessions` 變成堂數制語意，仍為 0。

## A5. 前端元件規劃
目標檔案：`frontend/src/components/UniversalClassScheduler.vue`。

設計：
- 月結右側不再只顯示排課預覽，改用共用日曆卡片。
- 月結日曆有兩種狀態來源：
  - 系統預排：由 `end_date + days_of_week + day_time_slots` 推算。
  - 手動調整：取消系統日期 / 加入額外日期後，送出明確 `session_plan`。
- 新增或調整 computed：
  - `monthlyRecurringOccurrences`：產生跨 `course_start_date` 到 `end_date` 的全部月結預排。
  - `excludedDates` 或等價 state：記錄被取消的系統日期。
  - `finalMonthlySessionPlan`：系統預排扣除取消日期 + 手動加入日期。
- 送出 payload：
  - 若月結且使用者有日曆調整：`session_plan = finalMonthlySessionPlan`，`monthly_sessions = final count`，保留 `end_date`。
  - 若月結且無調整：可維持現有 `end_date` 自動生成路徑，避免不必要風險。

UI 文案：
- 「日曆調整只影響建立時要產生哪些堂次；取消日期不是正式請假紀錄。」
- Legend 增加「排除不建立」狀態。

## A6. 測試策略
新增/更新：
- `EnrollmentServiceMonthlyRecurringTest`
  - 月結 + `end_date` + `session_plan`：只建立 `session_plan` 指定日期，不自動補回被取消日期。
  - `StudentClass.EndDate` 仍保存 request `end_date`。
  - `monthly_sessions` 等於建立堂次數。
  - 現有自動 recurring tests 維持通過。
- 前端：至少跑 `npm run build`；若 repo 有前端單測則依現有腳本執行。
- Regression：`TuitionAlertsApiTest` 需由 CI 覆蓋，確認月結提醒規則不變。

測試環境限制：不可在 Pi/production 跑 PHPUnit；只走 GitHub Actions。

## A7. 安全與風險
| 風險 | 等級 | 控制 |
|---|---|---|
| 使用者新增跨固定星期未來日期造成合約漂移 | 中 | `ClassSession` 可建立例外日，但 `StudentClass.week*` 保留固定星期 |
| 月結建立後提醒錯誤 | 中 | 不碰 `AlertController::tuition`，CI 跑提醒測試 |
| 建立大量堂次 | 低 | API 既有 `session_plan|max:500` |
| 跨校建課 | 低 | 沿用 `EnrollmentService` branch / UserCampus guard |

## A8. Exit Checklist
- [x] DB 異動清單：不需要 migration。
- [x] API 合約：沿用 `POST /api/v1/class-sessions/batch`，月結 `session_plan` 非空時優先。
- [x] 前端元件規劃：`UniversalClassScheduler` 月結右側日曆 + final session plan。
- [x] 多校區隔離：沿用現有 `EnrollmentService` guard，不新增跨校 query。
- [x] 高風險邏輯：不修改繳費提醒、堂數扣除 service。
- [x] 測試策略：新增月結 `session_plan` test，CI 驗證。
