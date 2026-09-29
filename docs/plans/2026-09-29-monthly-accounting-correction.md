# 月結誤登收款／越界日期更正準備

R3/T3。核准單案已由正式 Pi-local executor 完成 execute／verify，並核對合約、帳單、收款歷史、收據、繳費單及帳務中心；本修訂將移除單案資格，原通用操作仍保持 planned，沒有 HTTP execute。以下未執行狀態屬各次修訂當時的準備紀錄。

## 修訂 v2（2026-09-29）

### 既有來源帳單明細的唯讀核對

正式 authenticated preview 發現來源父帳單已有單一明細，舊 planner 的「來源無明細」前提不成立。修正限定該明細父帳單唯一、金額等於原登錄金額，owner 為來源或 null、期間完整且落在原合約內；由 preview 導出並簽章綁定明細 ID。來源與目標各投影自己的日期、金額和 owner，execute 沿用來源明細 ID，原值保存在既有完整 audit snapshot。多項、其他 owner、金額／期間不符和 preview 後 drift 仍拒絕。修復 lifecycle、核准角色和 executor 不變；本修訂沒有正式資料更正。

### 同一期完整調課鏈

Authenticated preview 通過帳單與收款核對後，發現既有同一期調課鏈。更正核對 parent 完整存在且為 rescheduled、學生／分校／原合約一致、整條鏈均在同一核對期內；拒絕跨期、斷鏈、循環，以及來源／目標外的 parent 或 child。外部連結查詢只讀識別欄位並納入 signed graph，預覽後新增連結也須拒絕。既有按期移轉 schedules 的交易保留整條鏈 ID、內容與 original_schedule_id；rollback 恢復原 owner，verified cash correction 保留。沒有新增 executor、角色、HTTP execute 或操作啟用。

來源：本次 Founder 對話、in-app #369 及私有唯讀證據；Planner/Integration Owner：Codex，session ee4392b134a14f1a873a2be71259852c，基準 212e7637f71bbb43c2c4d3f4b995932f4b960262。沿用已批准修正目標；正式資料修復仍未批准執行。修訂原因：使用者已建立九月目標並作廢誤登收款；不新增第二份合約或重複沖銷。

既有目標含取消歷史及一筆月底待上課，保留 ID。四筆九月已上課仍屬來源，移到既有目標。InvoiceItem 可能缺 owner；只有單筆且 parent Invoice 明確時才更正 owner；未提供 item ID 時由唯讀 graph 導出，回傳的已簽章參數必須綁定該 ID。正式欄位與 ID 綁定私有清單。

## 個案目標

Founder 在 2026-09-29 本次對話確認八月實收 6,000；九月未繳。現有每堂 1,500，八月／九月各四堂，均試算 6,000。舊合約日期 07/27–09/10，唯一帳單月份為七月，登錄付款／已確認回報為 7,500，九月後兩堂越界。

預定結果：八月合約 08/01–08/31，應收／實收 6,000，舊期停用保留為已結算歷史；九月 09/01–09/30 的既有目標合約、未繳 6,000 帳單；保留月底待上課，不提前收取第五堂。原請假／取消歷史保留。confirmed 回報才新增 -7,500 沖銷；已 voided 則必須核對原付款及唯一 linked 沖銷，不再重複。新增 6,000 正確登錄，淨登錄收款 6,000。這是登錄更正，沒有退費、轉帳、九月抵扣或對外通知。

## 架構與權限邊界

- 新增與一般 monthly-contract-split 分開的 planned POP catalog operation；原一般拆分的收款不變規則保留。
- `POST student-classes/{id}/monthly-accounting-correction/preview` 沿用主任／super_admin、所屬分校及既有驗證 middleware；只返回核對摘要、參數與簽章，不返回原始帳款／回報 graph。唯讀 preview 讀取原完整來源 graph，以 Founder 證據識別、原日期／金額／付款／回報／帳單 ID、兩期日期／應收、精確目標堂次綁定簽章。
- 支援範圍先限定：獨立月結、同一學生、來源單一帳單、單一 confirmed 或 voided 回報及精確原付款／沖銷；目標單一未繳無款帳單、沒有價格變更／群組；調課鏈限同一期完整關聯。原始資料不符或期間有第三期有效堂次即拒絕。
- 使用既有定價／月結費用 service 核對兩期已上堂次與應收。缺費率、未上課、混合未核對金額、付款或日期漂移、外校、目標不符或有效時段重疊均 fail closed。
- 先投影已核對的日期／付款更正，再重用一般拆分的純預覽檢查；投影簽章不能直接授權一般拆分 execute。執行在單一交易中按 ID 排序鎖定來源與目標，重查原簽章，沖銷／重登，再重用既有移轉、鏡像關聯及扣堂重算，更正既有九月未繳 Invoice／InvoiceItem；沒有目標仍保留新建流程。
- 不捏造主任身份：POP 的 verified actor 寫入既有更正／audit 紀錄；無人員身份時 confirmed_by/voided_by 保持 null，回報註明核准證據及 POP actor。
- 原始付款、回報與收據不刪除。回復先比對完整更正後 graph，拒絕修改過的資料；回復只撤銷拆約：堂次回原合約，新期帳單作廢、新建目標保留為歷史停用；既有目標恢復原合約欄位並保留待上／取消紀錄，不停用或刪除，原日期回復。已核實的 6,000 收款更正保持，不能為了回復拆約再次登錄已知錯誤的 7,500；如需另一筆金額更正，必須另備核准清單。目錄 reversible=false 明示不能自動完整還原已確認收款，rollback_supported=true 只涵蓋拆約邊界。

## 正式執行前

本文件不等同可執行 Manifest。部署能力經核准後，以正式最新快照產生不可變清單、檢查精確堂次／付款／鏡像／調課關聯，綁定 backend SHA 與證據識別，Founder GO 後才由 POP 執行、verify、驗收八月收據與九月繳費單。沒有正式更正結果前，不宣稱學生已拆約／開單。


## 個案核對清單（不是可執行 Manifest）

範圍限定單一學生、來源課程及單筆帳單／付款／回報；正式識別、原堂次 ID 與核對證據保存在私有更正清單，不列於公開工程文件。保留原登錄的付款日期／方式；本次僅確認實收金額，沒有新付款或退款。

| 期別 | 合約日期 | 堂次 ID | 應收 | 實收／狀態 |
|---|---|---|---:|---|
| 八月 | 2026-08-01 ～ 2026-08-31 | 四堂（ID 綁定私有清單） | 6,000 | 6,000，已結算歷史 |
| 九月 | 2026-09-01 ～ 2026-09-30 | 四堂（ID 綁定私有清單） | 6,000 | 0，既有帳單保持未繳 |

原七月／八月請假與取消紀錄保留在來源歷史課程，不移轉、不收費；即使落在更正後日期之外也不刪除。八堂有效堂次與出勤／評量 ID 保留，九月四堂的合約歸屬及既有鏡像一致移轉。既有目標及帳單 ID 沿用，綁定私有不可變清單。

正式最新 graph 尚須確認沒有群組、價格調整、其他付款或重複目標合約；調課鏈須完整且同一期，沒有範圍外 parent／child。任一條件不符就停止該案，不放寬驗證、不以本清單代替正式快照簽章。

## 驗證

本地隔離資料庫驗證：更正前唯讀、原付款保留及沖銷重登、兩期應收與月份、九月實際繳費單與帳務中心待繳名單可付 6,000、八月歷史不重複催繳、出勤評量 ID、重複執行、跨分校／過期簽章、交易失敗全復原，以及後續變更阻止回復。一般拆約與月結帳單回歸一併執行。正式資料沒有變更。

### v2 驗收與恢复

驗證已 voided net=0、既有目標日期／預估應收／NULL item owner、取消重疊及待上堂次完整資料形狀。readonly 不改款；更正新增唯一正確收款，移四堂並保留原 target/invoice/session IDs。測試冪等、target 漂移、額外付款、有效重疊、audit failure atomic rollback，以及既有 target 的 contract-only rollback。待上堂次不提前收費。能力 planned；部署、不可變 repair manifest 與實際 mutation 分別需保護核准。

## 單案本人確認方案（歷史：已核准執行，資格收尾由本修訂處理）

Founder 已選擇準備僅限本案、由本人確認的方案。另設獨立 POP operation，
不修改原 planned／主任及管理員雙核對的入口。公開 policy 只容許一組不含
個資的參數／冪等鍵／同一本人識別 hash、精確核准 reference 與 UTC 期限；
eligible_cases 最多一組精確、限時資格；空白則拒絕全部請求。核准狀態仍屬 DB，合併
policy 不等於核准，也不會直接執行。catalog／policy 同步遞增版本，舊 draft
須重建，不能跳過 stale 檢查。

實際登入本人先核對簽章清單、dry-run，再以同一 verified super_admin 身分
核准；token 綁定精確部署 SHA、參數與期限。Pi-local 執行每次重查單案資格、
DB 核准人與既有資料防漂移／交易／verify。機器不能代核准，其他分校／學生
及不同資料不適用。回復仍只撤銷拆約，已核實收款不重寫；不得標成 reversible。

正式 preview 通過後，私有清單須明示八月已結算 6,000、九月四堂未繳 6,000，
沿用使用者已建九月合約和帳單，保留付款沖銷、堂次、調課及出勤評量歷史；
月底待上課不提前收费。最後一次 Founder GO 才可涵蓋本單案權限啟用及清單
執行；單案資格不代表 DB 已核准或帳務已更正；完成 audit 後移除單案資格。

## 正式排程阻塞修復

首次精確版本部署及 DB 核准通過後，正式排程仍未取件；readonly health 心跳正常，且不可寫快取路徑與 POP file-cache mutex 雜湊一致。移除該冗餘檔案鎖，保留每筆 MySQL claim lock，並以全域 MySQL lock 維持單一執行器。取件只選實際部署 SHA、未過期且具 token 的核准，其他驗證照常執行；舊 DB 核准不刪除、不延長、不借用。

指定單案的完整參數／本人／期限維持一致，只替換未執行請求的冪等鍵，重新試算及核准新版本。正式財務完成與否須以 Pi-local verify 及帳務／繳費單查詢證據判定。

## 核准單案完成與資格收尾

正式 Pi-local execute／verify 已成功；authenticated 查詢確認兩期合約、各期已上堂次與金額、原付款及原沖銷、單筆正確收款／confirmed 收據、未繳目標帳單與繳費單、帳務中心提醒、既有明細 owner 與未上課堂次均符合核准清單。月底待上課未提前計费；不新增重複合約、帳單或沖銷。

完整識別與財務稽核證據保存在私有清單，不提交公開 repo。本修訂將 `eligible_cases` 回復空白，任何新 draft／approval／execute 都必須重新準備及核准；原 DB 核准與執行紀錄不刪除、不改寫。此資格移除的合併／部署證據須與修復執行證據分開記錄；正式資格收回以部署版本及拒絕新請求的 runtime 證據判定。

## 單案關閉的 runtime 驗證（2026-09-29）

資格清單已在 main 清空，但僅 operations/policies 變更未被既有 deployability detector 視為 application runtime，因此部署工作雖成功結束、Deploy to Production 卻 skipped，不能宣稱正式資格已關閉。本修訂對合法空清單明確回傳 no-active-case 拒絕，保留 malformed／多案例拒絕與所有原限制，隨正常 backend 部署一併帶入已清空清單。回歸涵蓋空白／多案例／缺清單均無 request、approval、payment 寫入。正式 closeout 必須核對實際部署 SHA、authenticated draft 拒絕及原已更正帳務不變；沒有額外資料修復或對外通知。僅 policy 更新漏判 runtime 的通用修正另案評估，本次不改 workflow／governance classifier／保護設定。
