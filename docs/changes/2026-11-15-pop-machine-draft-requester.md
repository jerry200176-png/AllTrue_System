## 2026-11-15 — POP 機器草稿重試保留 requester 邊界
<!-- release-notes: silent_ship=silent-2026-11-15-pop-machine-draft-requester -->
<!-- silent-reason: 受控內部 API 的 requester 綁定修補，沒有員工介面或業務流程新增。 -->
- POP 草稿冪等鍵目前為全域唯一；機器使用相同鍵與參數重試時，回傳前也要核對原草稿屬於同一 requester。不同機器或人工 requester 的草稿回覆 409，不回傳原紀錄。
- 同 requester 仍可重送相同請求取得原草稿，沒有新增重複草稿、核准、執行或公開留言。權限、校區、參數／context 比對與既有審批保持原規則；沒有 migration 或資料修復。
- 合成 API 回歸涵蓋兩種機器用途對其他機器／人工 requester 的四種衝突，以及兩種用途正常重試；相關 44 tests／279 assertions 通過。此為 #5117 合規連接的前置修補，不能據此宣稱寫回憑證、關閉回報 follow-up 或正式生命週期已接通。
