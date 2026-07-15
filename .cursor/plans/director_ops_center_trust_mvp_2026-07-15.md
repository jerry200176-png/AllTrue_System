# Execution Brief — E-OPS-TRUST（主任作戰中心｜Trust MVP）

**Date:** 2026-07-15 · **Branch:** `cursor/director-ops-trust-mvp-badf`  
**Founder lock:** F1=A · F2=B · F3=C · F4=B · F5=B

## 主任一天（產品決策依據｜非工程報告）

| 時段 | 工作 | 系統應主動給什麼 |
|------|------|------------------|
| 08:30 進門 | 「今天會不會出事？」 | 信任紅燈：付錢沒課、堂數對不起來、本週沒課 |
| 午前 | 催到班／處理請假結果 | 待點名、待審請假／補課（已有） |
| 午後 | 家長來電：有沒有課、剩幾堂、要不要繳 | 剩課／已付可見性、休眠提醒（F2） |
| 傍晚 | 審評量、收尾 | 待審評量（已有） |
| 不該做 | 自己挖 API／問工程師看 stranded | digest 數字上總覽 |

**一個頁面 80%：** 強化既有 `DirectorDashboard`（已是 Campus Operations Command）→ 加 **Trust 四燈 + 休眠提醒**。不新建導航迷宮。

**可自動化：** forward-gen／LR 回填／digest 計算（已有）→ 結果必須上畫面。  
**不該存在於主任日：** 無信任語意的軍階/彩蛋、自己算「剩課有沒有排上去」。  
**可簡化／後置：** CourseManagement 深潛、void 自助（F5）、催繳自動推（F4 禁）。

## 為什麼現在做（選這條而非重構脊骨）

- F1 北極星＝「課表／剩課是對的」→ 主任每天要用**看得見的 Trust KPI**，不是再修一個 god-controller。
- `BusinessDigestService` 已算 stranded／dormant／ledger 乖離——**缺的是分校授權 API + 首屏**。
- ROI：一週內可上；無 migration；無歷史改帳（F3）；無自動催繳（F4）。

## 使用者得到什麼

主任打開總覽立刻看到四顆 Trust 燈與兩個行動項：
1. **已付堂可見性**（stranded 堂數／約當 NT$）  
2. **休眠保留**（F2：人數／可回收 NT$；「要聯繫／不排課」）  
3. 行事曆覆蓋（未來 7 天堂次數）  
4. 堂數帳本一致性（Remaining 乖離筆數）  
Invoice Trust：標示「只修正未來、不改歷史帳單」（F3 政策可見）

## 成功指標（Measure）

| KPI | MVP 定義 | 綠燈 |
|-----|----------|------|
| Paid Class Visibility | campus `stranded_sessions` | → 0 為綠；>0 紅 |
| Calendar Trust | `sessions_next_7d` & 跨約重複 | 7d>0 且 duplicate=0 綠 |
| Ledger Trust | `remaining_divergent` | =0 綠 |
| Invoice Trust | 政策 badge + unpaid 僅資訊 | 不假装歷史已修 |
| 採用 | 主任當週是否打開總覽即見 trust 區塊 | 質性：不用再問工程師 stranded |

## 風險

- 全校 digest 加 `campus_id` 過濾漏 join → **測試鎖分校隔離**  
- 數字吓人但無 drill-down → MVP 導向行事曆／課程管理，Phase 2 再列名單  
- 與舊 `system/trust-summary`（改版公告向）混淆 → 新路徑 `director/operations-trust`

## MVP（本 PR）

1. `BusinessDigestService` 支援 `campusId` + `trust{}` 四 KPI  
2. `GET /api/v1/director/operations-trust?branch_id=`（director+campus）  
3. `DirectorDashboard` Trust 條 + 優先風險納入 stranded／dormant  
4. PHPUnit 隔離／結構；前端 `directorPriorityRisks` 單測  

**Out of MVP：** 歷史 Invoice 改寫、void 申請流（F5）、評量/請假推播（F4→P2）、後端 occurrence 重設計、dormant 寫入標記欄位（先用既有查詢語意提醒）。

## Phase 2 / 3

- **P2：** Trust drill-down 名單；F4 評量+請假結果推播（opt-out）；digest Telegram 早報  
- **P3：** F5 作廢申請→Founder 核准；dormant 狀態欄位＋定期提醒排程  

## Decide

**單一 Epic：`E-OPS-TRUST`**＝主任作戰中心的 Trust 首屏。  
證據足夠 → **立即 DEV**（本分支）。
