# In-app #535 — 同價同堂長跨科轉用

SourceRef: `alltrue:bug_report:535`; canonical intake/decision: #5197.
Founder 2026-11-15：採同一學生、同校區、同原約定單價與堂長的轉用範圍。
此決定不是 Agent 執行任何 production 舊資料轉用的授權。

## 問題與範圍

歷史回報希望 A 科已付費的剩餘堂數可供 B 科使用；附件中的 B 科另有舊欠款。
現有 cutover 轉課會搬日期後的堂次／紀錄，手足轉讓會改學生並取消多餘排課；
兩者不能直接當成同學生跨科餘額轉用。已使用合約也不能直接綁入新共用包。

採分批交付，不能把預覽或安裝工具當成整體需求完成：

1. **唯讀預覽**：服務端驗證可用額度與金額；沒有執行端點或前端轉用按鈕。
2. **原子轉用與介面**：重算、驗證、成對帳務與專用稽核同一交易提交，通過
   受影響讀取端、衝突／重試、失敗回滾與逆轉邊界測試後，才走既有發布控制。

寫入原型超過既有單 PR 邏輯規模上限，先抽出窄帳務 primitive／鎖定預覽與共用測試，
並修正已確認的零堂數結案重算問題。此先行批次沒有 schema 或執行端點，不代表
跨科轉用已可操作；剩餘原型保存在隔離分支，後續依實際 diff 與完整驗證交付。

## 首批：唯讀預覽契約

`POST /student-classes/{studentClass}/subject-credit-transfer/preview`
只接收另一科合約 ID 與正整數堂數。沿用 director/super_admin、require_campus、
password-change、terminal 拒絕；super_admin 可跨其管理校區讀取，但兩份合約
仍必須是同一學生。其他角色必須有來源學生校區；空 scope 不給權限。
另有每分鐘 20 次限制。

- 來源與 B 範本必須是單科堂數制、按堂計費、固定整堂扣堂、無折扣，且有
  明確正單價與堂長；不得用目前價目表、預設堂長或四捨五入猜原單位。
- 用現有鎖定版本 `brick/math 0.14.8` 做精確有理數比較與乘法；直接宣告
  已有依賴。另將既有 Symfony Filesystem 7.4.21 升至 7.4.23，修補官方
  [GHSA-6prc-h956-hxjq](https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq)
  路徑邊界漏洞；其餘 package 版本不變。既有 `Rate` 是 FLOAT，不能把它宣稱為
  新增的不可變價格快照；單價 × 購買堂數還須與兩份合約學費完全相符。
- 來源必須在籍、未停課結案，僅一張可歸屬且已付清帳單／項目，原收款淨額、
  帳單與合約學費相符。Paid flag 不能代替真實收款；待確認、折扣、多帳單、
  退款、沖銷、轉入／轉出或共享項目等不明情況拒絕，不自動修帳。
- 用 `SessionDeductionService` 的 canonical 使用量，不以殘留的 stored counters
  增加額度。零碎分鐘、取消殘留、超扣拒絕；stored 漂移另外標示，不覆寫。
- 未點名且已結束的待處理堂次阻擋。未結束排課保留額度，沿用
  `SessionStatus::futureReservationExclusionStatuses()` 排除已取消／准假，
  待核准請假、暫停等仍保留，
  可轉用 = 購買 − canonical 已用 − 保留排課；不取消、搬動或重生堂次。
- canonical 使用量截至今天，提前標記已上課的未來堂次須先拒絕核對，不能同時
  從使用量和保留排課排除。已發生的 completed／attended／late 正常扣堂不變。
- 單價可精確對帳的小數不任意捨入；本次轉用金額必須可用既有整數帳款表示。
- 先鎖學生，再依 ID 鎖兩份合約、來源發票／項目／付款／回報／堂次，然後才讀
  canonical 投影。所有 preview transaction 都 rollback；一般 auth 的既有
  token/profile 行為不在「domain 業務資料不變」的主張內。
- 回傳額度、金額、B 原欠款及診斷 hash。`execution_available=false`；hash
  不是授權，也不承諾可被未來 writer 直接使用。writer 必須重新鎖定並計算。

## 後續寫入的驗收與恢復邊界

新 B 額度批次沿用 B 教師／科目、同單價與堂長、manual_occurrence，不自動排課。
B 舊合約、帳單和欠款不變；A 原收款、收據、已上課、學習紀錄與點名不搬動。
只抽取手足 writer 的窄帳務 primitive，保留手足原流程與規則。

專用長期 audit 綁定學生／校區、A／B 範本／新 B、堂數、單位、金額、成對付款、
actor、reason、來源範圍唯一 operation key、語意 fingerprint、前後狀態與逆轉關聯。
註冊 hard-delete 保護，不將短期安全 log 當成永久帳務歷史。

writer 必須處理 stale preview、相同重試／變更參數衝突、部分與重複轉用、
reservation／attendance 競爭、schema readiness、零來源 lifecycle、失敗原子性。
守恆測試另包含 ledger/receipt 投影與退款／作廢，不能只比較資料列未改動。

成功後 rollback 不能只 revert code。可逆只限新 B 未使用、未排課、沒有後續
轉用或帳務異動且與 audit 完全相符；用補償紀錄、保留原操作與付款、可重試。
其他狀態走具體 POP 修復，不倒搬教學歷史、不新增一般管理員萬用修帳入口。

新 schema、實際啟用與 Agent 操作 production 舊資料，分別依當時有效的既有
原生控制／確切批准判定，不把產品範圍同意延伸為任意資料操作。

## 第二批：原子 API 與帳務讀取端

新增 apply 與受限 reverse，沿用首批角色、校區、throttle 及學生→合約鎖。
`execution_available` 現在只在必要 schema 就緒且 stored counters 與 canonical
使用量一致時為 true；送出仍重新驗證 hash，不把預覽當權限或修帳授權。
Schema 不完整時 apply/reverse 回 503，預覽仍可唯讀診斷。

來源只允許可核對的原收款及本流程有成對紀錄的既有轉用；未知轉入／退款／沖銷
仍拒絕。來源範圍 operation key 與 request fingerprint 保證相同重試不重做，
changed key parameters 或 stale preview 拒絕。原因與 actor、前後快照永久留存。
逆轉以補償 Payment 保留歷史；新 B 有教學、排課、扣堂、後續帳務或 snapshot
異動即拒絕，不給一般管理員任意回復舊狀態的權限。

排課已提交而 ClassSession 尚未產生時，不能把空白投影當可用額度。
沿用既有 occurrence key 與 materialization 白名單，鎖定 dated booking，
來源有未投影預約即拒絕；新 B 有活著的排課（含週期範本）即不得逆轉。
已投影的同時段不重複扣保留額度，取消／准假沿用既有規則。

對稱方向也要核對：轉用先耗盡來源，或還原先歸零新 B 時，等待中的排課取得
mutex 後仍須重讀剩餘契約。共用的 scoped authority guard 同時保護 schedules
及 ClassSession 物化（含 stale preload），只對本流程已稽核且額度耗盡的合約
生效，不改其他 completed/cancelled 的語意；取消與同堂出勤狀態更正仍沿用原規則。

MySQL RR 的 correlated tenant 子查詢可能在等待 Student 鎖前建立舊 read view。
以 current Student mutex 與 Campus 原生 tenant filter 的 shared locking read
保留相同校區限制，避免普通讀取先於關鍵鎖；TEST 校區包含 super_admin 都仍拒絕。
並行回歸涵蓋部分收據撤銷先贏後的逆轉，不能用舊 snapshot 恢復付款數值。
財務快照本身也必須使用 current locking reads，而不是普通 fresh()/get()；
逆轉先鎖三個合約，再檢查來源／新批次所有 header/item-linked 帳單，有額外帳單即拒絕。
物化多一次必要的 fresh course PK read；monthly 測試預算只新增這項權威成本，
保留既有批次掃描及不重複物化斷言，不能將新增功能成本混稱為 Token 節省。

收據撤銷保留原來可用的剩餘資金內操作；若會抽走已轉出的資金則 409。
缺 InvoiceID 的舊收據在來源仍有未還原轉出時先拒絕，不猜付款歸屬。
Ledger 將內部額度流與現金收款、沖銷分開，原收據不因此誤顯多收。
原已確認收據／原 cash linkage 的購買堂數取自首筆永久快照，列印不隨來源減堂；
純額度轉出入及補償不是新收款，不改 last-payment date。快照包含必要收據識別及狀態。

本批未提供新操作入口；下一批在本批合併後接續介面與整體驗收。
原回報校區 9 不在本機 director 15/16 的財務唯讀範圍，目前僅有回報／附件，
實際單價、預約占用量及可轉用額仍 UNKNOWN，不宣稱已直接解決真實案例。
Founder 續示：先完成功能，真實案例稍後驗收；此未擴大校區資料讀取或轉用授權。
無 Agent production 舊資料移轉，既有 POP／部署與 Founder 閘門照舊。

## 首批驗證

本機使用專案既有 `scripts/phpunit-isolated.sh`，獨立 localhost MariaDB、隨機
測試 schema、自動清理，沒有讀 production 憑證或改共用 DB。真實 HTTP route
涵蓋同單位／小數、既有欠款不變、保留排課、未點名、Paid 雙真相、角色／校區、
不明收款、超扣與錯誤輸入。第一輪在未新增端點時 9/9 因 404 失敗；最終
GREEN 與原生 MySQL／完整 CI 證據補在 PR。這不是完整轉用功能驗收。

## 第三批：操作入口與窄歷史

課程管理的兩種列模式、課程抽屜與歷史課程選單提供「跨科轉用與紀錄」。
同學生另一科合約使用既有 fetchAllPages，最多20頁、每頁100筆，任何失敗／
不完整分頁均拒絕提供部分選項；不重掃全校區，不用姓名替代學生ID。
資格、單位、金額與權限仍由服務端決定，前端先顯示 preview，再明確確認。

GET 相同轉用資源只回當前學生／校區、且該課程是來源、範本或新批次的紀錄，
固定每頁20筆；不傳 operation key、fingerprint、帳務 snapshots 或收款人資訊。
還原用紀錄中的原來源ID，另填原因，沿用既有服務逐項重核，不承諾一定可還原。
來源歸零結案後仍能從歷史／新批次找到紀錄。

不明回覆將原body/key留在當頁記憶、關閉重開仍可重試，不容許改參數或另送；
未將原因、financial state或token存入browser storage。整頁重新整理不保留intent，
介面要求先確認結果；再次開啟先讀持久歷史，未成功讀取時不能確認新轉用。
成功後僅歷史刷新失敗不會把已提交操作誤報成失敗。真正還原保留原轉用與補償。

Founder明示先完成功能，campus9真實案例稍後驗收；沒有擴張帳務讀取／實際資料
轉用權限。合併、正式activation、runtime驗證與reporter驗收分別記錄，不以此文件
或隔離測試宣稱正式可用。
