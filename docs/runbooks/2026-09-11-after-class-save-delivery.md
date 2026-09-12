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

## 2026-09-12 接續：in-app #285（尚未交付）

- 本輪啟動範圍：in-app #285／#286／#287；fresh queue run `34685461154`，detail runs `34685510917`／`34685519056`／`34685520422`，三件 freshness validator passed。其他既有 resolved 回報不重複通知或代替真人驗收。
- 接管基線與正式站 backend/frontend/build：`ff74c83e604191a5ae56ca543629be2e3577daf6`；deploy `34682878958` success，health ok。agent-control session `637daa9815dc42f783fbe84a8cf46acf`，branch `chore/task-product-delivery-20260912-main`；preflight passed。未提交舊成果留在原 worktrees。
- #285 附件 235 是主任編輯後的無法確認提示。先前只測沒有大寫學生欄位的 response；正式站唯讀取樣顯示舊 `StudentID=0` 與關聯派生 `student_id>0` 並存。migration `2026_04_24_000001` 設定預設 0，Model fillable 不維護該欄位；hydration 原先只補小寫欄位。這是 response contract 缺口，不是已證明的資料遺失。
- 最小 R2/T2 修復：儲存後 hydration 的兩個學生欄位均取課程學生關聯；不存回 legacy 欄位，不改 auth、campus、老師、堂次、狀態與前端 fail-closed assertion。老師／主任 create/edit 的 HTTP feature tests 已在修前 4/4 fail；修後加上不回顯錯誤 request student 的負例，與既有代課／復原測試共 31 tests／172 assertions passed；前端契約 5 tests passed。
- 正式 frontend 加隔離 API 回應：手機 390／桌機 1440 的課後儲存 4 cases，及 #287 的 390／1280 阻擋導頁 2 cases，共 6 passed；截圖在本輪 `/tmp/alltrue-delivery-20260912-bbCslW/browser/`。所有 API／外部請求攔截，沒有 production DB 測試寫入。這不代表正式 backend 已更新。
- rollback：透過既有 deploy.yml 交付本次 merge 的 revert；無 migration/data repair，不回退其他已交付成果。#285 原串受理留言 685 已存在，待部署與驗證後才追加完成回覆。

## 驗收與停止條件

- 實際完整 App（不是 pilot HTML）：手機 390、桌機 1440，TeacherHome→原評量→失敗→快速 browser back→同堂恢復→重試→後端确认→工作台不再催修改。
- 隔離 fixtures 攔截所有 API；只證明前端已部署行為，不冒充 production DB 寫入或真人理解率。測試 revision、畫面、required checks 與正式站 SHA 已列於上方並在 scoped PR 回寫。
- 反覆失敗由 Astra 接手；required gate、身份／隔離／權威回應驗證不明時停止相關交付，不改 assertion 洗綠。
- Rollback：透過現有 deploy.yml 交付本包 revert；保留後端課務與歷史草稿，不回放提交／通知。上線前記錄正式站基線 SHA，回退後重新核對 health/version 與課後情境。

## 本批接續：in-app #286 輔導課下一期（開發中，尚未部署）

- Founder 於 2026-09-12 主 CLI 明確批准：從既有輔導課複製設定建立下一期、保留前後期關聯、費用為 0、不建立付款義務；一般付費課規則不變。批准證據：[issue #2760 留言](https://github.com/jerry200176-png/AllTrue_System/issues/2760#issuecomment-5645043931)。不包含歷史修復、帳款搬移、migration、權限擴大或其他 runtime activation。
- R3/T3 scoped GO；task `chore/task-tutoring-continuation-286-20260912`，session `62a7401baf5e4719a175d2abf3892f9a`，base `ff74c83e604191a5ae56ca543629be2e3577daf6`，preflight passed。原回報 #286、issue #2760；本批其他 in-app 範圍仍是 #285/#287，不擴張清單。
- 架構：新增明確 tutoring endpoint，主任／管理員分校權限、鎖來源／學生／群組、複用既有排課與 CourseContinuity 關聯；不改 paid renewal/purchase。Charge/Pay/Paid 歸零且 PayDate 空，Rate 保留既有課務／核薪語意；不建立 Invoice/Payment，不變更舊課、出席或評量。
- 獨立 SEC/ARCH review 指出並修正：共用方案不得誤走付費加購、首堂須符合原固定星期、手動排課不可偷偷轉自動。最新本機相關 API suites：36 tests / 227 assertions passed，含舊付費／關聯 regression；前端既有 high-risk flows 6 passed，Vite build passed，PHPStan no errors。完整 App 的 390/1440 失敗保留／重試／零應收及共用方案防誤寫共 3 browser tests passed；舊版正式前端的兩個尺寸 before 截圖亦已取得，所有 API 均隔離攔截，不是 production DB 寫入證據。CI 與部署尚待最終 head 驗證，不宣稱已交付。
- rollback：以正常 PR revert 並透過 deploy.yml 交付，停用新入口；不刪已建立的下一期、不搬回帳務或歷史紀錄。必要資料處置另取授權。原串完成回覆需在 exact-SHA/runtime 核對後，不能代替回報者驗收。

## 2026-09-12 本批對帳與低風險 UI 接續

- **18:40 最終 #286 里程碑**：main CI `34688472717`、deploy `34688731527` success；依主 CLI 已有 GO 在同 run 記錄 `production-activation` 審核，沒有擴大到 #287。2026-09-12T10:40:16Z identity GREEN，backend/frontend/build 全部 exact `a975e4b6ae3137ae8cb0bb92f8f6c0fe188e08ce`、health ok、pending/drift 空；正式前端隔離 API 的3個手機／桌面流程 passed。原串公開回覆 **695**、resolved evidence 已写；GitHub #2760 closed，未代建真人正式課程、未代按 reporter verify，operationally accepted 尚未確認。下方 earlier pending 狀態由本條覆蓋。
- 範圍再對帳：API 共287筆、max ID287，無新 report ID；#274 在本批期間以 comment692 重新反映手機底部遮擋，status in_progress。已 reopen #2606、原串回覆694，要求辨識新被遮擋面板／畫面，不冒充舊單堂視窗修正涵蓋所有情境；屬啟動後非新事故之後續手機案件，保留下一批，不擴張本批業務範圍。
- UI 最終本機：整合 #286 後完整 unit **106 files／548 tests**、完整 build passed；Attendance full-App recovery 390/1440 **2 passed**、tutoring full-App **3 passed**、page/component browser **12 passed**，兩個 baseline-only cases 非正常驗收項。完成訊息另去除內部課程ID，manual 零預排顯示下一步而不是 null 結束日；API payload 和財務/權限保持不變。#2768 仍須 exact-head CI 與後續 production 驗證。

- **最新狀態覆蓋下方較早里程碑**：#287 新 comment 691 指出一般取消仍會依舊四堂上限補排；已回到 in_progress，GitHub #2761 reopened，公開回覆 693 明確撤回「行事曆導頁可完成需求」的建議。根因為既有測試直接 DB cancelled，未覆蓋使用者取消／補排路徑。已向主 CLI 申請限定未收款按堂課的「主任確認後原子下修總數／金額／超額預排」GO，尚未批准、未實作或代改正式資料；不得再次視為 resolved。
- #286 已 merge：PR #2766 最終 head `a5923dac85f62f2fa6e52079000f39f02c2f069a`，merge `a975e4b6ae3137ae8cb0bb92f8f6c0fe188e08ce`，PR CI `34688163020` passed；main CI `34688472717`／部署仍待確認。
- Security #2767 deploy `34688143363` attempt 2 success；2026-09-12T10:25:30Z production-identity GREEN，backend exact `577a0a25484999042250088bac202b887a4fbd15`，無前端變動所以 frontend/build 保留 `41d959b9…`，health ok、pending runtime/drift 空。沒有對真人正式資料提交攻擊測試，不冒充 live exploit test 或 operational acceptance。#977 已記錄 evidence；#3 仍 open。
- GitHub #2743／#2727／#2751 已依最新原串與 production ancestry 關閉：#281 reporter comment 679 確認正常、既有 reply 680；#283 已交付 reply 681；#284 已交付評量減噪 reply 684（後來686只限制其增量切片）。未再次通知或代按 in-app reporter verify。#2715 依其明確等待回報者的紀錄保留。
- UI #2768 為 draft，等待 #286 runtime 後才合併；完整前端 105 files／543 tests passed，最終整合 head 的 browser 截圖使用 `ui-mobile-final/`。原 #2653 保留到來源差異確認整合後。

- #287：已交付 #2763（merge/deploy `ff74c83e604191a5ae56ca543629be2e3577daf6`、deploy `34682878958`）；本輪以 production `41d959b9…` 再跑手機／桌面隔離流程 2 passed。保留公開回覆 689，不重複通知；已寫 resolved evidence 指向 `41d959b9c9f298c66a2efbd19307be3d49f0c381`／`34686619551`，reporter 尚未 verify。修的是錯誤說明／下一步，不冒充已替使用者取消排程、改堂數或帳務。
- #286：完整本機 PHPUnit 2,301 tests／10,583 assertions，11 既有 skips，無 failures；新測試改精確 baseline+delta 是為兼容整套 fixtures，不刪 assertion。獨立 review `1e5f21cc0f6d1f4ffc6db0708c54bd8af6546912` passed；該 head required CI 全綠。後續納入已合併 #2767；最終 merge/deploy/runtime 仍待核對。
- Security #2767：source #2764 head `235857f4138fe4bf0a6884df41c427e3622c2e85` 的固定欄位驗證完整保留，補上混合文字／檔案輸入拒絕；49 API tests／244 assertions、PHPStan、exact-head CI `34687587529` 與獨立 review passed。merge `577a0a25484999042250088bac202b887a4fbd15`，main CI `34687835027` passed。deploy `34688143363` 首次因 PR 欠明確 Rollback 欄位停在 executor 前；已補具體回滾 evidence 後重跑，不修改 gate。此時尚未確認部署。
- 已關閉重複 PR #2762／#2764：前者 merge-tree 與 current main 的產品及 staff updates 無剩餘差異（由 #2763 整合），後者由 #2767 保留並補強。原 branch/worktree 與未提交成果保留。
- UI 草稿 #2646、#2648–#2651、#2653–#2662（存在的 PR）、#2666、#2668、#2673、#2676、#2679、#2680、#2682 仍有獨有產品差異，不能當重複案關閉。本批只接續 #2653 點名觸控／狀態提示；其餘保留後續獨立驗證。#2677 仍有行事曆權威檢查及過去堂次確認改動，#2021／#2626 涉帳務取捨，#1991 是延後的 spinout RFC，均不整包帶入。
- Issues 不以 in-app resolved 自動清零：#2715 明確等待回報者；#2751／#2727／#2743 的 GitHub 紀錄仍需與最新原串補充對帳。#2135 是未完成 umbrella；#2742 是非阻塞的同 SHA provenance 競態，保留 fail-closed 與既有 Founder boundary，不新增治理改動。
- Security 對帳：Dependabot open 僅 #3（Laravel）；維持 open／#977 framework track，未 dismiss。Code scanning API 回 no analysis found（404），不是零漏洞證據；secret scanning list 回 1 筆已 resolved 的 Telegram 通報，另有 scope 警告，不能宣稱全面掃描完成。#1007 最新既有證據為 credentials containment 完成、公開歷史 object 清理未完成；不重播 rotation、不 force-push，保留協調與 GitHub-side purge blocker。
- 分支 dry-run 無 remote merged deletion 候選。四個本機 branch 已核實零 unique commits、無 worktree、無 open PR／upstream dependency 後刪除參照：`test-push-verify`→`895bc724abb836e1d14c406b8b0b8992fe907a74`；`chore/compact-release-notes-20260831`→`d34549f2ac6355029696926629a404577bbf29ba`；`chore/docs-runtime-log-cleanup`→`dca6773b88584c124805570813698db855925671`；`chore/exempt-dependabot-provenance`→`e2f2a390c7d2c7550d655d9face627820a779591`。commits 均留在 main 歷史，可用上述 SHA 重建；未刪目錄或未提交資料。
- UI scope：task `mobile-attendance-copy-20260912`、session `23f7107a635d489a8e9007f7e4a6afe6`、base `577a0a254…`。接續 #2653 head `146f4868c47f23ed45e6171e15e480823d5a4d20` 的 Attendance 模板/CSS，新增實際點名 payload contract 與 CourseEditForm 參考單價文案測試。無 script/API/權限／付款／點名語意變動；Rate 仍保留原值。
- UI 本機證據：390/412/768/1280/1440、密集/空白/loading/error/長中文及單筆點名與輔導/付費表單 12 browser tests passed（2 個 baseline-only cases 在正常模式刻意 skipped），新 Vue tests 2 passed、完整 build passed。baseline 兩個尺寸各頁已拍；前後圖在 `/tmp/alltrue-delivery-20260912-bbCslW/ui-attendance-before`、`ui-course-edit-before`、`ui-mobile-after`。真 Vue + 合成 API，未寫正式資料；觸控高度達 44px，不宣稱誤觸率或效率已量測改善。
- UI rollback：正常 revert PR/deploy，僅回復呈現；不改任何點名／帳務資料。最終 CI、merge、deployment、production 驗證與原串對帳尚待本批完成時更新。
