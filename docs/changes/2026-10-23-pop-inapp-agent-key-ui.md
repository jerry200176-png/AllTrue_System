## 2026-10-23 — feat(security): 超級管理員「AI 回報處理申請金鑰」設定畫面（ADR-POP-015 part 2 UI）
<!-- release-notes: staff_update=staff-2026-10-23-pop-inapp-agent-key-ui -->
- 個人資料 → 安全性新增「AI 回報處理申請金鑰」區塊（僅超級管理員，`PopInappAgentKeyPanel`），管理 `pop_inapp_agent` 金鑰（`/api/v1/admin/pop-inapp-agent-clients`）：建立時要填名稱並勾選至少一間分校（有「全選」，空清單不能送出）；金鑰只在建立後的視窗顯示一次、可複製，關閉視窗或離開畫面即從記憶體清除，不寫入 localStorage、不傳給上層元件；建立進行中不能切換分頁。列表顯示每把金鑰的分校、建立時間、到期日與狀態（有效／已過期／已撤銷）。撤銷前以確認視窗說明會一併取消它送出、已由預先核准規則通過但還沒執行的申請，完成後顯示取消筆數。
- 版本更新卡新增 `super_admin` 對象：只有超級管理員（與 `admin` 別名）看得到，主任與老師看不到；`docs/GUIDE_STAFF_UPDATES.md` 同步。
- `triageTokenService.js` 的請求與錯誤處理抽到 `adminKeyApi.js` 與新金鑰共用，分診金鑰行為不變。
