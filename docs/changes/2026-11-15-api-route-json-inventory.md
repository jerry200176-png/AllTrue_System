## 2026-11-15 — fix(ci): 用原生 JSON 讀取 API 路由清單
<!-- release-notes: silent_ship=silent-2026-11-15-api-route-json-inventory -->
<!-- silent-reason: 修正內部路由診斷格式，沒有應用介面、權限或正式資料變更。 -->
- 既有 advisory 比對器仍假設 Laravel8 文字表格，現行輸出讀到0條路由，把135個前端路徑全部當成差異；改用已有的 route:list --json，保留既有URI比對範圍與真正候選差異。
- 無效、空白或不完整 inventory 明示無法比較，不把未知當成全數缺失或驗證成功；PHP失敗保留exit status及診斷。advisory退出政策、權限與workflow均不變。
- 相同5dcc程式庫受控前後，讀取0→553條API路由，候選差異135→3，stdout12,302→397bytes；這是診斷修正與單一工具輸出縮減，不是整體會話或費用節省比例。單次延遲受cache/負載影響，不宣稱持續加速。
