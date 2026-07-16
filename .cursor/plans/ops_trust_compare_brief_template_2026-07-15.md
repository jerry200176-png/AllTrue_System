# E-OPS-TRUST Compare Brief（Day 7–14）

> 填寫日：________　分校：________　參與主任：________  
> 只能勾一個結論：**Keep / Fix / Kill**  
> 禁止用「CI 過／已部署／工程測過」當成功證據。

## 結論（擇一）

- [ ] **Keep**：已證明改善，維持並小幅迭代  
- [ ] **Fix**：方向正確，但閉環或 Score 有明確問題（列清楚要修什麼）  
- [ ] **Kill**：主任不使用或未改善決策效率 → 移除／降級，不繼續堆功能  

## Fact（可查 log／錄影／秒錶）

| 指標 | Day0 | Day N | 來源 |
|---|---|---|---|
| 有異常時 decision impression→click |  |  | adoption_event |
| Stranded／Critical 中位處理時間 |  |  | snapshot + 人工紀錄 |
| 首頁→找到學生中位點擊 |  |  | 驗收腳本 |
| 每日未處理 Critical 卡 |  |  | ops_trust_snapshot |
| bypass_seek / dashboard_opened |  |  | adoption_event |
| 主任驗收 ≤5 分完成？ |  |  | 面談紀錄 |

## Inference

（從 Fact 推出、但尚未再獨立驗證的判斷）

-

## Hypothesis（仍未驗證）

-

## Score 對照

- Score 回升時，stranded／dormant **count** 是否同步下降？ Fact：___  
- 是否出現「分數變綠但根因仍在」？ Fact：___  

## Next（依結論）

- Keep → 允許的小改：________  
- Fix → 唯一優先修：________  
- Kill → 移除／關入口計畫：________  
