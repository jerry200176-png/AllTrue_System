## 2026-10-27 — test(e2e): 先驗證首頁事件再切換頁面
<!-- release-notes: silent_ship=silent-2026-10-27-dashboard-acceptance-readiness -->
<!-- silent-reason: 僅修正內部正式驗收的同步順序與隔離回歸測試，不改產品畫面或業務資料。 -->
- 等待既有收集器驗證過的同校區主任首頁事件，避免首頁chunk尚未載入就切走而漏掉事件。
- 原有最後事件數、payload、個資與唯讀防護、timeout和retry全部保留；未注入正式事件或降低flaky失敗門檻。
- 舊runtime驗收40378052192仍是失敗證據；這個候選不宣稱原事故唯一根因或正式驗收已通過。
