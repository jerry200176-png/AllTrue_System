# #5120 rollback data-integrity 調查（本機證據，未修後端）

SourceRef: `alltrue:bug_report:523`；既有 draft PR #5120／issue #5114。

分析版本：本機 `9e81a7356f3b79ebe36dfbd286fa70c120aa8a66`。Backend runtime 與受審 `1ad294d3f8e06b1d98bdd21cdc9ed7ca17cc2715` 相同；新增的 backend 內容只有先前獲准的 diagnostic test。本次追加工作僅唯讀分析與文件，不修改後端。

## 結論與證據強度

- **已重現**：兩份先綁定為 approved 的 LearningRecord model，A 成功退回後，B 仍以 stale model 執行 controller，兩次均回 200；TeachingSessionCount 從 2→1→0，另一堂仍 approved。StudentClass 的 row lock 沒有使記憶體中的 route model 自動變新。
- **本次沒有重現第二次財務沖回**：在此序列，B 沒有再降低 UsedSessions 或 XP，reverse ledger 仍一筆、approval sign-in 已作廢；fresh 第二次 HTTP request 讀到 pending 則回 409。
- **未證明**：所有交錯均安全／均失敗、production 曾發生、production DB isolation／schema 與 fixture 相同、兩個真實 HTTP process 的等待鎖／deadlock 行為。不得把這個 characterization 當成正確產品契約或已修復證據。
- **尚未修改** controller／service／financial semantics／authz／schema。前端防重只能保護同一掛載頁面，不提供跨 client exactly-once。

## 隔離與可重現案例

證據工具與 source hashes：[manifest](5120-evidence/manifest.json)、[metadata subscriber](5120-evidence/metadata-bootstrap.php)。實測輸出：[SQL templates／isolation／26 assertions](5120-evidence/sql-diagnostic.txt)、[16-test subset](5120-evidence/subset-test.txt)。Test source：`backend/tests/Feature/LearningRecordReviewerFromLoginTest.php` 的 `test_diagnostic_stale_bound_rollback_decrements_teacher_count_twice_but_reverses_usage_once`。

| 項目 | 實際觀察 |
|---|---|
| DB | `AllTrue_test`，全新 Docker MySQL 容器 `alltrue-523-db`；未 mount 正式資料、repo .env 或憑證 |
| Network | Docker `alltrue-523-test`，`Internal=true`；PHP 與 DB 只在此網路互連，未 publish DB port |
| Runtime | PHP 8.2.35、Laravel v12.70.1、PHPUnit 11.5.57、MySQL 8.0.46 |
| Test connection isolation | `REPEATABLE-READ`，由 PHPUnit Test Prepared subscriber 在實際 test connection 查 `@@transaction_isolation`，非只引用 server default |
| Transaction nesting | subscriber 實測 `transactionLevel=1`；RefreshDatabase 外層交易，controller 的 DB::transaction 為同連線內層／savepoint |
| Fixture | 同一老師、同一合約、兩個不同日期的 LR，均透過既有 approve API 核准；計數 2，且有 XP／sign-in／deduction fixture |
| Test identity | 真實 API middleware 驗證 A；B 的 controller replay 使用相同已驗證 director／campus attributes，不是額外 auth bypass 功能 |

Prepared evidence（只有合成 metadata）：

```json
{"db":"AllTrue_test","isolation":"REPEATABLE-READ","mysql":"8.0.46","transaction_level":1}
```

交錯是**決定性重放**，沒有 sleep 或以 timeout 猜測成功：

1. 用 approve API 核准 LR-A 與 LR-other，確認 teacher count=2。
2. B 先 `fresh()` 綁定 LR-A，記憶體狀態 approved；A 的 API request 也讀到 approved。
3. A 開始退回、完成既有 transaction；LR-A=pending、count=1、一次 XP 撤銷與 usage reverse。
4. B 帶步驟 2 的 model 呼叫實際 `rollbackApproval()`；雖然資料庫已 pending，controller 先檢查的是 stale property。
5. B 的 StudentClass `FOR UPDATE` 已在 A 之後；沒有 LearningRecord locking re-read。B 又回 200，count=0；other LR 仍 approved。
6. 再以 fresh HTTP request 送退回，回 409。測試同時確認 B 不再降低 usage／XP、只留一筆 reverse。

這個安排證明 A 結束後仍可重放 stale-bound B 的既存缺口；它沒有真的建立兩個並行 HTTP process，因此不聲稱測得 lock-wait 時長、deadlock 機率或所有 RR snapshot 排列。

## 交易邊界與鎖順序

完整靜態候選清單及可重建工具：[353 個顯式邊界／168 個無本地邊界 write 候選](2026-11-08-5120-transaction-inventory.md)、[PHP tokenizer scanner](5120-evidence/inventory.php)。掃描 backend/app 558 個 PHP 檔，249 檔文字相關。清單逐一保留具名方法、transaction 與 lock source lines；不是只抽搜尋前幾筆。

`SC`=StudentClass，`CS`=ClassSession，`LR`=LearningRecord。下表區分顯式 row lock 與 UPDATE 取得的隱式寫入鎖。未寫在方法內的鎖，可能在 helper／observer／caller 內。

| 邊界／入口 | 現行順序與共享資料 | 判讀 |
|---|---|---|
| LR `rollbackApproval`（controller 1589） | route-bound status → transaction → **SC lock** 1605 → XP event DELETE／engagement lock → LR UPDATE → User decrement → sign-in UPDATE → reverse ledger／package／SC recompute | 已確認 stale LR 缺口；LR UPDATE 隱式鎖不能追溯修正先前 property 判斷 |
| LR `approve`（1542） | route-bound status → transaction → LR UPDATE → User increment → syncOnApprove → CS／sign-in locks → deduction SC／ledger／package → XP → transaction 後 NotificationSync | 不是 rollback 的鏡像鎖序；此入口本次未做雙 client race，不能宣稱已確認重複核准 |
| LR `update`（1267） | transaction → **LR lock＋重讀** 1287 → ownership／status／正文保護 → mirror sync／save | 已有 fresh-row guard；單獨把其他入口改 LR-first 仍須查 caller 互鎖 |
| LR `updateTeacher`（1373） | transaction → protectedRecordForSession(lock=true) → possible occurrence/cascade → refresh LR → LR／teacher-comment write → optional SC teacher write → User counters／occurrence | `refresh()` 本身不是 row lock；guard helper 已取得 LR lock，不能把方法內沒寫 lock 誤判無鎖 |
| LR `destroy`（1498） | role／campus／filled-approved guard 在交易外 → transaction → author snapshot → possible XP／User／usage rollback → LR DELETE | route-bound／delete／rollback 交錯須另測；本次未重現 delete race |
| LR batch approve/reject/requestChanges（1635／1749／1787） | 交易前查詢 collection，再在交易內迴圈 LR write；approve 再進 User／CS／SC／ledger／XP | collection 不是鎖內重讀；多 LR／多 course 的順序與部分成功政策需一起考慮 |
| LR single reject/requestChanges（2179／2156） | route model 檢查 approved，然後 save，無本地顯式 transaction／fresh guard | 是需檢查的狀態競爭入口；不是已驗證損毀 |
| store/backdoor/bulkBackdoor（1053／1825／1915） | transaction；查 SC／CS／既有 LR，可能建立／恢復，系統核准再進 sync／ledger | 有 caller-owned transaction 與 DB uniqueness 保護；不等於所有 status transition 被序列化 |
| ensurePast／Backfill／Resurrection／Integrity | 多個方法沒有自有 transaction，依 caller；Integrity repair 明確 CS lock → LR 建立／作廢；CS observer 可再觸發 LR／sign-in 收尾 | bulk query update 與 Eloquent save 的 observer 行為不同，CAS 不可忽略它 |
| ApprovalSessionSyncService | payableSignIn：transaction → **CS lock → live/voided sign-ins lock**；syncOnRollback 無自有 transaction，先 plain-read approval sign-in，再作廢 → reverseForSession → CS UPDATE → recompute | helper 的 nested transaction 不會提早釋放 outer transaction 已持有的鎖 |
| SessionDeductionService | deduct/reverse：transaction → **SC lock → ledger net current read**；reverse 再鎖 matched deduct minutes → append ledger → package sync；recompute 再 SC lock | ledger net 提供 usage 的序列化，沒有保護 TeachingSessionCount 或 LR 狀態 gate |
| PackageDeductionService | transaction → CoursePackage lock → package ledger shared/current reads → append → counters；部分 null-session 寫入分支不同 | 共用 package 可能跨 course，不能假設 SC lock 已涵蓋所有共享寫入 |
| XP award/revoke／LR model observer | revoke：event plain read → DELETE → engagement lock → XP update；LR VoidedAt save observer 也能進 revoke | current replay 第二次 event 已不存在；別的外層 snapshot／void interleaving 未驗證 |
| attendance/RFID | Attendance.store／swipe、RecordStudentSwipe 的課程與堂次 guard 路徑：**SC → CS → sign-in**，再 deduction／LR restore/backfill | 是既有 SC-first 參考；caller-owned transaction 必須一起看 |
| CS status/update/restore | `ClassSessionController.update` 有 **CS-first** transaction 分支，再進 LR／sign-in／deduction(SC)；restore 的 artifact lock 是 LR → sign-in | 與 SC-first 路徑存在反向取得鎖的候選；尚未做兩 process deadlock 證明 |
| leave／reschedule／occurrence | CourseLeaveCascade apply/undo：caller transaction 下 **SC → ordered CS** → LR／sign-in；RescheduleSessionService.execute：SC lock 再 schedules／CS／LR；OccurrenceAssignment：CS → protected LR → schedules | teacher guard、history pin、schedule ownership 都可能與 LR lock 交會，不能只改 controller 一處就宣稱全域有序 |
| SC 更新／結案／轉移／拆約 | controller 的 plain update 與 model hooks、togglePause 明確 SC lock；transferSessions 是 source SC → target SC；applyTransfer 是 SC → CS；SessionEntitlementTransfer 是 source SC → target SC → CS → LR/sign-in/ledger；amendment/financial correction 各有獨立鎖集合 | source→target 不代表全域 ID 排序；雙向轉移／老師變更／rollback 的交錯列為必要測試，未宣稱實測 |
| SC money/settlement model hooks | saving/deleting 可呼叫 terminalClosureInDb 的 SC `FOR UPDATE`；saved 清 settlement cache | 隱藏在 model event 的鎖是候選方案必須保留的邊界 |
| financial／POP／repair／commands | 全部掃描候選保留於 appendix（包含只新增、修復、preview/dry-run helper） | 本次只讀 source，沒有執行任何 repair／正式 job；不把 lexical match 全部列為已確認漏洞 |

跨方法的 source-order 不是全域 lock graph 證明；動態 dispatch、關聯載入、DB constraint／gap locks、trigger、不同部署 schema 仍須獨立驗證。上述涵蓋所有靜態命中的邊界；不能將未做語意／runtime audit 的項目寫成安全通過。

## 最小併發保護候選（只分析，未實作）

| 候選 | 最小改動意圖 | 語意／風險與必要前提 |
|---|---|---|
| A：course-serialized fresh transition | transaction 內鎖 SC，接著鎖定並重讀 LR，再檢查 active/status/current course/current teacher，只有 approved→pending 的 winner 執行所有 side effects | 保留既有 API／409 與業務效果；route model 只能提供候選 ID，不能決定狀態。若 SCID 已被轉移，需 abort/retry 並按新 owner 重新驗證既有 campus scope。SC→LR 仍可能與 CS→LR→SC 路徑互鎖，先完成 caller/observer 測試，不能直接加鎖就宣布完成 |
| B：conditional transition / CAS gate | 鎖內以 id＋approved＋未作廢（必要時包含 current ownership/version）條件更新，affected rows=1 才可撤 XP／減 count／reverse；0 則保持既有拒絕語意 | 不需 migration，但 UPDATE 仍取得 LR 寫鎖，沒有消除順序風險；query builder 會跳過 model events，必須審核 observer 與 reviewer/audit/response 契約。不可先撤 XP 再做 conditional gate |
| C：只在開頭 LR FOR UPDATE | 對齊 LR.update 的 fresh read，能阻擋同列 stale status | 不是推薦的孤立修補：LR→SC 與既有 SC→LR/CS writers 可反向。必須同時證明受影響 call graph 的 order 或 bounded deadlock retry；retry 必須重跑整個 transaction 並重新判斷，不能重播 route object |
| D：較後期的 transition receipt/idempotency | 可追溯 operation receipt／operation key，支援 ambiguous response reconciliation | 會涉及新持久合約，可能需 migration／權限／保留政策，超出本次範圍。不能只以 LR id 永久去重，因 approve→rollback→approve→rollback 是合法不同 cycle；瀏覽器鎖／快取鎖不能替代 DB transition guard |

推薦先評估 A 或 B 的**原子狀態門＋完整交易**，先不改任何授權、金額或 entitlement 規則；在授權後的小範圍後端修復中完成下列測試再選定。不是批准立即實作，也不是要求一次重寫 appendix 的 353 個方法。

## 是否涉及 billing／ledger／財務

**是，屬 data-integrity critical。** TeachingSessionCount 的已觀察漂移不等於現金帳損毀，但 rollback 呼叫本來就會撤銷 approval-driven sign-in、反向 session deduction、同步 package ledger、重算 Remaining/UsedSessions，並改變 CS 是否 attended；這些會影響 entitlement、出勤／薪資資料讀側。不能以「只是 UI」或「沒有改 Invoice」把後端修復降為 display-only。

此路徑未直接改 Invoice／Payment／receipt amount，不代表與財務無關。任何候選需保持 waived/refunded/settlement 鎖、歷史 deduction minutes、package cycle、teacher attribution、campus scope 與既有 ledger authority。不能在本次順便更正計數、補帳、修正式資料、加 migration 或改權限。

## 必要測試（候選後端修復前，尚未全部執行）

| 情境 | 必須觀察的不變式 |
|---|---|
| 同 LR stale-bound rollback×2、fresh duplicate | 只有一次合法 transition／count decrement／XP revoke／usage reverse；loser 依既有拒絕合約，不能用兩次 200 假裝冪等 |
| 真正兩個 process／獨立 PDO 的交錯 | committed fixture、barrier 控制 bind/read/lock/commit；RR 與有需求時 RC；含先建立 snapshot 與 lock-wait。記錄最終權威列，而非只測 HTTP status |
| rollback vs approve／batch／reject／requestChanges | 所有可序列化順序均與最終 LR 狀態、User counter、XP、attendance、session/package net 一致；後到者不得依舊 collection 寫入 |
| rollback vs dirty editor／teacher reassignment／void/delete | 文字、author snapshot、TeacherID 與 count attribution 不漂移；本 PR 的 frontend requery 不覆寫 dirty text 或異筆 modal |
| rollback vs CS cancel/leave/reschedule／restore／RFID | CS、LR、sign-in 無幽靈狀態；deduction net 不重複；同堂點名與 approval sign-in 的既有差異保留 |
| SC transfer／close／waive／refund／amendment | current course/campus/teacher 必須在穩定 ownership 下驗證；empty scope、foreign campus、teacher 仍拒絕；不能解鎖終態合約 |
| 多 LR 同 teacher／多 course 同 package／雙向 transfer | 異 LR 的 teacher/XP 共用資源正確；鎖排序包含雙向／排序相反輸入，必要時捕獲 bounded deadlock 並驗整體重試 |
| 部分扣堂／跨 package cycle／獨立點名 | reverse minutes 與原 deduct 相同；獨立點名不被 rollback 作廢；無第二筆不當 package credit |
| fault injection 每個 effect 間拋錯 | 整個 transaction 回滾，LR／User／XP／sign-in／session／兩套 ledger 都不留下部分成功；post-commit notification 不可引發交易盲重播 |
| server committed but response lost／bad 2xx | client outcome unknown 先讀後決策、保留輸入；receipt 或讀側不等於取消仍在執行的 server request，跨 client 保護仍由後端負責 |

## Rollback 邊界

- 現在沒有後端 runtime 變更可上線或回滾；本機只有 front-end 修復、diagnostic test 與文件。可以用普通 revert 還原這些本機 commit；**不推送**。
- 日後若候選後端修復獲准，優先無 schema 的窄幅原子 transition；rollback 以正常 PR revert code，維持既有 schema／API；不能用 code revert 宣稱已回復已完成的業務操作。
- transaction 內故障以 DB rollback 還原整體 side effects；檢查 nested savepoint、outer retry、模型 events 及 post-commit 動作，不能只靠增加 retry 次數。
- 已成功的正式退回或歷史 counter drift，若未來需要更正，必須另立授權範圍、不可變 manifest／snapshot、逐筆驗證與正式 repair／rollback 計畫；本次不查也不修。

## 本機驗證結果與限制

| 執行 | 結果 |
|---|---|
| 修復前 frontend finding reproduction | 16 failed / 22 passed；重現 stale-success 丟棄與 outcome-unknown 無鎖問題 |
| 最終聚焦真實元件（rollback＋既有 view/queue） | 60 passed，含最後補上的 parallel counts、query-vs-modal、failed-refresh recovery 案例 |
| 完整 frontend suite | 323 files / 2201 tests passed；啟動時尚未加最後 4 個測試，後補案例已含於上述最終 60，未聲稱這次完整 run 的檔案快照等於最後 test file |
| Playwright 本機 synthetic pilot | 5/5 passed；桌面／手機與 footer 成功路徑 |
| npm run build | passed，含 lint/no-undef、unused baseline、UI ratchet、既有測試及 Vite build |
| isolated diagnostic（含 isolation subscriber） | 1 test / 26 assertions passed，量得實際 test connection metadata |
| isolated reviewer/campus/XP subset | 16 tests / 125 assertions passed；267 個 PHPUnit deprecations，非 warning-free；初次預設 128 MB discovery OOM，改僅本機 runner `memory_limit=512M` 後完成 |

最終 complete CI 沒有被重跑或冒稱綠燈。受審 draft 的 control_plane／子工作 skip 與 aggregator `SHARDS=skipped`／`CORE=skipped` 的紅燈，並不表示 PHPUnit／Vite 已執行後必然失敗。本次不改／關／弱化 gate。

## 重現方法（僅隔離環境；不會在此自動執行遠端操作）

使用已準備的 `backend/vendor`（manifest 列出 composer.lock hash）。建立臨時 app 時，只複製 app/bootstrap/config/database/routes/tests/vendor/artisan/composer.json/composer.lock/phpunit.xml；**不複製 .env 或 cached config.php**。測試 XML 的 DB_HOST 改為下方獨立容器名，DB_DATABASE 保持 `AllTrue_test`，沿用 repo 測試 fixture admin／ci-ephemeral，不使用正式憑證。準備 storage 測試目錄。

PHP image：官方 `php:8.2-cli`（本次 pull digest `sha256:604de2f0cba9e9dde16cf717c2f03b0948c99949bdf7c11cdf76326e0da7d2ea`），以 `docker-php-ext-install pdo_mysql pcntl` 建立本機 `alltrue-523-php`。此 build 使用 `--network none`，只編譯 image 內建 source；MySQL image digest `sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`。

```sh
# 僅供新的 disposable names；勿連既有 DB 或改正式設定。
docker network create --internal alltrue-523-test
docker run -d --name alltrue-523-db --network alltrue-523-test \
  -e MYSQL_ROOT_PASSWORD=fixture-root -e MYSQL_DATABASE=AllTrue_test \
  -e MYSQL_USER=admin -e MYSQL_PASSWORD=ci-ephemeral mysql:8.0
# 等自己的 MySQL ready，才執行；不 publish port。
# TEST_APP 指向上述乾淨的臨時 app；EVIDENCE 指向本文件旁 5120-evidence。
docker run --rm --network alltrue-523-test \
  --mount "type=bind,src=$TEST_APP,dst=/app" \
  --mount "type=bind,src=$EVIDENCE,dst=/evidence,readonly" \
  -w /app -e APP_ENV=testing -e DB_DATABASE=AllTrue_test alltrue-523-php \
  php -d memory_limit=512M vendor/bin/phpunit \
  --bootstrap /evidence/metadata-bootstrap.php \
  --filter test_diagnostic_stale_bound_rollback \
  tests/Feature/LearningRecordReviewerFromLoginTest.php
```

原始本機 logs、完整 lexical JSON、隔離 app copy 保存在 `/workspace/work/alltrue-523/`；沒有上傳或公開。Subscriber 只輸出 metadata 和 SQL templates，不輸出 bindings／憑證／私人資料；source hashes 讓後續重建者核對版本。完成後可刪除**自己新建**的容器／匿名 volume／internal network；不可套用到共享環境。

## 遠端凍結

#5120 保持 draft，遠端 head `1ad294d3f8e06b1d98bdd21cdc9ed7ca17cc2715`。本輪未 push、建立 PR、edit PR、發評論、dispatch workflow 或部署。`ui-preview.yml` 的 opened/synchronize 有共用 Cloudflare preview 與 sticky-comment 副作用；不得以 draft 推論無外部寫入。所有新成果只保留本機，不修改 workflow 安全條件。
