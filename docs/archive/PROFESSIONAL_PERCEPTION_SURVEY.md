# Professional Perception Pulse Survey — Design Doc

> **狀態**：v1 design（2026-05-23，尚未實作）｜**Issue**：#490 ｜**Epic**：#469
> 本檔是 **設計與資料規範**，用於量測 #461 visual polish 是否真的提升使用者的「專業感」感受。
> 實作（後端 endpoint + 前端 UI）需另開一個 `feat/perception-pulse-v1` PRD + branch。

## 為什麼

- #461 假設「視覺一致 → 主觀專業感 ≥ 4.0/5」，但目前沒有量測管道。
- 需要 **低頻、可關閉、無 PII、分眾** 的 pulse 設計。

## 受眾分眾

| 群體 | 觸發條件 | 期望樣本 |
|------|---------|---------|
| 主任 (`director`) | 每月最後一週登入時，且本月未填 | 4 校區各 ≥ 1 |
| 老師 (`teacher`) | 每兩個月一次，登入後 4 秒提示 | 每校區 ≥ 3 |
| 家長 (`parent`) | 季度一次（4/7/10/1 月），LIFF 內入口 | 每校區 ≥ 5 |

> 三個群體分開計算 baseline，不可加總平均（混淆 visual polish 的真實效果）。

## 問卷內容

**1 主題、1 必填、1 可選**：

```
這幾週使用 AllTrue 的整體感受？
[ 1 (差) — 2 — 3 — 4 — 5 (專業/順手) ]   ← 必填
（可選）想跟我們說什麼？_________________
[ 不再提醒這項調查 ]
```

> 不問「你會推薦給朋友嗎？」（NPS）— 本系統使用者非自願選擇（補習班指定使用），NPS 設計不適用。

## 資料規格

### 新表 `perception_pulse_responses`

| 欄位 | 型別 | 說明 |
|------|------|------|
| id | bigint PK | |
| campus_id | int FK | Campus.id（必填）|
| role | enum(`director`/`teacher`/`parent`) | 必填 |
| score | tinyint 1–5 | 必填 |
| comment | text NULL | 可選；存 raw（後端不做 NLP）|
| client_locale | varchar(8) | `zh-TW` etc. |
| created_at | timestamp | |

> **PII 規則**：
> - **不存** `user_id` / `parent_session_id` / IP（匿名化）
> - 用 `(campus_id, role, week_bucket)` 去重，避免一人灌票
> - `week_bucket` = `YEARWEEK(NOW())`，存在 cache 或 redis 30 天

### API

```
POST /api/v1/perception-pulse
  body: { score: 1-5, comment?: string }
  middleware: web-auth（director/teacher）或 parent-session（parent）
  rate limit: 1 per (user, week)

GET  /api/v1/perception-pulse/summary?branch_id=&role=&range=7d|30d
  middleware: super_admin or director（自校）
  返回：avg, count, by_week, by_campus（去識別化）
```

## 前端 UX

- **Director / Teacher**：登入後 4 秒 toast bottom-right「想花 10 秒給我們打分嗎？」→ 點開 modal
- **Parent**：LIFF home page 標題下方一條 small banner「您對家長入口的整體感受？」
- **拒絕**：點「不再提醒」→ localStorage flag + 後端記 `opt_out`，當月不再出現

## 量測 → 決策

| 指標 | 目標 | 決策 |
|------|------|------|
| Director avg score | ≥ 4.0 | < 3.5 → 安排 director 訪談 |
| Teacher avg score | ≥ 3.8 | < 3.5 → 檢視 teacher home / attendance UX |
| Parent avg score | ≥ 4.0 | < 3.5 → 檢視 ParentPortal UI |

低於目標 → 套用 `docs/PRODUCT_OPS.md` T+14 retrospective。

## 風險

- **取樣偏誤**：只願意填的人偏正/偏負 → 對照 in-app bug 回報量（負相關期望）
- **PII**：comment 欄使用者可能寫具體姓名 / 班級 → director 看 summary 時 comment 截斷顯示，全文存 audit log 並 90 天刪除
- **疲勞**：頻率太高 → 一律一鍵 opt-out + 系統強制 30 天冷卻

## 不在本 PR 範疇

- 實作（後端 controller / migration / 前端 modal）
- 試點分校選擇（與 #488 feature flags 整合）
- comment 的 NLP 情緒分析（v2 才考慮）

## 後續工作

1. 等 #488 feature flags lite 設計完成，先在 1 個分校試點 30 天
2. 30 天結果回看是否擴大全分校
3. 半年後評估是否改用第三方（Pendo / Sprig）
