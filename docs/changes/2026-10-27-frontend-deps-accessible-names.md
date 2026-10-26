## 2026-10-27 — chore(deps): 更新前端依賴並校正無障礙操作名稱
<!-- release-notes: silent_ship=silent-2026-10-27-frontend-deps-accessible-names -->
<!-- silent-reason: 輔助科技使用者可感知名稱修正，尚未發布；需具體程式先行／驗證後公告時序決定，不能把此標記當作看不到差異。 -->
- 保留Supabase、Vue、axe與Playwright更新；新ARM64 optional binary未核實官方來源，暫維持原鎖定版本，不降級axe或忽略檢查。
- 學生匯入與範例按鈕名稱以畫面原文開頭；老師打卡按鈕從完整可見內容命名，裝飾圖示不朗讀。
- 原有資料、操作、鍵盤與狀態通知行為保留；舊head四個axe失敗已定位，新head完整axe／CI尚待驗證。
