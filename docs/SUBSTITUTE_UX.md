# 代課流程 UX v2 操作手冊（PRD 9c058f19）

> 適用：SmartCalendar v2 代課 Modal、老師請假批次 Modal、主任儀表板近 7 天代課卡片  
> 啟用條件：前端 `VITE_FEATURE_SUBSTITUTE_V2=true` 並重建 `npm run deploy`  
> 版本：2026-04-18

---

## 角色視角

### 主任 Director

#### 場景 A：單堂代課

1. SmartCalendar → 點擊課堂卡片 → 下方抽屜 → **「👤 換代課老師」**。
2. 彈出「選擇代課老師」卡片式 Modal：
   - 可依「授此科目 / 分校」篩選。
   - 每張老師卡顯示：頭像、姓名、分校綁定、是否授此科目、**衝堂**狀態（紅字「衝堂」代表該時段已有課、灰字「跨分校協調」代表其他分校忙碌但可調整）。
   - 選到紅色「衝堂」卡後點送出，後端會回 422 並在頁面顯示衝堂時段與分校。
3. 送出 → 右下 Toast「已指派 XXX 老師 · 5 秒內可撤銷」，Hover 可暫停倒數；點「撤銷」即回復。
4. 5 秒後 Toast 消失，家長 App 的代課通知生效（FR-010）。

#### 場景 B：老師請假批次代課

1. SmartCalendar 工具列 → **「🗓️ 老師請假」** 按鈕。
2. Modal 分兩步：
   - Step 1：選老師 + 請假區間（≤30 天、最多 50 堂）。按「預覽影響堂次」呼叫 `/teacher-leaves/preview`。
   - Step 2：左側列表顯示所有受影響堂次，右側支援：
     - **整批指派同一老師**（一鍵填入 50 堂）
     - **逐堂微調**（下拉換人、加備註）
     - 送出前會 recheck 跨分校衝堂，有衝堂的堂次以紅底顯示並阻止提交。
3. 送出 → `/teacher-leaves/batch-substitute?atomic=true`：
   - 預檢通過才進交易；中途任何一堂失敗 → 整批回滾、無部分成功狀態。
   - 成功回應顯示 summary「共 N 堂，成功 N、失敗 0、跨分校 N」。

#### 場景 C：審閱近 7 天代課

- 主任儀表板右側有「近 7 天代課記錄」卡片：
  - 顯示學生 / 科目 / 日期時段 / 正班 → 代課老師 / 跨分校標籤。
  - 空狀態顯示「近 7 天無代課紀錄」插畫。
  - 資料來自 `/substitutes/recent?branch_id=<current>`；僅顯示您**管理分校**的記錄（分校隔離）。

---

### 老師 Teacher

老師僅有**讀取**權限：
- 課表會自動反映被指派的代課堂次；
- SmartCalendar 上的代課 Modal 不對老師開啟（按鈕 `v-if="!isTeacher"`）。

---

### 家長 Parent

- 家長 App 收到 **Type=substitute** 站內通知：標題「{學生} {科目} 代課通知」；內文含日期、時段、正班老師、代課老師。
- 若主任在 5 分鐘內按「撤銷」，通知會被作廢（`ResolvedAt` 被設定、`Payload.voided=true`）。家長 App 可據此把該通知標示為「已取消」或隱藏。

---

## API 速查

| Method | Path | 角色 | 說明 |
|---|---|---|---|
| POST | `/api/v1/class-sessions/{id}/substitute` | director | 單堂代課（回應含 `undo_window_seconds`） |
| POST | `/api/v1/class-sessions/{id}/substitute/undo` | director | Undo（時間窗 = setting + 60s grace） |
| GET  | `/api/v1/teachers/{id}/availability?date=YYYY-MM-DD` | director | 老師某日跨分校忙碌時段 |
| POST | `/api/v1/teacher-leaves/preview` | director | 預覽老師請假影響堂次 |
| POST | `/api/v1/teacher-leaves/batch-substitute` | director | 批次代課（交易） |
| GET  | `/api/v1/substitutes/recent?branch_id=` | director | 近 7 天代課記錄 |
| GET  | `/api/v1/system/settings/substitute-undo` | director+ | 讀 Undo 時間窗設定 |
| PUT  | `/api/v1/system/settings/substitute-undo` | **super_admin only** | 設定 Undo 時間窗（5/10/20/30 秒） |

### Undo 時間窗設定（Gmail Undo Send 模式）

業界對齊 Gmail Undo Send：

- **允許值**：`5 / 10 / 20 / 30` 秒（僅這四檔，類 Gmail Settings → General → Undo Send）
- **預設**：5 秒
- **儲存**：`system_settings` 表，key = `substitute.undo_window_seconds`
- **Server grace**：伺服器實際允許視窗 = UI 值 + **60 秒**（容忍網路延遲、時鐘漂移、標準對齊 Stripe idempotency 策略）
- **寫入權限**：僅 `super_admin`（`User.type='S'`）
- **回應欄位**：`POST /substitute` 回應包含 `undo_window_seconds`，前端 ToastWithUndo 直接用此值驅動倒數條

調整示例：

```bash
# 查目前值
curl -H 'Authorization: Bearer <super>' \
  https://<host>/api/v1/system/settings/substitute-undo

# 改成 10 秒
curl -X PUT -H 'Authorization: Bearer <super>' \
  -H 'Content-Type: application/json' \
  -d '{"undo_window_seconds":10}' \
  https://<host>/api/v1/system/settings/substitute-undo
```

### 衝堂 HTTP code 差異
- **409**：同分校 capacity / room / teacher 衝突（既有 `ScheduleGuardService`）。
- **422 + `cross_campus: true`**：跨分校（物理不可分身）衝堂，Payload 附 `conflicts[]`，不揭露其他分校敏感欄位。

---

## 禁止回歸項（必讀）

請參閱 `docs/AI_REGRESSION_LESSONS.md` §2026-04-18「代課 Undo 必須同時 voided 家長通知」。五條禁令摘要：

1. Undo 交易必須同時刪 schedules + 回復 LR.TeacherID + void Notification。
2. 家長通知 `SourceKey='substitute:'.$classSessionId` 為冪等鍵；不得直接 `Notification::create`。
3. 跨分校衝堂檢查只攔**其他分校**，同分校走 409。
4. Availability busy_slots 欄位白名單：只 3 鍵。
5. 批次代課預設 atomic=true，整批回滾，禁止邊跑邊 commit。

---

## 部署清單

```bash
# 後端
cd /home/admin/backend
php artisan route:clear && php artisan config:clear
sudo systemctl reload php8.3-fpm   # or: php artisan opcache:reset

# 前端（啟用 flag）
cd /home/admin/frontend
# .env.production 已設 VITE_FEATURE_SUBSTITUTE_V2=true
npm run deploy
```

部署後 smoke：
- `curl -H 'Authorization: Bearer <dir>' /api/v1/substitutes/recent` 回 200
- SmartCalendar 點「👤 換代課老師」應彈出新卡片式 Modal（而非舊 `<select>`）
- 儀表板可見「近 7 天代課記錄」卡片

Rollback：`VITE_FEATURE_SUBSTITUTE_V2=false` 重新 `npm run deploy`；後端 API 不必回滾（新路由不影響舊 caller）。
