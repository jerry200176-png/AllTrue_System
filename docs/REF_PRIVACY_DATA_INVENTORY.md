# PII 資料地圖與保存/刪除政策盤點（#889）

> 目的：盤點系統實際儲存哪些學生/家長/老師個資、存在哪張表、目前有沒有保存期限或刪除機制，對標 GDPR/PDPA 的 data inventory / data minimization 要求。
> 方法：唯讀盤點 `backend/database/migrations/*.php`（168 個檔案）+ 交叉比對 `app/Http/Controllers`、`app/Services`、`app/Console/Commands`、`config/filesystems.php`。**不涉及任何寫入、刪除、或帳號變更**。
> 這是程式碼可驗證的部分；供應商合約層（LINE/Sentry 等資料處理協議）不在此範圍，見 `docs/REF_VENDOR_RISK_REGISTER.md`（#892）。

## 1. 直接識別資料（學生／家長／老師／職員）

| 資料表 | PII 欄位 | 用途 | 保存/刪除機制 |
|---|---|---|---|
| `Student` | `name`、`SchoolName`、`RFID`、`LineID`、`TelegramID`/`1`/`2`、`Phone`、`parent_name`、`parent_phone`、`notes`（自由文字） | 核心學生資料 | 由 `StudentController::purgeStudentRecords()`（`app/Http/Controllers/StudentController.php:230-344`）硬刪除，經 `destroy()`/`bulkDestroy()` 觸發。**無 soft-delete 欄位、無排程自動清除、無「退學封存」流程**——只有主任明確操作才會刪除。 |
| `Teacher` | `T_Name`、`Phone`、`RFID`、`LineID`、`TelegramID`/`1`/`2` | 核心老師資料 | 未發現任何刪除/保存邏輯。 |
| `User` | `LoginName`、`Name`、`phone`、`AvatarUrl` | 登入身分（老師/職員/管理員帳號） | 未發現刪除/匿名化邏輯；`CheckInactiveUsers` 只發提醒，不停用/刪除帳號。 |

## 2. 聯絡/關係資料

| 資料表 | 欄位 | 說明 |
|---|---|---|
| `Student.parent_name` / `parent_phone` | 監護人識別資料 | 沒有獨立的緊急聯絡人表；隨學生刪除一起清除。 |
| `student_line_bindings` | `line_user_id`、`student_id`、`campus_id`、`verified_at` | 家長 LINE 帳號綁定學生，用於通知。**⚠️ 未包含在 `purgeStudentRecords()` 內——刪除學生後綁定紀錄可能孤立殘留。** |
| `parent_binding_attempts` | `phone_fingerprint`、`student_id`、`campus_id` 等 | 家長綁定嘗試的 append-only 觀察紀錄（migration 註解明確標示「非驗證真相」）。無清除機制，無限成長。 |
| `ParentSession` | `StudentID`、`TokenHash`、`ExpiresAt` | 家長入口 session token。已包含在 purge 流程內；`2026_07_24_130000` migration 也已全域過期未驗證的 session（已修復的 P0 隱私事故先例）。 |

## 3. 上傳檔案（頭像／bug 附件／聊天附件）

| 功能 | 欄位 | 儲存方式 | 公開/受保護 |
|---|---|---|---|
| `User.AvatarUrl` | 路徑字串 | `Storage::disk('public')` | ⚠️ **公開 disk，路徑對外可讀，無簽章 URL、無存取驗證** |
| `bug_report_attachments` | `stored_path`、`original_name`、`mime_type`、`size` | 檔案路徑（非 DB blob），`BugReportService.php:53` 存到 `'public'` disk | ⚠️ 同樣公開——bug 回報截圖經常直接顯示學生姓名/資料，任何人拿到路徑就能讀取 |
| `chat_messages.media_url` | 路徑字串 | `ChatService.php:408` 存到 `'public'` disk | ⚠️ 同樣公開——聊天附件可能含證件/作業等含個資內容 |
| `LearningRecord.AttachmentUrl` | 路徑字串 | 未在本輪逐一確認實際 disk 呼叫，暫列為同樣風險待驗證 | 待確認 |

**這四項全部是檔案路徑、非 DB blob，且全部沒有發現使用私有/簽章 URL disk。這是本次盤點中最具體、最值得優先處理的儲存層發現。**

## 4. 敏感操作資料

| 資料表 | 內容 | 保存機制 |
|---|---|---|
| `StudentSingIn` / `TeacherSingIn` | RFID 刷卡時間戳、`Memo` 自由文字 | 僅隨學生/老師刪除清除，無獨立保存期限 |
| `PendingSwipe` | `RFID`、`Payload` | ✅ **有保存機制**：`PrunePendingSwipes` command 清除 30 天以上舊資料——這是全庫唯一的排程式資料保存政策，可作為其他表的範本 |
| `chat_messages` | `body`、`media_url` | 全庫**唯一**有 soft-delete 的表（手動維護的 `deleted_at`，非 Eloquent `SoftDeletes`），但軟刪除後無排程硬清除 |
| `LearningRecord.Content`/`Progress`/`Comment` 等 | 老師對特定學生的自由文字備註 | 僅隨學生刪除清除 |
| `learning_record_teacher_comments`、`learning_record_feedbacks`、`parent_feedback` 等 | 綁定 `student_id` 的自由文字互動紀錄 | ⚠️ **確認缺口：這些表都不在 `purgeStudentRecords()` 範圍內**，刪除學生後這些含個資的回饋文字會殘留孤兒資料 |
| `Invoice`/`InvoiceItem`/`Payment` | `Amount`、`Note` | 已包含在刪除流程，但無獨立的財務紀錄法定保存政策 |

## 5. 與個資存取相鄰的驗證機密

| 資料表 | 欄位 | 說明 |
|---|---|---|
| `User.PSW` | 密碼雜湊 | `2026_02_14_000001_add_security_improvements.php` 曾**批次把既有明文密碼轉雜湊**——歷史上真的發生過明文密碼事故，現已修復，僅作為先例記錄 |
| `User.pin_hash` 等 | 二次 PIN 驗證（敏感頁面） | 見 #769；鎖定計數器故意存 DB 而非 cache |
| `auth_tokens` | Bearer token、`expires_at` | session/auth token store |
| `password_reset_requests` | `account_input`、`requested_ip`、`request_note` | 含原始帳號 + IP（本身即個資），無清除機制，無限成長 |
| `user_login_activities` | `ip_address`、`user_agent`、`device_label` | 登入稽核軌跡（IP/裝置指紋屬多數隱私法規定義的個資），無保存政策 |

## 跨分校資料範圍（洩漏風險）

系統**沒有** Eloquent global scope 或 DB 層級的租戶隔離，`CampusID` 範圍完全靠每個 request 手動執行：
- `AttachAuthUser` middleware 從 `UserCampus` 設定 `auth_campus_ids`。
- `RequireCampus` middleware 只擋「零分校」的使用者，**不會過濾查詢結果**。
- 個別 controller（如 `StudentController::destroy`）必須自己記得檢查 `CampusID` 是否在允許清單內——這正是 `RequireCampus` docblock 警告的「empty campus_ids = 存取全部」模式；緩解措施只處理了「空清單」這個特例，任何 list/search endpoint 只要忘記加 `CampusID` 篩選，就可能跨分校洩漏個資。
- `TeacherScopeService` 管的是科目/年級教學權限範圍，**不是**分校資料隔離機制，兩者不可混淆。
- 上述所有帶 `CampusID`/`campus_id` 的個資表（`Student`、`Teacher`、`StudentSingIn`、`TeacherSingIn`、`chat_threads`、`bug_reports`、`parent_feedback`、`student_line_bindings`、`learning_record_*`）的安全性完全取決於每個 controller 自己有沒有加篩選——建議把這些表上的 list/export endpoint 都列進一次專門的 scoping 稽核。

## 缺口總結（優先處理順序建議）

1. **上傳檔案走公開 disk**（頭像、bug 附件、聊天附件）——任何人取得路徑即可讀取，含學生個資的截圖/附件曝險，**本次盤點中最具體、最該優先修的一項**。
2. **學生刪除流程不完整**——`purgeStudentRecords()` 遺漏 `student_line_bindings`、`parent_feedback`（含回覆）、`learning_record_teacher_comments`/`learning_record_feedbacks`（含回覆），刪除學生後這些個資變孤兒資料，不算真正刪除乾淨。
3. **完全沒有保存期限政策**——全庫唯一的排程清除是 `PrunePendingSwipes`（30 天 RFID 佇列）；登入活動、密碼重設請求、家長綁定嘗試記錄、聊天訊息/附件、學習紀錄自由文字全部無限累積。
4. **沒有「退班/退學」封存流程**——目前只有「硬刪除」或「不刪除」兩種選項，沒有中間的封存/去識別化選項。
5. **跨分校範圍靠慣例而非框架保證**——每個個資 endpoint 都需要個別稽核，沒有單一可信賴的隔離機制。
6. 一個正面先例：`2026_07_24_130000_require_verified_parent_line_bindings.php` 顯示團隊已經執行過一次真正的 P0 隱私圍堵修復（讓未驗證的家長 session 全域過期）——未來的保存/補救 migration 可以參考這個做法的寫法。

---
_本文件為唯讀程式碼盤點產出，非正式法遵稽核。對應 GitHub #889。後續動作（例如把公開 disk 改成受保護 disk、補齊刪除 cascade、建立保存期限排程）屬於實際程式碼變更，需依 `docs/governance/RISK_BASED_MERGE_POLICY.md` 分類風險等級後另開 PR，不在本文件範圍內直接執行。_
