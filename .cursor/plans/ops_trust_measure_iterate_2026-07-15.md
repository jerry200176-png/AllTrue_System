# E-OPS-TRUST Measure 定義（v3 · 分母與樣本有效性）

> 權威口徑。部署成功 ≠ 產品成功。**正式觀察：2026-07-17 起第一個完整營業日。**  
> 2026-07-16 = partial-day telemetry sanity only（不得當 Day 0）。

## 觀察窗

| 區段 | 日期 | 用途 |
|---|---|---|
| Partial | 2026-07-16（deploy 08:22 之後） | 遙測 sanity only |
| Day 0 起 | **2026-07-17** 完整營業日 | 正式 baseline / calibration 起點 |
| Compare | Day0 + 7～14 完整營業日後 | Keep / Fix / Kill；樣本不足見下 |

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
| **impression** | 決策卡 **真正進入 viewport**（≥40%）；**禁止** API 回傳即算 |
| **click** | 該 decision instance **首次**有效操作（CTA 或人名列擇一，只計一次） |
| **bypass_seek** | 已看過決策卡後，**未走提供路徑**卻自行進相關頁搜尋 |
| **resolved（Critical）** | 底層異常確實消失；**不是**卡片隱藏／改名 |
| **actionable_at** | 異常首次成為可行動狀態（snapshot／底層條件成立），**不是**卡片首次曝光 |
| **handling time** | actionable_at → 底層解除 |
| **human-active time** | 另列，扣除夜間空窗 |

Telemetry 安全：不送姓名／電話／LINE／帳務明細／student_id／student_class_id；僅自有 adoption log；營運與除錯用途。

## 指標分母與樣本有效性（v3 · 強制）

### 1) 找到具體對象所需時間／點擊數

**只計有效任務**，同時滿足：
- 主任**首次**看到一張可行動 decision card（viewport impression）  
- 該卡**確實存在可追查對象**（有 people／可指名學生或課程；空名單卡不計）

分母＝上述有效任務數。刷新重看、無對象可追的卡、教練引導後的任務 → **排除**。

### 2) Critical resolved rate

\[
\frac{\text{觀察窗內「到期應處理」且底層異常已解除的 Critical instance}}{\text{觀察窗內所有「到期應處理」的 Critical instance}}
\]

- 到期應處理＝在窗內曾達 actionable、且依政策應在窗內處理完（含窗末仍未解）  
- **不得**只計被點擊或被指派者  
- resolved＝底層異常消失（例：stranded sessions＝0），非 UI 狀態改名

### 3) Critical 未處理年齡

從 **異常首次成為可行動狀態（actionable_at）** 起算 → 觀察時刻或 resolved 時刻。  
**不從**卡片首次曝光（impression）起算。

### 4) bypass_seek rate

\[
\frac{\text{看過該 decision card 後自行前往相關頁搜尋的去重 session}}{\text{看過該 decision card 的有效 session}}
\]

- 分子／分母皆對「該卡」去重 session  
- 走提供路徑（CTA／人名列）進處理頁 → **不算** bypass  
- 未看過卡就進課程管理 → **不進本率**（另記，不混入）

### 5) 是否仍需問 Founder

只計：**產品應能自主回答**，但主任因資訊不足／不可信而升級詢問的案例。

**不列為產品失敗（排除）**：財務歷史改帳、退費、跨約爭議、自動排課／通知家長等 **明確 owner-gated** 決策。

### 6) 主任信任度

必須記錄：
- 具體事實是否被接受（哪一句／哪個數字）  
- 質疑原因  
- **主任原話**  

**不得**只用滿意度分數代替。

### 7) 樣本不足與結論紀律

- 有效任務／有效 session／Critical instance 不足時 → **不得**下 Keep 或 Kill  
- 只能標 **Inconclusive**，並延長觀察至滿 **14 個完整營業日**  
- 滿 14 日仍無足夠有效任務 → 結論應偏向 **Kill 或縮小入口**，禁止無限等待／堆功能

最低有效樣本（Hypothesis，Compare 時標明是否達標）：
- 至少 1 位真實主任完成無教練驗收  
- 找到對象時間／點擊：≥ 5 件有效任務（跨卡合計可）  
- Critical resolved／年齡：窗內 ≥ 3 件到期應處理 Critical instance（若窗內 Critical＝0，該兩項標 N/A，不得用空窗冒充成功）  
- bypass_seek：看過可追查對象卡的有效 session ≥ 5  

## Dormant（F2：retention_hold）

**禁止**以 dormant count 下降作為成功條件。量測 owner／聯繫／下一步／逾期未處置／找到對象時間。合法 hold 可綠；逾期未處置扣分＝deferred Hypothesis。

## Outcome 北極星（CTR 僅診斷）

1. 找到對象時間（有效任務中位）  
2. 找到對象點擊數（有效任務中位）  
3. Critical resolved rate（到期應處理分母）  
4. Critical 未處理年齡（自 actionable_at）  
5. bypass_seek rate（看過該卡的有效 session 分母）  
6. 非 owner-gated 的 Founder 升級詢問  
7. 信任度（事實接受＋原話）  

CTR ≥40% 只診斷，不可單獨判定成功。

## 主任驗收（工程不可代簽）

不教操作；只給正常登入。必留原話（看不懂的詞／不信 Score／仍去挖課／點擊後以為會發生什麼）。

## Day 7–14 結論選項

- **Keep**：行為改善證據＋樣本達標  
- **Fix**：方向對，只修一個最高影響缺口  
- **Kill**／縮小入口：不用或未改善；或滿 14 日樣本仍不足  
- **Inconclusive**：樣本不足且尚未滿 14 日 → 延長，禁止 Keep／Kill  

禁止新增 Dashboard KPI／推播／核准／長治理文。僅允許改遙測定義錯誤、資料安全、最短閉環中斷。

## 下次回報格式（僅此）

完整觀察期間｜樣本數與分校（含有效任務／instance 數）｜Fact／Inference／Hypothesis｜主任驗收原話｜Keep／Fix／Kill／Inconclusive｜唯一下一步  
