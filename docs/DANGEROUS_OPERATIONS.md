---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-05-24
---

# ⛔ AllTrue 系統危險操作清單

> **任何 AI 或工程師執行下列操作前，必須先閱讀此文件。**
> 違反此清單 = P0 故障，事後必須補寫事故紀錄到 `docs/AI_REGRESSION_LESSONS.md`。

---

## 一、已發生過的事故（血淚教訓）

| 日期 | 事故等級 | 指令 | 後果 |
|------|----------|------|------|
| 2026-04-21 | P0 | `git push --force origin main` | 觸發 deploy.yml，生產 `.env`/routes 被覆蓋，全站停機 15 分鐘 |
| 2026-04-22 | P0 | `php artisan config:clear`（在 Pi 上） | session/auth 配置錯亂，全站 401 錯誤 5 分鐘 |
| 2026-04-22 | P0 | `php artisan test`（在 Pi 上） | `RefreshDatabase` 清空 production DB：Student 395→1，ClassSession 5446→0 |
| 2026-04-23 | P0 | 未經 CI 直接改 `.htaccess` 並部分還原 | 前端全站語系/靜態資源錯亂，部分還原造成第二次破壞 |
| 2026-04-23 | P0 | 在 production 跑 `vendor/bin/phpunit` | 污染 cache owner，所有使用 cache 的 API 500 |

---

## 二、絕對禁止清單（沒有例外）

### 🔴 等級 A：直接毀滅系統

| 危險指令 | 風險 | 說明 |
|----------|------|------|
| `php artisan test` | ⛔ 清空 DB | Pi 上的 backend 就是 production，`RefreshDatabase` 會 DROP 所有資料表 |
| `vendor/bin/phpunit` | ⛔ 清空 DB | 同上 |
| `git push --force` | ⛔ 觸發部署 | 覆蓋歷史 + 可能觸發 deploy.yml 自動部署 |
| `git push -f` | ⛔ 觸發部署 | 同上 |
| `git push origin main` | ⛔ 繞過 PR | 直接觸發 main CI/deploy，跳過 branch protection 與 review 流程 |
| `git reset --hard <任何>` | ⛔ 不可逆 | 覆蓋工作區，無法復原 |
| `git checkout <branch> -- backend/` | ⛔ 覆蓋檔案 | 大目錄 checkout 會覆蓋生產最新版本 |

### 🔴 等級 B：可能讓網站停擺

| 危險指令 | 風險 | 說明 |
|----------|------|------|
| `php artisan config:clear` | 網站 401/500 | 清除後若未立即重建，認證失效 |
| `php artisan route:clear` | 路由消失 | 所有 API 404 |
| `php artisan optimize:clear` | 複合清除 | 包含 config + route + view |
| `php artisan cache:clear` | session 失效 | 登入的使用者全部被登出 |
| 直接覆蓋 `backend/.env` | 全站停擺 | 覆蓋可能清掉 DB 密碼、Sentry DSN 等 |

### 🔴 等級 C：資料損失風險

| 危險指令 | 風險 | 說明 |
|----------|------|------|
| `DELETE FROM <表> WHERE ...` | 資料刪除 | 沒有回收站，刪了就沒了 |
| `TRUNCATE <表>` | 整表清空 | 比 DELETE 更快、更危險 |
| `UPDATE <表> SET ...`（無 WHERE） | 全表改寫 | 最難察覺的破壞 |
| `php artisan migrate:fresh` | 整個 DB 重建 | 等同清空所有資料 |
| `php artisan db:wipe` | 整個 DB 清空 | 同上 |
| `mysqldump ... > /dev/null`（寫錯目標） | 備份消失 | 備份本身可能被覆蓋 |
| 在 production `AllTrue` 上做 restore drill | 二次資料破壞 | 還原演練只能進 drill/test DB，不能碰正式 DB |

### 🔴 等級 D：備份 / code backup 破壞

| 危險做法 | 風險 | 說明 |
|----------|------|------|
| 把 Pi working tree / local backup branch 當 code 備份來源 | 可能把舊版覆蓋成真相 | code source of truth 是 GitHub protected `main` + PR history |
| 沒確認 Google Drive offsite / manifest 就回報備份正常 | 假安全感 | 備份需有本地 dump、Drive 異地、sha256 manifest、restore drill |
| 事故恢復時部分還原檔案 | 二次事故 | 還原必須完整還原指定檔案或走 PR revert，不做手工局部拼接 |

---

## 三、高風險操作的安全做法

### 跑測試（唯一安全方式）

```bash
# 1. 複製到 /tmp（絕對不在 /home/admin/backend/ 跑）
cp -r /home/admin/backend /tmp/backend-test-$$

# 2. 改 .env 到測試 DB
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=AllTrue_test/' /tmp/backend-test-$$/.env
sed -i 's/^APP_ENV=.*/APP_ENV=testing/' /tmp/backend-test-$$/.env

# 3. 確認（必看！）
grep DB_DATABASE /tmp/backend-test-$$/.env   # 必須是 AllTrue_test
grep APP_ENV /tmp/backend-test-$$/.env        # 必須是 testing

# 4. 在 /tmp 裡跑
cd /tmp/backend-test-$$ && php artisan test

# 5. 清掉
cd / && rm -rf /tmp/backend-test-*
```

> **CI debug 優先走 GitHub Actions**：改 `ci.yml` 或測試檔 → push → 看 GitHub Actions log。不要「先本機跑通再 push」。

---

### 修改 `.env`（唯一安全方式）

```bash
# ✅ 正確：只改一個欄位
sed -i 's/^SENTRY_LARAVEL_DSN=.*/SENTRY_LARAVEL_DSN=新值/' /home/admin/backend/.env

# ✅ 修改前先備份
cp /home/admin/backend/.env /home/admin/backend/.env.bak.$(date +%s)

# ❌ 絕對不可以
echo "整個新 .env" > /home/admin/backend/.env  # 覆蓋！
cp 某個檔案 /home/admin/backend/.env             # 覆蓋！
```

---

### Push 到 GitHub（推之前必檢查）

```bash
# 1. 確認在 WSL2 本地 repo，不在 Pi production 直接改檔
pwd
git remote -v

# 2. 確認當前 branch
git branch --show-current

# 3. 必須是 feature/fix/chore branch，不可為 main
test "$(git branch --show-current)" != "main"

# 4. 確認沒有 force flag，且只推 feature branch
git push origin HEAD   # ✅ 正確
git push --force       # ❌ 禁止
git push origin main   # ❌ 禁止
```

---

### artisan cache/config/route 指令（僅限部署或事故恢復）

```bash
# ✅ 部署後重建快取（有必要才執行，執行前確認在 Pi 上）
php artisan config:cache
php artisan route:cache

# 執行後立即確認網站正常
curl -sk https://daan.lifenet.com.tw/api/v1/health
```

---

### 任何寫入生產 DB 的操作（必須先備份）

```bash
# 操作前先備份（10 秒完成，省一切麻煩）
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p'密碼' --single-transaction AllTrue \
  | gzip > /home/admin/backups/emergency/pre_op_${TS}.sql.gz

echo "備份完成：pre_op_${TS}.sql.gz"
```

### 備份健康檢查（不可只看檔案存在）

- 本地：nightly + sixhour dump 必須持續產生。
- 異地：Google Drive `AllTrue-Backups` 必須有 `db/`、`sixhour/`、`monthly/`、`manifests/`。
- 完整性：manifest 需包含檔名、大小、sha256。
- 還原驗證：restore drill 只能還原到 drill/test DB；禁止在 production `AllTrue` 做演練。
- code 備份：GitHub protected `main` + PR history 是唯一可信 code backup；Pi working tree 不是備份來源。

---

## 四、快速確認清單（任何高風險操作前）

執行以下五個問題，全部回答「是」才能繼續：

- [ ] 我知道這個指令的完整效果，沒有任何不確定？
- [ ] 如果出錯，我知道怎麼還原（有備份 / 有 rollback 步驟）？
- [ ] 我**不在** `/home/admin/backend/` 目錄下跑任何測試指令？
- [ ] 我確認**沒有** `--force` / `-f` 旗標？
- [ ] 修改 DB 前已執行 `mysqldump` 備份？
- [ ] 若牽涉備份/還原，我確認目標不是 production `AllTrue`？
- [ ] 若牽涉 code 回復，我確認 source of truth 是 GitHub protected `main`？

如果有任何一個「否」，**先停下來**，確認清楚再繼續。

---

## 五、緊急恢復速查

### 網站 401/500 → 快取損壞

```bash
cd /home/admin/backend
php artisan config:cache
php artisan route:cache
sudo service php8.2-fpm reload
curl -sk https://daan.lifenet.com.tw/api/v1/health
```

### DB 被清空 → 從備份還原

```bash
# 找最新的備份
ls -lt /home/admin/backups/sixhour/ | head -5

# 還原
zcat /home/admin/backups/sixhour/最新備份.sql.gz \
  | mysql -h 127.0.0.1 -u admin -p AllTrue

# 驗證
mysql -h 127.0.0.1 -u admin -p AllTrue \
  -e "SELECT COUNT(*) FROM Student;"
```

### git 操作出錯 → 查 reflog

```bash
git reflog | head -20    # 找到上一個正確的 commit hash
git reset --soft <hash>  # 軟還原（保留工作區變更）
```

---

> 最後更新：2026-04-27
> 相關文件：`.cursor/rules/p0-never-force-push-and-deploy.mdc`、`docs/AI_REGRESSION_LESSONS.md`
