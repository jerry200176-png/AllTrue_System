# PRD — #451 LINE 教學簡化與官方流程對齊

## 1) 文件資訊
- 功能名稱：LINE 串接教學簡化（Messaging API 官方流程版）
- 版本：v1.0
- 狀態：Draft -> In Progress
- 目標角色：`director`、`super_admin`（設定者），家長（接收教學說明）
- 對應 issue：#451（parent epic: #450）

## 2) 目標與業務背景
- 痛點：設定者看不懂 LINE 後台步驟，常在 token、webhook、LIFF 三處卡住。
- 痛點：現場仍會誤用/詢問 LINE Notify 路徑，造成設定失敗與時間浪費。
- 業務價值：縮短導入時間、降低客服/工程支援成本、提升家長通知啟用率。
- KPI：
  - LINE 串接首次成功率（同日）提升到 >= 80%
  - 平均設定完成時間降到 <= 10 分鐘
  - LINE 設定相關求助單量 30 日內下降 >= 40%

## 3) 範圍
### In Scope
- `LineIntegration` 頁面文案重寫（新手路徑 + 進階路徑）
- 明示 LINE Notify 退場與 Messaging API 主路徑
- Webhook/Token/LIFF 常見錯誤排查區塊
- 家長說明文案改為「可直接轉貼」版本

### Out of Scope
- LINE webhook 後端邏輯改寫
- LIFF OAuth 流程改版
- 新增新的後台權限模型
- 課程回報管理與 in-app 問號教學重構（分別在 #453、#452）

## 4) RACI
| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類 | CEO/PM | I |

## 4b) Dependencies
| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 Ticket / PR | #450 Epic 建立完成 | 已完成 |
| 外部服務 / API | LINE Developers Console、LINE Official Account Manager | 可用 |
| 環境 / 資料前提 | 既有 `/api/v1/line/status` 與 `/api/v1/line/settings` 可用 | 已存在 |

## 5) User Stories + AC
- US-001：作為主任，我希望只看頁面就能完成 LINE 串接，避免查多份文件。
  - AC：首次進入頁面可看到「3 步驟快速開始」區塊且可完成設定。
- US-002：作為管理者，我希望知道 LINE Notify 是否仍可用，避免走錯流程。
  - AC：頁面明確標示 Notify 已退場，並給 Messaging API 替代流程。
- US-003：作為設定者，我希望遇到錯誤時可快速定位。
  - AC：至少 5 條錯誤排查條目，涵蓋 webhook verify/token/LIFF endpoint。

## 5b) UI/UX 精緻化
| 面向 | 規格 |
|---|---|
| 版面層次 | 頁首先顯示「快速開始」，再顯示完整步驟與排查；避免長文一口氣壓給新手 |
| 色彩一致性 | 使用既有成功/警示樣式；退場提示用 warning 色，不用 error 紅 |
| 互動回饋 | 複製按鈕都顯示「已複製」狀態；每段教學可展開/收合 |
| 空狀態設計 | 無綁定家長時顯示下一步 CTA（先完成 OA 串接） |
| 載入狀態 | 狀態卡顯示 loading 文案，避免空白 |
| 防呆設計 | 在欄位 hint 明示來源路徑與常見格式錯誤 |
| 響應式 | 手機可讀、長網址可換行 |
| 無障礙 | 展開按鈕可鍵盤操作，提示文字保持高對比 |

## 6) 功能需求 FR
- FR-001：新增「快速開始（3 步）」區塊，對應 OA -> Token/Secret -> Webhook 驗證。
- FR-002：教學文字需明示 LINE Notify 於 2025-03-31 停止服務。
- FR-003：新增「常見錯誤排查」清單，至少 5 項。
- FR-004：家長轉貼文案分為簡版（家長）與管理者備註（進階）。
- FR-005：保留既有 API 請求與表單欄位，不改資料契約。

## 7) 非功能需求 NFR
- NFR-001：頁面初次載入不增加額外 API 呼叫（維持現有數量）。
- NFR-002：文案調整不得造成 build 失敗；`npm run build` 必須通過。
- NFR-003：若 LINE 官方詞彙再改版，文件區塊可在單一頁面更新完成（可維護性）。

## 8) 技術方向（無程式碼）
- 前端僅調整 `LineIntegration` 的教學資訊架構與文案模組。
- 後端 API 維持現狀，不變更 token 儲存與 status 查詢流程。
- 以「任務導向」重排內容，先給可執行步驟，再給完整背景與排查。

## 8b) Decision Log
| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-05-23 | 先做資訊架構/文案重排，不改 API | 同步重構後端檢查端點 | 先解 adoption 問題，風險低、可快速驗證 |
| 2026-05-23 | 明確寫出 Notify 退場時間 | 僅保留模糊「建議 Messaging API」 | 減少現場誤操作與舊流程混淆 |
| 2026-05-23 | 加入排查清單與可複製文案 | 僅保留步驟教學 | 現場最常卡在錯誤排查，需降低支援成本 |

## 9) 資安與存取控制
- 權限：維持 `director/super_admin` 可設定，其他角色只讀/不可見（沿用現有路由防護）。
- PII：教學文案不新增敏感資料存取。
- STRIDE 快評：
  - Spoofing：無新增身分流程。
  - Tampering：不更動後端資料寫入邏輯。
  - Repudiation：保留現有設定 API 寫入紀錄。
  - Information Disclosure：token 欄位維持遮罩顯示。
  - DoS：無新增頻繁輪詢。
  - Elevation：無新權限入口。

## 10) QA 驗收
- Happy path：
  - 可看到快速開始、完整步驟、排查清單。
  - 複製 webhook 與家長文案功能正常。
- Edge：
  - 已配置 token/secret 時 placeholder 與提示正確。
  - 無 LIFF 時仍可完成基本流程。
- Error：
  - API 載入失敗時提示文案可行動（重試）。
- UI/UX 清單：
  - [ ] 空狀態有 CTA
  - [ ] 非同步有 loading
  - [ ] 互動有成功/失敗回饋
  - [ ] 手機長網址可讀無爆版

## 11) 上線與維運
- 部署步驟：PR merge -> `deploy.yml` 自動部署前端資產 -> health check。
- Feature flag：不需；純文案與資訊架構調整，無風險邏輯切換。
- Observability：
| 監控項目 | 指標/關鍵字 | 告警閾值 | 負責 |
|---|---|---|---|
| 前端 build | `npm run build` | 任一失敗即阻擋 | `[OPS]` |
| 服務健康 | `/api/v1/health` | 非 `status=ok` | `[OPS]` |
- 回滾：`git revert <commit>` 回退本次 UI/文案調整，無 migration。

## 12) 里程碑與優先級
- P0：完成 `LineIntegration` 教學重構與排查清單（本次）
- P1：補管理手冊長文版與截圖（後續 docs PR）
- P2：加入 onboarding 行為追蹤事件（#452 連動）

## 13) 風險 / 假設 / 開放問題
| 風險 | 等級 | 業界/開源參考 | 本專案採行方式 |
|---|---|---|---|
| 使用者仍走舊流程（Notify） | 高 | LINE Notify 官方終止公告（2025-03-31）與 LINE Developers Messaging API 指南 | 在教學頂部直接標示退場與替代路徑 |
| 教學太長導致跳出 | 中 | Intercom Product Tour/Checklist 建議（短訊息、4-5 步驟、清楚 CTA） | 先「3 步驟快速開始」，再提供展開細節 |
| 現場處理人員無法快速分流問題 | 中 | Atlassian JSM queue/triage best practices（先分流、再處理） | 排查清單按「token/webhook/LIFF」分組 |

- 假設：
  - 假設 A：LINE 後台術語短期不再大改。若不成立，透過 #451 持續更新文案。
  - 假設 B：目前 API 欄位足夠。若不成立，開 follow-up API issue。
- 開放問題：
  - [AI-RESOLVABLE] 是否需補「影片版教學」連結（可後續 A/B）。
  - [AI-RESOLVABLE] 是否將排查清單抽成共用 docs 區塊供客服引用。

## 14) Definition of Done
- [ ] LINE 教學重構完成：驗證方式：`npm run build` 成功
- [ ] 退場資訊可見：驗證方式：頁面可見 Notify 停止與 Messaging API 替代說明
- [ ] 排查清單完成：驗證方式：頁面有 >= 5 條常見錯誤處理
- [ ] 文件更新完成：驗證方式：`docs/CHANGELOG.md` 含本次條目
- [ ] 可部署驗證：驗證方式：merge 後 `GET /api/v1/health` 回傳 `status=ok`

## Todos（九類）
- [FEATURE] 後端 API：本次不變更（記錄原因：純前端教學重構）。
- [FEATURE] 前端 UI：重排 `LineIntegration` 區塊、文案、排查清單。
- [FEATURE] UI/UX 精緻化：快速開始與分層教學、按鈕回饋一致化。
- [TEST] 測試與自動 QA：執行 `npm run build`。
- [REVIEW] 資安靜態審查：確認未新增敏感欄位暴露與權限洞。
- [REVIEW] Code Review：逐條對照 FR-001~FR-005。
- [DOCS] 文件更新：`docs/CHANGELOG.md`。
- [OPS] 部署與 health check：merge 後檢查 workflow 與 health。
- [TEST] 驗收場景：以 super_admin/directory 手動走一次串接流程。
