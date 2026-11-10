# PRD — #453 課程回報管理易用性 v1

## 1. 文件資訊
- 功能：課程回報管理 UX + SOP 強化（v1）
- 版本：v1
- 狀態：Draft -> In Progress
- 目標角色：director、super_admin
- 對應：#453（parent #450）

## 2. 目標與業務背景
- 痛點：使用者不清楚何時「已確認」vs「已修正」，處理說明品質不穩定。
- 目標：降低處理猶豫與誤操作，讓處理流程可預期且可追溯。
- KPI：
  - `pending -> acknowledged` 平均時間下降 30%
  - `resolved` 填寫低品質說明比例下降 50%

## 3. 範圍
- In scope：頁內 SOP 區塊、處理說明範本按鈕、狀態操作引導文案。
- Out of scope：後端資料模型變更、通知機制改造、跨頁流程重構。

## 4. RACI
| 角色 | 代表 | R/A/C/I |
|---|---|---|
| `[FEATURE]` | 實作 | R |
| `[TEST]` | 測試 | R |
| `[REVIEW]` | 審查 | R |
| `[DOCS]` | 文件 | R |
| `[OPS]` | 上線 | R |
| 人類 | CEO/PM | I |

## 4b. Dependencies
| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 Ticket | #450 | 已完成 |
| 外部服務 | 無 | 無依賴 |
| 環境前提 | 既有 discrepancy API 可用 | 已存在 |

## 5. User Stories + AC
- US-001：作為主任，我要快速知道如何處理一筆回報。
  - AC：頁面內可看到三步 SOP，且包含每一步輸入要求。
- US-002：作為處理者，我想快速輸入合格處理說明。
  - AC：有範本按鈕可一鍵填入說明草稿再編輯。

## 5b. UI/UX
| 面向 | 規格 |
|---|---|
| 版面 | 在列表上方加入「處理 SOP」提示卡 |
| 互動 | 範本按鈕點擊即填入 textarea |
| 防呆 | 保留最少 10 字限制，並加範本提示 |
| 響應式 | 手機版同樣可用範本按鈕 |

## 6. FR
- FR-001：新增頁內 SOP 卡片。
- FR-002：新增至少 3 組處理說明範本。
- FR-003：桌機與手機的處理表單都可套用範本。

## 7. NFR
- NFR-001：不增加 API 呼叫次數。
- NFR-002：`npm run build` 通過。

## 8. 技術方向
- 前端 `ScheduleDiscrepancyPage` 資訊層重排與範本互動。
- 後端 API 不變。

## 8b. Decision Log
| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-23 | 先做前端 SOP + 範本 | 後端先加規則引擎 | 快速降低使用門檻、風險低 |

## 9. 資安
- 無新增權限入口。
- 無新增敏感資料欄位。
- STRIDE：維持現況，主要為 UI 層變更。

## 10. QA
- Happy：可點範本、可提交 resolved。
- Edge：切 tab / 手機版仍能套用範本。
- Error：提交失敗時仍顯示錯誤訊息。

## 11. 上線與維運
- PR merge 後走 `deploy.yml`。
- 無 feature flag（低風險前端互動優化）。
- 健康檢查：`GET /api/v1/health` 必須 `ok`。

## 12. 優先級
- P0：頁內 SOP、範本按鈕。
- P1：後續再補 SLA 視覺化（另案）。

## 13. 風險 / 假設 / 開放問題
| 風險 | 等級 | 業界參考 | 採行 |
|---|---|---|---|
| 處理流程仍不一致 | 中 | Atlassian JSM workflow/queue triage best practices | 頁內 SOP 明確化操作順序 |
| 使用者忽略教學文字 | 中 | Intercom onboarding 建議（短步驟+明確 CTA） | SOP 精簡三步 + 就地範本 |

- 假設：目前欄位足夠承載處理說明；若不成立，再開 API issue。
- 開放問題：[AI-RESOLVABLE] 是否需要加入「常見錯誤案例」折疊區塊。

## 14. DoD
- [ ] SOP 卡片顯示：驗證 `ScheduleDiscrepancyPage` 可見三步流程。
- [ ] 範本按鈕可用：驗證點擊後 textarea 內容變更。
- [ ] Build 成功：驗證 `npm run build` 通過。
- [ ] deploy 後健康正常：驗證 `/api/v1/health` 回傳 `ok`。

## Todos（九類）
- [FEATURE] 後端 API：不適用（本次無後端變更）。
- [FEATURE] 前端 UI：`ScheduleDiscrepancyPage` 加 SOP + 範本。
- [FEATURE] UI/UX 精緻化：按鈕文案與導引區塊。
- [TEST] 測試：`npm run build`。
- [TEST] 自動 QA：桌機/手機手動流程驗收。
- [REVIEW] 資安：確認無新增權限風險。
- [REVIEW] Code Review：對照 FR-001~003。
- [DOCS] 文件：更新 `docs/CHANGELOG.md`。
- [OPS] 部署與 health check：merge 後檢查 workflow + health。
