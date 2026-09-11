# 課後儲存確認：限定交付證據

狀態：產品已部署並通過正式頁面隔離情境驗證；教職員公告隨本文件補件發布。Founder 2026-09-11 GO 包含本包實作、測試、必要 metadata、green-gated merge、production activation 與既有公告；不是 PLAN.md 全包授權。

## 正式交付證據（2026-09-11）

- [產品 PR #2719](https://github.com/jerry200176-png/AllTrue_System/pull/2719)，merge / tested production SHA `be1088d1ae4b8a79a8f90231fdeda537eb81e38b`。
- 正式 `deployment.json`：backend / frontend / frontend_build_sha 均為上述 SHA，built_at `2026-09-11T10:52:11.141Z`，deployed_at `2026-09-11T10:52:55.017258Z`；18:53 台北 health `ok`。
- [精確 main CI](https://github.com/jerry200176-png/AllTrue_System/actions/runs/34590707167) 與 [部署](https://github.com/jerry200176-png/AllTrue_System/actions/runs/34590905969) success；[PR CI](https://github.com/jerry200176-png/AllTrue_System/actions/runs/34590428021) 前端 build / coverage 與必要 checks passed。PHPUnit / PHPStan 依既有 changed-area 判定 skip，不冒稱執行；Bugbot usage limit 未執行，Astra 實際審查，沒有假 reviewer。
- 原有 T3 分類保留；[限定 GO 與 exact-target 啟用紀錄](https://github.com/jerry200176-png/AllTrue_System/pull/2719#issuecomment-5633317567)。既有 required-reviewer environment 通過，未改 gate、權限或審核設定。
- 本機全單元測試 103 files / 534 tests passed；完整 maintained build 鏈與 lint passed，unused 215/224，未改 baseline。冻结 build revision `7e6d7342035207098254158856f36f40b2722128`，4 個完整 App Playwright tests passed（33.2 s）；後續只合併其他 CLI 主線及重生 release bundle。
- **正式網址驗收**：測試 source revision `c241cd2699650e1e54042d802fd37ab327836abe`，`AFTER_CLASS_BASE_URL=https://daan.lifenet.com.tw`，4/4 passed（51.6 s）。手機 390 / 桌機 1440：長中文、503 / network / 409 / malformed JSON、快速跨頁返回、重複點擊只一個請求、權威紀錄成功才收尾、帳號／分校／堂次隔離及過期回應。截圖人工檢查；保留於原隔離任務 `frontend/test-results/production-be1088d1/`，不是獨立 HTML 原型。
- 隔離情境攔截全部 API / 外部請求，沒有 production DB 測試寫入。另跑 [既有授權帳號唯讀 smoke](https://github.com/jerry200176-png/AllTrue_System/actions/runs/34591400025)：12 passed / 22 skipped（1.5 m）；主任課程查找、老師工作台／出缺勤／課表與評量／我的課表／科目統計均實際 passed。Skip 不算通過，也不把 fixture 登入冒充真實帳號驗證。
- 本批沒有相符原 in-app，使用既有 `staff-2026-09-11-after-class-confirmed-save` 公告；不建立假回報，不關閉 #1618，不替回報者確認 closed。文案依本次限定公告 GO 由 Astra 審定，不擴大產品承諾。
- 回退基線：`63b70302d894469e947a185fa77973ae16b70078`（本批啟用前已部署）。只透過既有 deploy.yml 交付本產品 merge 的 revert，不回退其他 CLI 的獨立成果，不回放儲存或通知；保留歷史草稿与後端紀錄。Rollback 路徑已審查，未為測試而實際回退正式站。
- 尚未驗證：真人理解率、真實課務 DB 寫入、真實 LINE 收訊。單元或瀏覽器案例數不替代這些證據。

- Astra 是交付與證據審查主責，同一 Luna 是唯一 worker；一次退修後若仍有缺口由 Astra 接手。
- branch `chore/task-after-class-confirmed-save-20260911`，base `a4e0194cd0627f9dc6f0b2c947931b8d9899f96f`（#2713），agent-control session `f5e229dd38d943569838358f520efff8`，metadata checkpoint `dfb314b8c`；原 agent-preflight 與 provenance passed。
- [#1618 所有權協調](https://github.com/jerry200176-png/AllTrue_System/issues/1618#issuecomment-5632922266)。沿用 TeacherHome→評量導頁與待辦；不接管 #2644 / #2653 / #2661。#2661 是 AssessmentPage，不是本包 LearningRecordsPage。
- API/DB 仍是權威：既有 POST store / update 回 hydrated record（201 或 200）；前端驗證身分相符才完成。保存成功不代表主任已核准，不改扣堂或學費。
- 現有 localStorage 草稿機制只補 scope，不新增 store；未能確認歸屬的舊草稿不猜測套入、不批次修復／刪除。無真客通知、歷史資料修補、migration、品牌或權限修改。
- 本產品增量沒有相符原 in-app 回報，不製造假回報；#1618 為廣義工作流 issue，不關閉。按 GUIDE_STAFF_UPDATES 先正式站驗證，再發布既有版本更新。

## 檢查原始結果與適用範圍

- Exo doctor：`FAIL: 6 checks, 5 errors, 2 warnings`；`missing dir: .exo/logs`；4 stale adapters（CLAUDE.md / .cursorrules / AGENTS.md / codex.md）；stale session `SES-20260901045942-87630ED6`。drift 詳查：constitution hash matches lock；config validation pass。
- `exo brief` 的過期 lock 清理刪除了 checkout 內已過期的 tracked ticket.lock（2026-09-08 09:00 到期）；已按原 HEAD 內容復原，未取得／冒稱該 ticket 所有權，未修改治理差異。
- 目前 portfolio-ops origin/main bootstrap v1.1 將 agent-control 定為 canonical、Exo 為 experiment-only；canonical 本機便利 checkout 仍有舊文字，不能用舊 adapter 加設第二套 gate。未 regenerate adapter、清全域 session、變更 classifier 或放寬必要 CI。
- P4 比對：#2699 merge `bce8daed103972badbb9be22253b1151388970e1` 已提供 generated-history 排除及真實 auth 負例。#2692 head `107c6fa25e906d45539ecb80a379dc726026468b` 尚含 PHPUnit snapshot/config 路徑與 whole-term marker／token vocabulary 改動；不整包重播，也不覆寫主線後續 CSS-selector 負例。現有 activation state 68 tests passed；本包不需更改 classifier。

## 驗收與停止條件

- 實際完整 App（不是 pilot HTML）：手機 390、桌機 1440，TeacherHome→原評量→失敗→快速 browser back→同堂恢復→重試→後端确认→工作台不再催修改。
- 隔離 fixtures 攔截所有 API；只證明前端已部署行為，不冒充 production DB 寫入或真人理解率。測試 revision、畫面、required checks 與正式站 SHA 已列於上方並在 scoped PR 回寫。
- 反覆失敗由 Astra 接手；required gate、身份／隔離／權威回應驗證不明時停止相關交付，不改 assertion 洗綠。
- Rollback：透過現有 deploy.yml 交付本包 revert；保留後端課務與歷史草稿，不回放提交／通知。上線前記錄正式站基線 SHA，回退後重新核對 health/version 與課後情境。
