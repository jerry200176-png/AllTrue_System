# PCR-1062-TRACK-A — Forward session generation (active stranded prepaid)

> **狀態**：READY — **未核准執行**（revenue-affecting production write → 需 CEO GO）
> **Epic**：#1062 · Engine：`ForwardSessionGenerator` + `sessions:generate-forward`
> **Companion**：#1152（dormant 主任決策，本 PCR 範圍**外**）

## Scope
**In**：僅「**活躍** stranded」count-mode 合約(最後一堂 ≤3 週、remaining>0、無未來堂次、**且有確認的每週 cadence**)。2026-07-10 範圍：**101 合約**（敦化 35 / 東湖 28 / 大直 14 / 石牌 10 / 大同 7 / 其餘 7）。
**Out**：~275 dormant 合約(退費/復課,主任決策 #1152);無確認 cadence 的活躍合約(command 自動 skip)。

## Safety design（為何安全)
1. **重用單一寫入權威** `ClassSessionMaterializationService::upsertSlot`——不自造 insert;idempotent on (SC,date,HH:MM);#957 唯一索引為後盾,不可能重複生成。
2. **Cadence 必須確認**（近 6 堂中 ≥2 堂同 weekday+HH:MM),否則 skip——**絕不猜時段**(生成錯時段比缺席更傷信任)。
3. **上限** min(RemainingSessions, horizon 4 週)——一次只補 4 週,可觀察後再滾動。
4. **狀態=scheduled、Note=系統向前生成 #1062**——可稽核、可一鍵回收。
5. **零停機**;生成的是未來 scheduled 堂,不動歷史、不動帳務金額。

## Preconditions
```
[ ] 本 PR merge + deploy 完成
[ ] CEO GO：GO PCR-1062-TRACK-A
[ ] mysqldump ClassSession 備份
[ ] dry-run 全域輸出存檔（courses_planned / slots_planned / skip 分佈）
```

## Execution（分校漸進,建議先單一分校觀察)
```bash
cd /home/admin/backend
# 1) 全域 dry-run 存證（唯讀）
php artisan sessions:generate-forward --horizon-weeks=4
# 2) 單校試點（建議先敦化 22：活躍最多、金額中等)
ALLOW_PROD_REPAIR=1 php artisan sessions:generate-forward --branch_id=22 --horizon-weeks=4 --execute --force
# 3) 驗證後其餘分校比照;或全域：
ALLOW_PROD_REPAIR=1 php artisan sessions:generate-forward --horizon-weeks=4 --execute --force
unset ALLOW_PROD_REPAIR
```

## Success criteria
| ID | 條件 |
|----|------|
| S1 | 生成堂次數 = dry-run slots_planned;無 skip 組被執行 |
| S2 | 每筆生成 Note=「系統向前生成 #1062」、Status=scheduled、落在正確 weekday/time |
| S3 | `bugs:verify-reproductions` 的 `stranded_prepaid_course` 下降 ≈ 已補合約數 |
| S4 | 行事曆/點名/評量正常顯示新堂;health ok |
| S5 | 重跑 idempotent(0 新增) |

## Rollback
生成堂皆帶 Note 標記 + scheduled 狀態:
```sql
-- 尚未上課的生成堂可安全刪除/取消
UPDATE ClassSession SET Status='cancelled'
WHERE Note='系統向前生成 #1062' AND Status='scheduled' AND SessionDate >= CURDATE();
```
或還原 S0 mysqldump。

## Post-GO 後續
- 首批穩定後,將 `sessions:generate-forward --execute`(限已 GO 範圍)加入 Kernel 每日排程,讓活躍預付堂**永遠向前 4 週**滾動(閉環 #1062 的「不再 stranded」)。**本 PR 不排程**,避免未 GO 前自動寫入。

## 為何 execution 需 CEO GO
生成未來堂次直接影響**行事曆對家長/老師的承諾**與後續**堂數扣費**;屬 revenue-affecting、規模化(101 合約)寫入 → 不可逆商業決策,需你單次 GO。
