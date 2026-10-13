## 2026-10-13 — feat: 永久回寫游標準備

<!-- release-notes: silent_ship=silent-2026-10-13-permanent-writeback-cursor -->
<!-- silent-reason: 未接線的控制流程準備，沒有啟用產品回寫或擴大權限。 -->

新增未接線的固定 GitHub state comment metadata helper，檢查 frozen main snapshot、不可變 request prefix 與 unknown outcomes；中斷後不得自動重播。啟用仍需 #3943 的獨立審核、CI 與 Founder 決定。
