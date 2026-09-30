# 主任＋老師「雙帳號」合併：階段 1 操作手冊（只讀）

> **只做到階段 1：只「看」不「改」。階段 2–4（建立紀錄表、正式套用、還原）還沒蓋，目前無法真的合併，也沒有 migration。**

## 白話說明

有些老師同時有「主任帳號」（type D）和「老師帳號」（type T）。創辦人決定一人只留一個身分：留**主任帳號**（S），
老師帳號（R）之後停用、不刪除；歷史紀錄不改，只把「未來的教學工作」搬給 S。階段 1 是排練，告訴你搬得動還是搬不動。

## 怎麼跑（GitHub → Actions → Staff Identity Merge (read-only, phase 1) → Run workflow）

1. **先跑 `candidates`**（其他欄位不填），下載 artifact，每行 `candidate d=<主任id> t=<老師id> confidence=... signals=...`：
   HIGH＝帳號名或 LINE 相同，或電話相同且同分校；MEDIUM＝姓名同分校，或電話同但不同分校（要人工確認）；LOW＝只有姓名相同。只顯示 id，不顯示姓名、電話、LINE。
2. **再跑 `dry_run`**，一次一個人：填 `survivor_user_id`（主任 id）、`retired_user_id`（老師 id）、`cutover_date`（YYYY-MM-DD，這天起的工作才搬）。

## 怎麼看 GO / NO-GO（最後一行 `merge-dry-run-result=`）

- **REFUSED**：輸入不對（id 相同、找不到人、S 不是主任、R 不是老師、R 已停用…），看 `refuse-check ...=FAIL`。
- **NO-GO**：能試算但目前不能合併，原因在 `nogo reason=`：`multi-role-flag-off`、`survivor-missing-director-grant`／`-teacher-grant`、`slot-overlap`（未來課撞時間）、
  `rfid-collision`（刷卡卡號衝突）、`rate-overlap`（薪資設定重疊）、`retired-has-pending-past-learning-records`。
  **階段 1 一定是 NO-GO**（`merge-journal-table-missing`、`scope-teachers-prerequisite-missing`、`history-impact-not-computed`：紀錄表、前置作業與歷史影響筆數都還沒做），這是預期的。核心表缺少時另有 `schema-missing-<表名>`。
- **GO**：全部通過；階段 1 不會出現。

其他行：`move`＝會搬給 S 的未來項目（筆數＋最多 20 個 id）；`copy`＝複製薪資設定；`decision`＝要創辦人決定；`skip ... reason=missing`＝此環境沒有該表。歷史影響筆數（keep／warn）階段 2 才會加入。

安全邊界：只有 `--candidates`／`--dry-run`（沒有 `--execute`），階段 1 沒寫過資料，不需要還原。
