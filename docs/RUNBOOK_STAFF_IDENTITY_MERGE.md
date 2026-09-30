# 主任＋老師「雙帳號」合併：階段 1 操作手冊（只讀）

> **只做到階段 1：只「看」不「改」。階段 2–4（建立紀錄表、正式套用、還原）還沒蓋，目前無法真的合併，也沒有 migration。**

## 白話說明

有些人同時有「老師帳號」（type T）和「主任帳號」（type D）。創辦人決定一人只留一個身分：**保留老師帳號**（S，留下來），
主任帳號（R）之後停用、不刪除；老師的課、點名、學習紀錄、刷卡、薪資都不動，只是把主任帳號的「進行中事項」和主任權限交給 S。階段 1 是排練。

## 怎麼跑（GitHub → Actions → Staff Identity Merge (read-only, phase 1) → Run workflow）

1. **先跑 `candidates`**（其他欄位留空），下載 artifact，每行 `candidate teacher=<老師id> director=<主任id> confidence=... signals=...`：
   HIGH＝帳號名或 LINE 相同，或電話相同且同分校；MEDIUM＝姓名同分校，或電話同但不同分校（要人工確認）；LOW＝只有姓名相同。只顯示 id，不顯示姓名、電話、LINE。
2. **再跑 `dry_run`**，一次一個人：`survivor_user_id`＝老師 id、`retired_user_id`＝主任 id、`cutover_date`（YYYY-MM-DD）。

## 怎麼看結果（最後一行 `merge-dry-run-result=`）

- **REFUSED**：輸入不對（id 相同、找不到人、S 不是老師、R 不是主任、R 是超級管理員、帳號已停用…），看 `refuse-check ...=FAIL`。
- **NO-GO**：階段 1 **一定是 NO-GO**（`phase1-read-only`、`merge-journal-table-missing`：還沒有套用功能），這是預期的。其他原因也會列出：
  `multi-role-flag-off`、`rfid-collision`（刷卡卡號衝突）、`pending-approvals-with-survivor-as-subject`（老師本人有待核准項目，合併後可能自己核准自己）、`schema-missing-<表名>`。
- **GO**：階段 1 不會出現。

重點行：`grant ... create=director campuses=`＝要替 S 建立的主任權限分校（操作者需確認）；`union table=UserCampus`＝S 缺少的分校；
`move`＝主任帳號名下進行中的事項（筆數＋最多 20 個 id）；`keep`＝歷史核准／建立紀錄留在 R（只給筆數）；`alias retired_login=`＝舊登入名是否相同（舊名稱之後仍可登入）；
`revoke auth_tokens`、`disable user`；`skip ... reason=missing`＝此環境沒有該表。

安全邊界：只有 `--candidates`／`--dry-run`（沒有 `--execute`），階段 1 沒寫過資料，不需要還原。
