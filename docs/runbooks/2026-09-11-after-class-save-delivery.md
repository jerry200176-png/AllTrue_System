# 課後儲存確認：限定交付證據

狀態：整合驗收中，未部署。Founder 2026-09-11 GO 包含本包實作、測試、必要 metadata、green-gated merge、production activation 與既有公告；不是 PLAN.md 全包授權。

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
- 隔離 fixtures 攔截所有 API；只證明前端已部署行為，不冒充 production DB 寫入或真人理解率。測試 revision、畫面、required checks 與正式站 SHA 將在 scoped PR 回寫。
- 反覆失敗由 Astra 接手；required gate、身份／隔離／權威回應驗證不明時停止相關交付，不改 assertion 洗綠。
- Rollback：透過現有 deploy.yml 交付本包 revert；保留後端課務與歷史草稿，不回放提交／通知。上線前記錄正式站基線 SHA，回退後重新核對 health/version 與課後情境。
