# E-OPS-TRUST Measure 定義修正（v2 · 2026-07-16）

> 權威口徑。部署成功 ≠ 產品成功。**正式觀察：2026-07-17 起第一個完整營業日。**  
> 2026-07-16 = partial-day telemetry sanity only（不得當 Day 0）。

## 觀察窗

| 區段 | 日期 | 用途 |
|---|---|---|
| Partial | 2026-07-16（deploy 08:22 之後） | 遙測 sanity only |
| Day 0 起 | **2026-07-17** 完整營業日 | 正式 baseline / calibration 起點 |
| Compare | Day0 + 7～14 完整營業日後 | 只准 Keep / Fix / Kill |

Baseline 誠實性（二擇一，標清）：
- **A**：若能從既有 snapshot／issue timestamps 回推可信歷史 → 可標有條件 Fact  
- **B**：無法回推 → **前 3–5 個完整營業日 calibration**，再與後段比較；門檻一律 **Hypothesis**，禁止宣稱「下降 ≥30%」無證據

## 事件口徑（campus + director/session + decision instance 去重）

去重鍵：`branch_id + telem_session（tab）+ calendar day + event + decision_key`  
刷新灌高不算。

| 事件 | 定義 |
|---|---|
| `dashboard_opened` | 該日該 session 首次開啟主任首頁 |
| `director_trust_score_shown` | 該日該 session 首次成功顯示 Score（非每次 API） |
| **impression** | 決策卡 **真正進入 viewport**（IntersectionObserver ≥40%）；**禁止** API 回傳即算 |
| **click** | 該 decision instance **首次**有效操作（CTA 或人名列擇一，只計一次） |
| **bypass_seek** | 已看過決策卡後，**未走提供路徑**卻自行進相關頁（如課程管理）搜尋 |
| **resolved（Critical）** | 底層異常確實消失（stranded sessions = 0 等）；**不是**卡片隱藏／改名 |
| **handling time** | 異常首次可行動（snapshot 首次出現）→ 底層解除 |
| **human-active time** | 另列，扣除夜間空窗，避免扭曲 |

Telemetry 安全：
- 不送姓名／電話／LINE／帳務明細  
- **不送 student_id / student_class_id**（可連結識別）  
- 僅 `POST /api/v1/adoption/events` + daily log；不進第三方分析  
- 用途：授權營運與除錯；追隨 host log rotation retention  

## Dormant（F2：retention_hold）

**禁止**以 dormant count 下降作為 Score／Keep 成功條件。

應量測（人工驗收 + 後續 disposition 前為 Hypothesis）：
- 是否已有明確 owner  
- 是否完成聯繫  
- 是否記錄下一步與追蹤日期  
- 超過政策期限仍無處置的數量  
- 主任從卡片找到對象所需時間  

Score：合法 retention_hold **可綠**；僅「逾期未處置 dormant」應扣分（處置追蹤未上線前 = deferred Hypothesis，暫不因合法 dormant 自動扣分）。

## Outcome 北極星（CTR 僅診斷）

印象→點擊 ≥40% **不可**單獨判定成功。

成功看 Outcome 組合：
1. 找到具體對象所需時間  
2. 找到對象的點擊數  
3. Critical 實際處理完成率（resolved）  
4. Critical 未處理年齡  
5. bypass_seek rate  
6. 是否仍需問 Founder  
7. 是否信任卡片所述事實  

## 主任驗收（工程不可代簽）

至少 1 位真實主任。**不要教操作**；只給正常登入入口。觀察能否自行：找最嚴重問題 → 具體學生／課程 → 說明下一步 → 完成或正確描述流程。

必留原話：哪個詞看不懂／為何不信 Score／為何仍去課程管理搜／點擊後以為會發生什麼。

## Day 7–14 紀律

- **Keep**：必須有使用者行為改善證據  
- **Fix**：只選一個最高影響缺口  
- **Kill**：真降級／移除入口，禁止「再加更多卡片」挽救  

禁止新增 Dashboard KPI／推播／核准流程／治理長文。  
僅允許改：遙測定義錯誤、資料安全、最短閉環中斷。

## 下次回報格式（僅此）

完整觀察期間｜樣本數與分校｜Fact／Inference／Hypothesis｜主任驗收原話｜Keep／Fix／Kill｜唯一下一步  
