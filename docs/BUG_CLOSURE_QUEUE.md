# Bug Closure Queue

> **權威狀態**：Production `GET /api/v1/bugs`（非本文件）。本文件為盤點快照 + 分類規則。
> **最後盤點**：2026-07-16（Production API）

---

## 完成定義（強制）

```
Resolved  ≠  Completed  ≠  Closed
```

**Completed** 必須依序全部成立：

1. Code Merge
2. Deploy
3. Production Verify
4. Reporter Verify（若需要）
5. **Closed**

任一 step 未完成 → **不得**宣稱「In-app Bug 已修好」或「已完成閉環」。

---

## Dashboard 分桶（固定回報格式）

| 桶 | 定義 | 對應 DB status / 子集 |
|----|------|------------------------|
| **Open** | 新回報，未分診 | `new` |
| **In Progress** | 工程進行中，或回報者重開 | `in_progress` |
| **Resolved** | 已標 resolved、尚未進入下述子桶計數前之總數 | `status=resolved` |
| **Waiting Verification** | resolved + 最後公開回覆要求回報者驗收（A 類） | resolved 子集 |
| **Waiting Close** | resolved + 可行政結案（D 類：已 deploy 且 stale / 回報者已口頭確認） | resolved 子集 |
| **Closed** | 真正結案 | `closed` |

**Resolved 不可視為完成。** Waiting Verification / Waiting Close 是 Resolved 的子分類，用於清理積壓。

### Owner / Observation（非 resolved 主桶）

| 類 | 定義 | 目前位置 |
|----|------|----------|
| **B — Waiting Owner Decision** | 需 Founder／主任決策或 Owner 手動 execute | 通常在 `in_progress`（例：#173） |
| **C — Waiting Production Observation** | Trust／Measure／Day0 觀察期 | resolved 或 in_progress 內個案標記 |

---

## 目前快照（2026-07-16 Production）

| 桶 | 數量 |
|----|------|
| Open | 0 |
| In Progress | **2**（#173、#200） |
| Resolved | **109** |
| Waiting Verification（A） | **23** |
| Waiting Close（D） | **86** |
| Waiting Owner Decision（B，resolved 內） | 0 |
| Waiting Production Observation（C，resolved 內） | 0 |
| Closed | 89 |

### 特別標記（不在 resolved 佇列）

| # | 狀態 | 說明 |
|---|------|------|
| **#173** | `in_progress` | Owner Execute：`173-supersede-repair.yml` 0 runs。不再投入 Engineering。 |
| **#200** | `in_progress` | 2026-07-16 17:56 回報者再次「問題仍存在」→ 自 resolved 重開。**不是 Closed，也不是 Completed。** |

---

## Resolved 109 筆 — Closure Queue 分類

### A — Waiting Verification（23）

等回報者按「確認已修好」或近期 resolved 預設待驗收。

#104, #114, #115, #116, #122, #123, #124, #126, #158, #159, #161, #162, #168, #174, #175, #180, #182, #189, #190, #191, #192, #194, #196

### B — Waiting Owner Decision（0，resolved 內）

無。#173 在 `in_progress`，不計入 resolved 109。

### C — Waiting Production Observation（0，resolved 內）

無。

### D — Waiting Close（86）

已 deploy／fix 文件化且 resolved ≥21d（或 ≥60d stale），可排程 super_admin 行政結案（reporter-verify 或結案審核）。

**最快可關閉 Top 20**（依 resolved 天數降序 = 積壓最久）：

| # | Resolved 天數 | 回報者 | 標題（截斷） |
|---|--------------|--------|-------------|
| 2 | 96d | 鄭宇志 | 調課後剩餘課堂未回沖 |
| 3 | 96d | 鄭宇志 | 課表顯示問題 |
| 4 | 96d | 鄭宇志 | 課程種類新增 |
| 5 | 96d | 鄭宇志 | 既有課程編輯加課 |
| 6 | 96d | 鄭宇志 | 加購課程無法顯示在課表上 |
| 7 | 95d | Adam | 修改開課日期後，點名系統未更新 |
| 8 | 95d | Adam | 編輯課程時，若滑鼠反白至小視窗外，會直接關閉視窗 |
| 9 | 94d | Adam | 承稍早回報(更改開課日)：課堂數無限增加 |
| 12 | 93d | Adam | 繳費狀態 |
| 13 | 93d | Adam | 調課無法選【當日】以前的日期；無法選擇代課老師 |
| 16 | 92d | 張翔 | Bug |
| 17 | 92d | Adam | 繳費狀態(新BUG) |
| 18 | 92d | Adam | 不知道該如何形容這個Bug... |
| 19 | 92d | Coco | 新加學生沒跑出來 |
| 20 | 92d | Adam | 字體大小調整 |
| 21 | 92d | Adam | 有請假和調課後，系統提示問題 |
| 22 | 92d | 鄭宇志 | 未顯示授課老師 |
| 23 | 91d | — | 同學生相近時段的調課 |
| 24 | 91d | Adam | 催繳名單 無法及時更新 |
| 25 | 91d | Adam | 新增名字搜尋 |

---

## 刷新方式

```bash
# 需 super_admin token；詳細腳本待下一批 chore PR
curl -sk 'https://daan.lifenet.com.tw/api/v1/bugs?status=resolved&per_page=100' \
  -H "Authorization: Bearer $TOKEN"
```

盤點 SOP：拉全量 resolved 詳情 → 依本文件 A/B/C/D 規則分類 → 更新本文件快照日期與計數。

---

## 相關

- 生命週期 SOP：`docs/CHAT_BUG_SYSTEM.md` §3.7
- Reporter Verify API：`POST /api/v1/bugs/{id}/reporter-verify`
