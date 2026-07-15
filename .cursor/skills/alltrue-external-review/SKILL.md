---
name: alltrue-external-review
description: >-
  AllTrue External Review Loop。每約五項有價值工作後暫停，全系統檢視並
  研究後才產出 Discussion Draft。禁止為湊數而發問；零 Draft 合法。
---

# AllTrue External Review

## 1. Purpose

找出**真正值得向外部社群請教**的高價值問題；不是增加討論量。

權威 SOP：[`docs/GUIDE_EXTERNAL_REVIEW_LOOP.md`](../../../docs/GUIDE_EXTERNAL_REVIEW_LOOP.md)

## 2. When to activate

- [`COUNTER.md`](../../../docs/reviews/external-review/COUNTER.md) 顯示自上一輪以來 valuable_work ≥ 5
- 使用者要求「外部審查 / External Review Loop」
- Bootstrap／首次落地（Round 1）

## 3. Required workflow

1. 讀 `docs/INDEX.md` → 本 GUIDE  
2. 掃系統訊號（非只看目前 branch）：
   - `docs/AI_REGRESSION_LESSONS.md` §復發家族  
   - `docs/TECH_DEBT.md` Open 項  
   - 最近 `docs/CHANGELOG.md` / in-app 復發主題  
   - 進行中 epic（如 materialization、billing dual-truth）  
3. 列候選（最多 7）→ 對每個跑研究閘門（官方／Issues／成熟產品／文章）  
4. 充分答案 → round log「已結案候選」；仍無答案 → Draft（≤3，已排序）  
5. 重置 COUNTER；寫 round log  
6. **然後繼續開發**（除非 Draft 觸發 Decision-requiring 需 CEO）

## 4. Forbidden actions

- ⛔ 為了產生 Discussion 而硬找問題  
- ⛔ 未完成研究閘門就建 Draft  
- ⛔ 一輪 > 3 Draft  
- ⛔ 用改 production 程式「代替」未解決的外部問題（應先 Draft / Decision）  
- ⛔ 把已有業界標準答案的題目發到社群（應寫入內部 ADR／TECH_DEBT）

## 5. Exit criteria

- [ ] Round log 已寫  
- [ ] COUNTER 已更新（valuable_since_last_round = 0 或註明 bootstrap）  
- [ ] Draft 數 0–3，每份含 GUIDE §4 六欄  
- [ ] 已結案候選有一句結論 + 來源  
