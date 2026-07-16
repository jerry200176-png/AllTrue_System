# E-OPS-TRUST Measure → Iterate（7–14 天）

> 本輪成果不是「功能上線」，而是**可驗證的主任行為改善**。部署成功／CI 綠 ≠ 產品成功。

## 1. 最小產品遙測（已埋點）

| 事件 | 用途 | Meta（無電話／郵件） |
|---|---|---|
| `dashboard_opened` | 基準曝露 | role, page |
| `director_trust_score_shown` | Score 可信度 | score, status, critical/warning_count, decision_keys |
| `director_trust_decision_impression` | 卡片曝光 | key, severity, target, people_total |
| `director_trust_decision_click` | 決策卡點擊 | key, target, severity, from |
| `director_trust_person_click` | 名單閉環 | key, student_id, student_class_id（無姓名） |
| `director_trust_bypass_seek` | 是否仍繞去挖課 | target=course-mgmt |
| `ops_trust_snapshot`（server log） | 異常首次／消失時間 | counts + keys，無姓名 |

來源：`POST /api/v1/adoption/events` + daily channel；client/server 皆 sanitize。

## 2. Trust Score 規則（Hypothesis，待驗證）

- **Campus-scoped**：不可跨分校比分數。
- **Critical 硬門檻**：`stranded_paid` → score ≤ 45；空週課表 → ≤ 40；有 hard cap 時 status 強制 `red`。
- **Dormant = retention_hold**：只扣 soft penalty，不當系統崩潰。
- **不可稀釋**：大量綠項不能把 Critical 刷成綠。

## 3. Phase 2 最短閉環（本 PR）

- Stranded / Dormant 決策卡展開人名名單（誰／為什麼／下一步）。
- 點人名 → 課程管理並預填學生搜尋；處理後回首頁應看到卡片／分數變化（依真實資料）。
- 不做評量推播、作廢核准、更多 KPI。

## 4. Baseline 與成功門檻（皆為 Hypothesis）

部署前無法完整回推時，**以本 PR 合併後第 1 個完整日為 Day 0 baseline**。

| 指標 | Hypothesis 門檻（暫定） |
|---|---|
| 有異常時決策卡點擊率 | ≥ 40% impression→click |
| Stranded／Critical 中位處理時間 | 較 Day0 降 ≥ 30% |
| 首頁→找到具體學生點擊數 | 中位 ≤ 2（含點決策卡或人名） |
| 每日未處理 Critical 卡 | 連續 3 日不上升 |
| Score 回升 vs 異常下降 | Score↑ 時 stranded/dormant count 同步↓ |
| bypass_seek / dashboard_opened | ≤ 25%（仍直接挖課則 Fix） |

## 5. 主任驗收腳本（至少 1 位真實主任）

任務（計時）：

1. 找出今天最嚴重的問題  
2. 找到受影響學生／課程  
3. 說出下一步  
4. 完成或口述處理流程  

記錄：是否 ≤5 分／不通的文字／不知下一步／是否仍問 Founder／是否信任 Score。

## 6. Day 7–14 Compare Brief（僅三種結論）

產出一頁：`Fact / Inference / Hypothesis` → **Keep | Fix | Kill**。  
禁止用「CI 過／有部署」代替使用行為證據。

Compare 模板檔：合併後第 14 天再填，不在本 PR 假裝已驗證。
