# PRD — #452 角色分眾問號教學 v1

## 1. 文件資訊
- 功能：問號導覽（`?`）分眾教學強化
- 版本：v1
- 狀態：Draft -> In Progress
- 目標角色：teacher、director、super_admin、parent
- 對應：#452（parent #450）

## 2. 目標與背景
- 痛點：不同角色看到同一套導覽，無法快速找到自己第一步。
- 目標：按角色給「首頁導覽 + 核心任務導覽」，降低首次學習成本。
- KPI：首次使用導覽完成率提升；導覽後 24h 內核心頁操作率提升。

## 3. 範圍
- In scope：`pageGuideConfig` 新增 role-home fallback、`teacher-home` 與 discrepancy 導覽、App 啟動 fallback。
- Out of scope：行為追蹤埋點、影片教學、跨端教學中心。

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
| 環境前提 | 既有 `usePageGuideTour` + `pageGuideConfig` | 已存在 |

## 5. User Stories + AC
- US-001：作為老師，點 `?` 後能先看到「今天要做什麼」。
  - AC：`teacher-home` 至少 4 個步驟，含今日待辦與快速入口。
- US-002：作為主任，若某頁未配置導覽，也能看到角色首頁導引。
  - AC：`startGuideTour()` 無頁面導覽時可 fallback 到 role-home。

## 5b. UI/UX
| 面向 | 規格 |
|---|---|
| 層次 | 先角色入口，再頁面細節 |
| 互動 | 保持現有上一步/下一步/完成 |
| 防呆 | 找不到頁面步驟時自動 fallback，不要無反應 |
| 響應式 | 沿用現有 popover 自適應策略 |

## 6. FR
- FR-001：新增 `role-home` 角色導覽配置。
- FR-002：新增 `teacher-home` 導覽配置。
- FR-003：新增 `schedule-discrepancy` 導覽配置。
- FR-004：`startGuideTour()` 支援頁面導覽失敗時 fallback。

## 7. NFR
- NFR-001：不改現有資料 API。
- NFR-002：`npm run build` 必須通過。

## 8. 技術方向
- 以配置驅動（`pageGuideConfig`）擴充導覽，而非硬編碼在頁面邏輯。
- App 層只做 fallback 路由，不改 tour 核心演算法。

## 8b. Decision Log
| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-23 | 採配置驅動擴充 | 每頁寫客製導覽邏輯 | 維護成本低、可逐頁增量 |
| 2026-05-23 | App 層加 fallback | 提示「本頁無導覽」 | 對新手更友善，避免空點擊 |

## 9. 資安
- 僅前端導覽文案/選擇器變更，無新增權限與資料暴露。
- STRIDE：維持現況。

## 10. QA
- 老師首頁可正常走完導覽。
- discrepancy 頁可導覽 SOP 與清單區塊。
- 未配置頁面按 `?` 仍會啟動 role-home。

## 11. 上線維運
- PR merge -> deploy.yml 自動部署。
- 無 feature flag（低風險前端文案與導航）。
- `/api/v1/health` 必須 `ok`。

## 12. 優先級
- P0：fallback + teacher-home + discrepancy。
- P1：後續補更多角色專屬任務導覽。

## 13. 風險 / 假設 / 開放問題
| 風險 | 等級 | 業界參考 | 採行 |
|---|---|---|---|
| 導覽過長造成中途退出 | 中 | Intercom Product Tour 建議（短步驟、任務導向） | 每頁維持 3-5 步、任務語句 |
| 流程不一致導致培訓成本高 | 中 | Atlassian workflow 標準化思路 | 用角色首頁導覽統一第一步 |

- 假設：現有 data-guide 標記足夠；若不足再補 marker。
- 開放問題：[AI-RESOLVABLE] 是否加「不要再顯示」偏好記錄。

## 14. DoD
- [ ] `?` 在無頁面導覽時可 fallback：手動驗證。
- [ ] 老師首頁導覽可走完：手動驗證。
- [ ] discrepancy 導覽可走完：手動驗證。
- [ ] `npm run build` 成功。
- [ ] merge 後 health `ok`。

## Todos（九類）
- [FEATURE] 後端 API：不適用（前端導覽配置）。
- [FEATURE] 前端 UI：新增導覽配置與 fallback。
- [FEATURE] UI/UX：角色分眾導覽語句。
- [TEST] 測試：`npm run build`。
- [TEST] QA：角色手動導覽 smoke。
- [REVIEW] 資安：檢查無資料外洩。
- [REVIEW] Code Review：對照 FR-001~004。
- [DOCS] 文件：更新 `docs/CHANGELOG.md`。
- [OPS] 部署：merge 後監看 deploy + health。
