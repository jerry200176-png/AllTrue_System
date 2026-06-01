# AllTrue 對標付費軟體 Gap-Review（2026-06-01）

> 唯讀對標報告（無程式修改）。對標 ClassDojo / Seesaw / Google Classroom / Cal.com，收斂到 4 校區補習班現實需求。
> 結論一句話：**模組功能本身對標到位甚至超越同級產品；最大風險是「課程管理↔行事曆一致性靠前端啟發式規則維持」與「回報功能缺推播觸達」**。

## 跨區排序（CEO 視角）
1. **P1｜評量回饋接推播**（區1）：功能已建好，差「家長/老師會不會看到」這一哩，CP 最高。
2. **P1｜行事曆 golden 合約測試 + TD-062 快取**（區3）：把「靠規則維持一致」升級為「靠測試/結構保證」，止住反覆 drift。
3. **P1｜建課費用即時試算**（區2）：低工，直接防 `rate_unit` 錯帳。
4. **P2**：真實回覆率/時效 KPI（TD-057）、主任可讀對帳/drift 健檢頁、後端 occurrence 權威化。
5. **P3**：System A/B 整併、死碼（TD-060）/共用包分鐘制（TD-059）、移除 Supabase fallback。

---

## 區域 1 — 課程/學習評量回報
**現狀**：System A（每堂評量回饋）已是成熟雙向訊息串，三方未讀語意細緻（§R19），per-row 權限隔離扎實，已有 analytics 端點。
**Gaps**：
1. **無任何推播**：回饋/回覆只靠 App 內紅點，無 LINE/Telegram push → 觸達率低（本區最大缺口）。
2. **KPI 失真**：`reply_rate_pct` 實為「有家長回饋比例」，非「員工真的回覆」；無平均回覆時效（TD-057 已自認）。
3. **雙系統並存**：System B 意見箱前端幾乎未接、缺 per-row 校區檢查（TD-056/057）。
4. 回饋綁死「已核准」評量，審核塞車時家長無法留言。
**建議**：P1 回饋/回覆接既有 `NotificationLineDispatcher` 推播（需節流+SEC）；P2 用 replies 算真實回覆率/時效（TD-057 Phase 2）；P3 A/B 整併。

## 區域 2 — 課程管理
**現狀**：覆蓋面廣、防呆扎實（共用包 ledger、加購批次 §R21、月結分期 §R26、AR ledger §R30/R31、請假/調課/補課/代課專屬權威路徑、代課 anchor 對齊 Google Calendar §R52）。
**Gaps**：
1. `rate_unit`（session vs hour）歷史坑靠規則文件防守，UI 無「金額即時試算」防呆。
2. 對帳修復工具是「主任看不到的後端 API」（`recompute`/`rebuild-ledger`）。
3. 共用包部分時數扣堂未對齊（TD-059）；`recalculateSessionCounters` 死碼（TD-060）。
**建議**：P1 建/改課 UI 加「費用與堂數即時試算」（純前端顯示，後端已有資料）；P2 主任可讀「帳務健檢」唯讀頁；P3 清 TD-059/060。

## 區域 3 — 課程管理 ↔ 行事曆/課表 對齊（核心）
**現狀**：`StudentClass`（契約）+ `schedules`（例外）+ `ClassSession`（實際堂次）三源，**在前端 JS 合併**，唯一合法路徑 `mergeWeekCalendarOccurrences()`（G-007/§R25b）。drift 偵測唯讀（不覆寫契約）；單堂手動改時間已標 `IsContractException`（TD-055 Done）。
**真正風險**：「真相」在前端被重算，不是後端單一來源。`calendarOccurrenceMerge.js` 累積極長 drift 防再犯（§R25/R42/R43/R44/R47/R49/R56/R57…），每條都是曾發生的「課消失/重複/掛錯老師/幽靈卡」。一致性靠前端啟發式維持，非結構性保證。效能慢（TD-062/018/058）放大「改完沒同步」錯覺。legacy Supabase fallback 仍在（§R50，已 gated）。
**建議**：
- **P1**｜三源合併建**後端 golden contract 測試**（每類動作→occurrence 期望快照，CI 把關）—— 把「靠規則」升級為「靠測試」。
- **P1**｜落地 TD-062 Phase 1（平行化 + 視窗快取，mutation 必 invalidate）。
- **P2**｜主任唯讀「課表 vs 契約 drift 檢查」清單（重用 `schedule_drift`/殘留 scheduled 偵測）。
- **P2**｜後端把 occurrence 解析收斂為單一權威服務（前端只渲染）—— 需 golden 測試先行。
- **P3**｜移除 legacy Supabase fallback。

---
> 對應技術債：TD-055 Done；TD-056/057/059/060/062 Open（見 `docs/TECH_DEBT.md`）。本報告供決策參考，非 AI 常讀路徑。
