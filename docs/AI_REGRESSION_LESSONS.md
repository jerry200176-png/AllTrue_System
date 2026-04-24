# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

> 詳細事故記錄（33 條）→ [AI_REGRESSION_LESSONS_ARCHIVE.md](AI_REGRESSION_LESSONS_ARCHIVE.md)

---

# ⛔⛔⛔ 開工前必讀摘要（30 秒讀完）⛔⛔⛔

## 紅線（⛔ 違反 = P0 故障，零容忍）

### R1. `/home/admin` 就是 production — 改檔案 = 改線上

```
/home/admin/backend/  ← nginx 直接 serve 的 document root
/home/admin/frontend/ ← npm run deploy 後 copy 到 backend/public/
```

- **feature branch 上修改既有 .php/.vue 檔案 = 即時影響 production**
- git checkout -b 不會隔離 working tree
- 唯一安全的寫入：**新增** test file（`tests/` 目錄）、新增 Export class、新增 migration
- 事故：§P0-005、§事故F

### R2. 禁止在 Pi 上跑測試（已發生 3 次 DB 清空事故）

```
❌ cd /home/admin/backend && php artisan test     ← 會 DROP production DB
❌ cd /home/admin/backend && vendor/bin/phpunit   ← 同上
❌ cd /home/admin/backend && php artisan config:clear  ← 全站 401
```

- 測試只能在 GitHub Actions CI 跑
- debug CI 失敗 → 改檔案 → push → 看 CI log，不要本機跑
- **包括「只跑單一測試檔」也禁止** — `RefreshDatabase` trait 不管你跑幾個檔案都會 DROP 全部表

### R3. CI 全綠前禁止改 production 既有檔案

```
✅ 正確順序：
   1. 新增 test file → push → CI RED
   2. 改 production code → push → CI GREEN
   3. PR merge → git checkout main → git pull
   4. 前端有改才 npm run deploy

❌ 錯誤：直接改 Controller/Route/Config 再補測試
❌ 錯誤：CI 還在跑就通知使用者
❌ 錯誤：PR 還沒 merge 就 npm run deploy
```

### R4. 還原必須完全還原

```
❌ 部分還原（「看起來有問題的先拿掉，其他留著」）→ 二次故障
✅ git checkout HEAD -- <file>  完整還原
```

### R5. 禁止 git push --force / 禁止直接 push main

- 見 `.cursor/rules/p0-never-force-push-and-deploy.mdc`
- `scripts/git-sync.sh` 已加入守門：在 main branch 執行直接 abort（2026-04-24）

### R6. deploy.yml 禁止用 `optimize:clear`，改用 `config:cache && route:cache`

```bash
# ❌ 危險：先清後不補，清除瞬間所有 API 可能 404/500
php artisan optimize:clear

# ✅ 正確：直接重建，無空白期
php artisan config:cache && php artisan route:cache
```

- 事故記錄：`optimize:clear` 包含 config + route + view cache 複合清除，清除後若有任何延遲就會導致 API 失敗（2026-04-24 修正）

### R7. Pi SSH public key 拒絕 → 根因是 home 目錄 775（group-writable）

```
症狀：Permission denied (publickey,password) — key 正確、格式正確，仍被拒
根因：/home/admin 權限 775 (admin:www-data)，SSH StrictModes 預設拒絕 group-writable home
修法：/etc/ssh/sshd_config 加入 StrictModes no（因為 www-data 需要寫入 home，無法改 755）
```

- **禁止**：看到 `Permission denied (publickey)` 就換 key — 先查 `ls -la /home/ | grep admin` 確認 home 權限
- **確認修復**：`sudo systemctl restart sshd` 後，GitHub Actions Deploy run 應無 `Permission denied`
- **Pi 環境特殊說明**：`/home/admin` 為 `admin:www-data 775`，是刻意設計讓 Apache 可寫；`StrictModes no` 是正確解法，不是暫時 workaround（2026-04-24）

### R8. deploy.yml 禁止 `composer install --no-dev`（Pi 環境）

```bash
# ❌ 造成 "Class NunoMaduro\Collision not found" → health check 500
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ✅ 正確：Pi 已有 dev 套件，--no-dev 無法乾淨移除，保留 dev 套件不影響生產
composer install --no-interaction --prefer-dist --optimize-autoloader
```

- 原因：Pi 本機 vendor 有舊 dev 安裝，`--no-dev` 雖移除 Collision 的檔案，但 `php artisan optimize` bootstrap 時仍讀到舊 `packages.php` 中的 provider 登記，導致 class not found
- 結果：health check 失敗 → 自動 rollback 觸發 → 服務短暫中斷（2026-04-24 事故）

### R10. 家長入口登入：必須同時讀 `parent_phone` 與 `Phone`

```
UI「家長手機」欄 → 儲存到 Student.parent_phone
登入驗證         → 原本只讀 Student.Phone → 永遠不符 → 401
```

- **禁止**：只用 `$s->Phone` 驗證家長身份
- **正確**：`resolveContactPhone()` 優先 `parent_phone`，空才 fallback `Phone`
- 修復：PR #38，2026-04-24

### R9. deploy.yml `git pull` 改為 `git fetch + reset --hard`

```bash
# ❌ Pi 有本地 auto-commit（nightly tag、hourly sync），造成 divergent branches，git pull 卡住
git pull origin main

# ✅ 強制對齊 origin/main，忽略 Pi 本地 commit
git fetch origin main
git reset --hard origin/main
```

- Pi 上有 `nightly-backup.sh`、hourly auto-sync 等 cron 腳本會產生本地 commit；deploy 必須用 reset --hard 確保版本一致（2026-04-24）

---

## 黃線（⚠️ 違反 = CI 反覆失敗、浪費時間）

### Y1. 寫測試前先查 NOT NULL 欄位

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='AllTrue' AND TABLE_NAME='<表名>'
  AND IS_NULLABLE='NO' AND EXTRA NOT LIKE '%auto_increment%'
  AND COLUMN_DEFAULT IS NULL;
```

- 常漏：`schedules` 的 **S.D.B.**（`student_id`, `day_of_week`, `branch_id`）
- `Campus` 有 10+ 個 NOT NULL 欄位，用 `firstOrCreate` 別 raw insert

### Y2. 測試用 `now()` + `addHours(N)` 跨午夜 → CI 22:00+ TWN 之後失敗

```php
// ❌ 危險：now() + 2h 在 22:01 TWN = 00:01 次日，EndTime "00:01" < "22:01" → session 窗口失敗
$endTime = now()->addHours(2)->format('H:i:s');

// ✅ 正確：setUp() 固定 10:00 AM，EndTime 永遠落在中午
Carbon::setTestNow(Carbon::today()->setTime(10, 0)); // in setUp()
// tearDown() 記得 Carbon::setTestNow()
```

- 同理：`start_time='23:00'` + `duration_minutes=30` → EndTime=23:30；CI 在 23:31 跑 → 計數差一

### Y3. PhpSpreadsheet sheet 名稱不能為空

- 動態 sheet name 必須 guard 空字串，fallback 到 `"Sheet"` 或 `"老師{$id}"`

### Y4. 前端改了必須 build 才生效

- `npm run deploy` 只能在 **main branch + PR merged 後** 執行

### Y5. DB::table() raw queries 不受 Eloquent global scope 保護

```php
// ❌ campus 過濾漏掉 → 跨分校資料洩漏
// ✅ 使用 resolveEffectiveCampusIds() 統一驗證，空 auth_campus_ids 非 super_admin → 403
```

---

## 模組對照索引（改特定模組前讀 Archive 對應條目）

| 模組 | 必讀條目（在 Archive） |
|------|----------|
| 堂數 / 扣堂 | §2026-04-17 繳費日期、§單堂費用固定 |
| 繳費 / 學收 | §繳費狀態 paid_at、§歷史課程漏算、§催繳名單六狀態、§幽靈課程 |
| 薪資 / 併堂 | §兼職薪資 concurrency、§同層級併堂 v1.4、§契約時長為準 |
| 代課 / 調課 | §代課Undo通知、§合併Undo還原時間、§雙層防護重複行、§atomic transaction |
| 評量 | §同天多堂課 buildEvents、§請假後不填評量 |
| 課表回報 | §2026-04-17 回報系統（14 條禁止項） |
| 排課 | §start_time 格式、§智慧排課誤標取消 |
| 出缺勤 / 分校隔離 | §SEC-001、§分校隔離後端強制 |
| 月結制 | §b3 inactive 歷史、§b4 加購分流 |
| routes/api.php | §AI 靜默回退路由（改前必讀完整檔案 + route:list） |
| 備份 / nightly | §nightly 覆蓋修正、§備份還原演練 |

---

> 新增事故：請直接寫到 [AI_REGRESSION_LESSONS_ARCHIVE.md](AI_REGRESSION_LESSONS_ARCHIVE.md)，並更新上方黃/紅線（若升級為通用規則）。
