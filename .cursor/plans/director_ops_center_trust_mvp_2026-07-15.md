# Execution Brief — E-OPS-TRUST（Decision Center｜v2 after Product Validation）

**Branch:** `feat/director-ops-trust-mvp-badf` · Founder F1–F5 locked

## Product problem (one sentence)
主任每天進門不知道「今天會不會因為課表／剩課講錯，被家長打爆」，且找不到單一入口決定先做哪一件。

## Why this is Decision Center (not digest wallpaper)
- **30 秒決策**：看 Trust Score → 若有決策卡，依順序點 CTA 去課程管理／行事曆。
- 每張卡有：為什麼、下一步、Owner=主任、一鍵跳轉（MVP 不提供一鍵自動修——避免錯排課；修完紅燈自然消失即 Measure）。
- Invoice／催繳政策＝折疊，不佔首屏決策位。
- 選擇 **B：單一 Score + Drill（決策卡）**，不是五燈監控牆。

## Today First
| 永遠 | 異常才显示 | 永不當首屏主角 |
|------|------------|----------------|
| Trust Score + 今日決策卡 | 點名／催繳／補課／評量待辦 | 五燈綠牆、Invoice 政策、軍階彩蛋、匯入 CSV（仍在下方待辦，非 Trust） |

## Product KPI (Measure)
- Trust Score 中位是否週週上升
- `director_trust_decision_click` 後 24h stranded 是否下降
- 主任為「剩課／沒課」切頁次數是否下降（質性訪談 + telemetry）
- 失敗：點 CTA 後仍問工程師 / Score 沒人看 / 紅燈永久存在

## MVP / P2
- MVP：Score + 決策卡 + 跳轉（本 PR）
- P2：stranded／dormant 點名到實際名單；一鍵標記休眠；推播（F4）
- P3：作廢申請流（F5）
