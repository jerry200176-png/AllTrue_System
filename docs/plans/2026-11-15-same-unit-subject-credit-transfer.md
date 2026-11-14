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

## 首批驗證

本機使用專案既有 `scripts/phpunit-isolated.sh`，獨立 localhost MariaDB、隨機
測試 schema、自動清理，沒有讀 production 憑證或改共用 DB。真實 HTTP route
涵蓋同單位／小數、既有欠款不變、保留排課、未點名、Paid 雙真相、角色／校區、
不明收款、超扣與錯誤輸入。第一輪在未新增端點時 9/9 因 404 失敗；最終
GREEN 與原生 MySQL／完整 CI 證據補在 PR。這不是完整轉用功能驗收。
