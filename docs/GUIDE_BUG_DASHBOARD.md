# GUIDE — In-app Bug Dashboard KPIs

> **完成定義**：Resolved ≠ Completed ≠ Closed。見 `CHAT_BUG_SYSTEM.md` §3.7、`GUIDE_BUG_CLOSURE_GATE.md`。  
> **Closure 批次**：`GUIDE_BUG_CLOSURE_GATE.md` +（若存在）Bug Closure Queue／Policy。

---

## 固定回報分桶

| 桶 | 定義 |
|----|------|
| Open | `new` |
| In Progress | `in_progress` |
| Resolved | `resolved`（**不是完成**） |
| Waiting Verification | resolved 且待 Reporter Verify |
| Waiting Close | resolved 且可依 Closure Policy 行政結案 |
| Closed | `closed` |

### 每日 KPI（必報）

| KPI | 定義 | 為何重要 |
|-----|------|----------|
| 今日新增 | `created_at` 為今日 | 流入 |
| **今日重新開啟（Reopened）** | 今日 `resolved→in_progress` 或 `closed→in_progress` | **比 Closed Count 更重要** |
| **Reopened Rate** | 期間內 Reopened 次數 ÷ 期間內曾標 Resolved 的 bug 數（或 ÷ Closed 嘗試） | 修復品質；高 = 驗收失敗／假完成 |
| 今日真正 Closed | 今日 `→ closed` | 流出 |

### #200 基準（2026-07-16）

| 項目 | Fact |
|------|------|
| Reopened | **2 次**（13:01「沒寫學生名」；17:56「只寫 SC」） |
| 定調 | UX Discovery 完成；狀態 Resolved；**等待 Reporter Verify**；不得稱 Completed |
| Engineering | **暫停**（等 Verify） |

---

## 回報範本（每輪）

```
Open: _
In Progress: _
Resolved: _
Waiting Verification: _
Waiting Close: _
Closed: _

今日新增: _
今日重新開啟: _（列出 #）
Reopened Rate（本日／本週）: _
今日真正 Closed: _
```
