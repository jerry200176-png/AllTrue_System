# Founder 單次、指定版本的程式啟用（R3 提案，尚未啟用）

來源：Founder 本次要求已核准任務不反覆詢問，並質疑第二次 GitHub 核准造成操作負擔。觀測：production-activation 只剩 main 分支規則，原 required reviewer 已缺少；既有部署在任何正式副作用前拒絕執行。此提案不修改 GitHub Environment 設定。

## 核准與安全邊界

保留既有 reviewer 路徑；沒有 reviewer 時，唯一替代是已註冊 Founder 本人發起既有 deploy.yml 的 workflow_dispatch / application-deploy。GitHub API 的 immutable run metadata 必須同時驗證 actor、triggering_actor、repository owner 的 user ID 與 login，與版本控制中的單一 Founder profile 一致；run ID、workflow path、main、target SHA、current main SHA、成功的 exact-target CI 與原 typed confirmation 全部一致。一次指定版本的啟用請求本身留下核准紀錄，不需第二次人工點擊。

自動 workflow_run / repository_dispatch 不能套用替代路徑。POP bootstrap、資料修復、身份權限、principal rotation 與其他 phase 的規則維持。仍禁止 admin bypass；只限 main；原共享 side-effect lock、artifact/CI/exact-main preflight、backup/recovery、health/smoke 及 deploy.yml executor 保留。若仍配置 reviewer，仍由 GitHub 強制 reviewer 核准；本變更不移除或假冒核准人。

## 實作與驗證

沿用 autonomy_gate.py、deploy.yml 及既有 102 項部署回歸，新增在相同 test module 的 4 項包含多組反例：錯誤身分或重跑者、未知 workflow/phase、不同 repo/main/SHA、未成功 CI、confirmation 不符、profile 未啟用均拒絕；profile 身分值由實際 JSON 載入並有變值驗證。無新 scheduler、服務或核准資料庫。

Founder profile 初始值由已驗證的 canonical repo owner 與先前 configured reviewer 相同身分建立，不授權其他帳號。此 PR 仍需 Founder 決策及完整 CI 後才能合併／首次使用；不是現在已上線的規則。Rollback: revert profile/helper/workflow changes together; absent reviewer returns to fail-closed. 沒有正式資料或 GitHub 安全設定變更。
