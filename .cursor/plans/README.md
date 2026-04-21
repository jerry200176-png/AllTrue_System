# Plans 資料夾管理規則

## 今日快速找（依檔案修改時間）

以下為 **2026-04-18** 當天曾寫入的 active plan（共 6 個，依時間排序）。跨日請改下方 `find` 的日期，或更新本表。

| 時間 | 檔案 |
|------|------|
| 01:14 | `建立課程繳費日期失效修正_930095c1.plan.md` |
| 12:09 | `修正剩餘堂數與月學收回歸_92597b81.plan.md` |
| 12:38 | `調課代課顯示與科目數視野修正_f6e57b1f.plan.md` |
| 13:01 | `課程回報管理分校隔離與正確時間填寫_a7c91e3b.plan.md` |
| 13:36 | `代課後調課複製舊師_bug_修正_3972a088.plan.md` |
| 14:20 | `代課流程ux優化_9c058f19.plan.md` |

**任意一天**（將日期改成你要的當天）：

```bash
find /home/admin/.cursor/plans -maxdepth 1 -type f -name '*.plan.md' \
  -newermt '2026-04-18 00:00:00' ! -newermt '2026-04-19 00:00:00' \
  -printf '%TY-%Tm-%Td %TH:%TM  %f\n' | sort
```

含 `archive/` 底下子資料夾時，改用：

```bash
find /home/admin/.cursor/plans -type f -name '*.plan.md' \
  -newermt '2026-04-18 00:00:00' ! -newermt '2026-04-19 00:00:00' \
  -printf '%TY-%Tm-%Td %TH:%TM  %P\n' | sort
```

---

## 依主題分類（自動化）

分類規則寫在 `list-plans-by-topic.py`（依**檔名關鍵字**匹配，僅輔助瀏覽）。可**一鍵更新索引檔** `TOPIC-INDEX.md`（請勿手改該檔）：

```bash
# 更新根目錄 active plan 的主題索引（最常用）
python3 /home/admin/.cursor/plans/list-plans-by-topic.py --write-index -q
```

其他用法：

```bash
# 只在終端機看分類，不寫檔
python3 /home/admin/.cursor/plans/list-plans-by-topic.py

# 含 archive/ 底下全部 plan 一併分類，寫入自訂檔名
python3 /home/admin/.cursor/plans/list-plans-by-topic.py --all --write-index TOPIC-INDEX.archive.md -q

# 給程式用：輸出 JSON
python3 /home/admin/.cursor/plans/list-plans-by-topic.py --json -q
```

**定期自動更新（可選）**：在 crontab 加一行（例如每天 08:00）：

```cron
0 8 * * * python3 /home/admin/.cursor/plans/list-plans-by-topic.py --write-index -q
```

主題桶大致為：繳費／學收、調課代課與排課、出缺勤與點名、學習評量、薪資兼職、LINE 與家長、課程管理、主任分校、基礎建設、其他。若要調整規則，請編輯 `list-plans-by-topic.py` 內 `RULES`。

**說明**：自動化的是「產生索引／JSON」，**不會**自動搬移檔案到子資料夾，以免破壞 Cursor 或書籤中的路徑。若需要實體資料夾分類，可再討論 symlink 或命名規範。

---

## 結構

```
plans/
├── *.plan.md              # Active：最近 7 天內的 plan（由 Cursor Plan 模式自動生成）
├── TOPIC-INDEX.md         # 主題索引（執行 --write-index 自動生成，勿手改）
├── list-plans-by-topic.py # 依檔名關鍵字分類；可寫索引／JSON
├── README.md              # 本文件
└── archive/
    ├── 2026-02/           # 2 月歸檔（5 個）
    ├── 2026-03/           # 3 月歸檔（55 個，含大量重複草稿）
    └── 2026-04-early/     # 4 月上旬 Apr 1-9（6 個）
```

## 分級原則（業界慣例）

| 分類 | 條件 | 處置 |
|---|---|---|
| **Active** | 最近 7 天內修改 | 保留在根目錄 |
| **Archive** | 超過 7 天 | 移至 `archive/YYYY-MM/` |
| **可刪** | 同名重複草稿（不同 hash）且已有最終版 | 可手動刪除 |

## 定期維護建議

- **每月初**：將上個月的 Active 檔案移入對應 `archive/YYYY-MM/`
- **每季**：檢視 archive，確認已納入 `docs/CHANGELOG.md` 的功能對應的 plan 可刪除
- **刪除前確認**：若 plan 記錄了重要決策理由（不在 CHANGELOG），應先摘錄到 `docs/` 再刪

## 快速歸檔指令

```bash
# 將 7 天前的 plan 移到對應月份 archive（YYYY-MM 請自行調整）
find /home/admin/.cursor/plans -maxdepth 1 -name "*.plan.md" -mtime +6 \
  -exec mv {} /home/admin/.cursor/plans/archive/YYYY-MM/ \;
```
