# 課表出入回報系統 — 資安審查紀錄

日期：2026-04-17
範圍：新增的後端 API (`ScheduleDiscrepancyController`, `ScheduleDiscrepancyNotifier`, `ArchiveScheduleDiscrepancies`)、資料表 `schedule_discrepancies`、前端 modal + 管理頁 + 儀表板卡片。

---

## 1. 威脅模型 (STRIDE) 結論

| 威脅 | 檢查項 | 控制措施 | 狀態 |
|------|--------|----------|------|
| Spoofing | 回報人偽造身份 | Bearer token + `AttachAuthUser` middleware；controller 以 `$request->attributes->get('auth_user')->id` 取得 reporter_id，不接受 body 參數 | PASS |
| Tampering | body 帶 `reporter_id=99999` 竄改 | 後端強制覆寫；Pest test 覆蓋 (`test_teacher_can_submit_discrepancy_for_known_session`) | PASS |
| Tampering | 跨校綁定 `class_session_id` | `store()` 驗證 session 所屬 Campus 等於 `branch_id`，否則 403；`test_session_cross_campus_binding_is_blocked` | PASS |
| Repudiation | 主任不承認曾確認／結案 | `acknowledged_by/_at, resolved_by/_at, withdrawn_at` 稽核欄位 | PASS |
| Information disclosure | 主任讀別校資料 | `require_campus` middleware + `resolveCampusScope()` 強制範圍；Pest `test_cross_campus_director_cannot_view_other_campus_reports` | PASS |
| Information disclosure | 老師看到他人回報內容 | `mine()` endpoint `where reporter_id = $userId`；`activeForSession` 不包含 audit 欄位（無敏感資訊） | PASS |
| Denial of Service | 大量回報灌爆同堂次 | FR-003 duplicate guard（同 session 只留一筆 active）；`per_page` 上限 100；分頁 | PARTIAL — 建議未來加 rate limit（第 10 節風險清單已記錄） |
| Elevation of privilege | 老師改狀態 | `role:director` middleware 保護 PUT 端點；controller 再次檢查角色 | PASS |
| Elevation of privilege | 老師撤銷他人回報 | `withdraw()` 檢查 `reporter_id === $userId`，否則 403；Pest `test_other_teacher_cannot_withdraw_someone_elses_report` | PASS |

---

## 2. 資料保護

- 無 PII 新增；學生姓名/科目欄位沿用既有欄位級別，未寫入真實學生 ID 以外的身分資料。
- LINE token 讀自 `Campus` 表並留在 server；日誌輸出只記錄 discrepancy_id 與 HTTP status，不包含 token 或 group id。
- 資料保留：12 個月後自動 archive；自動清理透過 `php artisan schedule-discrepancies:archive`，僅設 `archived_at`，非硬刪除，保留稽核性。
- 前端 localStorage 僅存已有 auth token，未新增敏感資料。

---

## 3. 輸入驗證

| 欄位 | 驗證規則 | 備註 |
|------|---------|------|
| `branch_id` | `required, integer` | 後端再驗證 user 是否屬於該 campus |
| `class_session_id` | `nullable, integer` | 有值時驗證歸屬；missing_session 類型必為 null |
| `discrepancy_type` | `required, in:<enum>` | 白名單防禦 |
| `subject / student_name / time_range / notes` | `string, max:*` | 字元上限 32–500 |
| `session_date` | `nullable, date` | Laravel date parser |
| `status` (PUT) | `in:acknowledged,resolved` | 白名單 |
| `resolution_note` | `max:500`；resolved 時 `mb_strlen >= 10` | FR-008 強制 |

---

## 4. 授權矩陣

| 端點 | teacher | director | super_admin |
|------|---------|----------|-------------|
| `POST /schedule-discrepancies` | ✓（自己校區） | ✓ | ✓ |
| `GET /schedule-discrepancies/my` | ✓（只限本人）| ✓（只限本人）| ✓（只限本人）|
| `GET /schedule-discrepancies/active-for-session` | ✓ | ✓ | ✓ |
| `POST /schedule-discrepancies/{id}/withdraw` | ✓（自己）| ✓（自己）| ✓（自己）|
| `GET /schedule-discrepancies` | ✗ (403) | ✓（本校）| ✓（所有）|
| `GET /schedule-discrepancies/summary` | ✗ (403) | ✓（本校）| ✓（所有）|
| `PUT /schedule-discrepancies/{id}` | ✗ (403) | ✓（本校）| ✓（所有）|

Route 層使用 `role:director,teacher` 或 `role:director`；middleware 內定義 `director ⊃ admin/super_admin`，teacher 僅含老師；實作正確。

---

## 5. 注入防護

- 所有資料庫操作使用 Eloquent / Query Builder 的參數綁定，無字串拼接 SQL。
- 唯一的 `selectRaw('status, COUNT(*) as total')` 不含用戶輸入，安全。
- 前端使用 Vue 模板語法 `{{ }}`，自動 HTML-escape；未使用 `v-html`。
- LINE push payload 由後端組字串；無跨來源執行。

---

## 6. 失效模式 (Fail-safe)

- LINE Messaging API 失敗：最多 3 次重試（指數退避），仍失敗寫 `schedule_discrepancy.line_failed` log，不中斷 API 回應。
- Campus 無 `messaging_channel_token` 或 `staff_line_group_id`：略過推播並記錄 `schedule_discrepancy.line_skip`，主任仍可透過儀表板與管理頁處理，不依賴 LINE。
- Pest `test_submit_succeeds_even_without_line_config` 驗證此路徑。

---

## 7. 已知殘餘風險

| 風險 | 建議行動 | 優先度 |
|------|---------|-------|
| 目前無 per-user rate limit，惡意內部帳號可灌大量 missing_session 回報 | 納入系統整體 rate limit roadmap；近期以 duplicate guard + 人工稽核即可 | LOW |
| LINE token 儲存於 MySQL，依賴 DB 本身存取控制 | 已與現有 `messaging_channel_token` 共用模型，無新增風險 | INFO |
| 歸檔命令需 DBA 確認 cron 排程實際運行（`schedule:run`） | 於部署 runbook 註明 | MED |

---

## 結論

✅ **Approved** — 可進入部署流程。已知殘餘風險列入 PRD 第 7 節風險清單追蹤，不阻擋上線。
