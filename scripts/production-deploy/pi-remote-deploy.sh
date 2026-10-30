set -uo pipefail
# The Laravel 12 deploy (run 41066600125) stopped because a source-installed vendor package
# (vendor/nette/schema) had local changes, which non-interactive composer refuses to replace.
# vendor/ is never edited by hand, so discard such changes instead of failing the deploy.
export COMPOSER_DISCARD_CHANGES=true
cd /home/admin

PREV_COMMIT=$(git rev-parse HEAD)
MIGRATION_RAN=0 MIG_HARD=
TARGET_SHA="@@DEPLOY_TARGET_SHA@@"
RELEASE_TRAIN="@@DEPLOY_RELEASE_TRAIN@@"
ADMISSIONS_FLAG_MODE="@@DEPLOY_ADMISSIONS_FUNNEL_V1@@"
ADMISSIONS_FLAG_CHANGED=0
ADMISSIONS_FLAG_WAS_PRESENT=0
ADMISSIONS_FLAG_BACKUP=""
CALENDAR_FLAG_MODE="@@DEPLOY_COURSE_SESSION_CALENDAR_V1@@"
CALENDAR_FLAG_CHANGED=0
CALENDAR_FLAG_WAS_PRESENT=0
CALENDAR_FLAG_BACKUP=""
MANAGER_FLAG_MODE="@@DEPLOY_COURSE_MANAGER_V1@@"
MANAGER_FLAG_CHANGED=0
MANAGER_FLAG_WAS_PRESENT=0
MANAGER_FLAG_BACKUP=""
STANDING_POLICY_FLAG_MODE="@@DEPLOY_POP_INAPP_STANDING_POLICY@@"
STANDING_POLICY_FLAG_CHANGED=0
STANDING_POLICY_FLAG_WAS_PRESENT=0
STANDING_POLICY_FLAG_BACKUP=""
FLAGS_RESTORED=0

write_deployment_manifest() {
  python3 /home/admin/scripts/write-deployment-manifest.py \
    --target-sha "$1" \
    --version-file /home/admin/backend/public/version.json \
    --output /home/admin/backend/public/deployment.json
}

rollback_deploy() {
  local rollback_failed=0
  local health2

  echo "=== [7/7] ❌ Deployment failed, starting automatic rollback ==="
  echo "回滾到 $PREV_COMMIT ..."

  # Feature-flag .env lines first, independent of the code rollback: the early returns below keep them.
  restore_feature_flags || rollback_failed=1

  cd /home/admin
  if ! git reset --hard "$PREV_COMMIT"; then
    echo "⚠️ git reset 失敗"
    rollback_failed=1
    return 1
  fi

  # Restore the exact previous lock before any old-source PHP command.
  if ! (cd /home/admin/backend && composer install --no-interaction --prefer-dist --optimize-autoloader); then
    echo "❌ rollback Composer dependency restore 失敗"
    rollback_failed=1
    return 1
  fi

  if [ "${FRONTEND_CHANGED:-0}" -gt 0 ]; then
    if ! (cd /home/admin/frontend && npm run deploy); then
      echo "⚠️ 前端 rollback build 失敗"
      rollback_failed=1
    fi
  fi

  if ! write_deployment_manifest "$PREV_COMMIT"; then
    echo "⚠️ rollback deployment manifest 寫入失敗"
    rollback_failed=1
  fi

  if ! (cd /home/admin/backend && php artisan optimize); then
    echo "⚠️ rollback optimize 失敗"
    rollback_failed=1
  fi

  # Abort after target checkout (e.g. failed migration): refresh FPM like [5/7].
  (cd /home/admin/backend && php artisan opcache:reset 2>/dev/null) || echo "⚠️ rollback CLI opcache reset 失敗"
  local rb_secret rb_response
  rb_secret="$(grep '^DEPLOY_SECRET=' /home/admin/backend/.env | cut -d= -f2-)"
  for _ in $(seq 1 16); do
    if [ -z "$rb_secret" ] || ! rb_response=$(curl -skf --connect-timeout 10 --max-time 30 -X POST -H "X-Deploy-Secret: ${rb_secret}" -H "Connection: close" -H "Content-Type: application/json" https://daan.lifenet.com.tw/api/internal/opcache-reset) || ! echo "$rb_response" | grep -q '"opcache_cleared":true'; then # secret-echo-allow: header to the internal reset endpoint; never printed
      echo "❌ rollback FPM refresh failed"; rollback_failed=1; break
    fi
  done
  unset rb_secret rb_response

  echo "Rollback health check..."
  health2=$(curl -sk https://daan.lifenet.com.tw/api/v1/health \
    | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('status','?'))" \
    2>/dev/null || echo "error")
  python3 -I "${PDV:-/missing}" verify -k --expect-backend-sha "$PREV_COMMIT" $([ "${FRONTEND_CHANGED:-0}" -gt 0 ] && echo --expect-frontend-sha "$PREV_COMMIT") || rollback_failed=1
  [ -z "${SMOKE_ON_PI:-}" ] || SMOKE_EXPECTED_BACKEND_SHA="$PREV_COMMIT" SMOKE_STRICT_VERSION= bash scripts/post-merge-smoke.sh || rollback_failed=1
  if [ "$rollback_failed" -ne 0 ] || [ "$health2" != "ok" ]; then
    echo "❌ Rollback 未完成：health=$health2 rollback_failed=$rollback_failed — 需要人工介入"
    return 1
  fi
  echo "✅ Rollback 成功，系統已恢復到 $PREV_COMMIT"
}

restore_bool_feature_flag() {
  # $1 .env line prefix (KEY=), $2 changed, $3 was-present, $4 previous line, $5 success message.
  # Literal: the previous line is appended, never used in a sed pattern; then verified with grep -Fx.
  local prefix="$1" env_file=/home/admin/backend/.env ok=1
  [ "$2" -eq 1 ] || return 0
  FLAGS_RESTORED=1
  sed -i -E "/^${prefix}/d" "$env_file" || ok=0
  if [ "$3" -eq 1 ]; then
    [ "$ok" -eq 0 ] || printf '\n%s\n' "$4" >> "$env_file" || ok=0
    { grep -Fxq -- "$4" "$env_file" && [ "$(grep -c "^${prefix}" "$env_file")" -eq 1 ]; } || ok=0
  else
    ! grep -q "^${prefix}" "$env_file" || ok=0
  fi
  if [ "$ok" -eq 1 ]; then
    echo "✅ $5"
    return 0
  fi
  echo "❌ ${prefix%=} restore failed: backend .env does not hold its previous value; fix it by hand"
  return 1
}

restore_feature_flags() {
  local failed=0
  restore_bool_feature_flag ADMISSIONS_FUNNEL_V1= "${ADMISSIONS_FLAG_CHANGED:-0}" "${ADMISSIONS_FLAG_WAS_PRESENT:-0}" \
    "${ADMISSIONS_FLAG_BACKUP:-}" "admissions flag restored during rollback" || failed=1
  restore_bool_feature_flag COURSE_SESSION_CALENDAR_V1= "${CALENDAR_FLAG_CHANGED:-0}" "${CALENDAR_FLAG_WAS_PRESENT:-0}" \
    "${CALENDAR_FLAG_BACKUP:-}" "course session calendar flag restored during rollback" || failed=1
  restore_bool_feature_flag COURSE_MANAGER_V1= "${MANAGER_FLAG_CHANGED:-0}" "${MANAGER_FLAG_WAS_PRESENT:-0}" \
    "${MANAGER_FLAG_BACKUP:-}" "course manager flag restored during rollback" || failed=1
  if [ "${STANDING_POLICY_FLAG_MODE:-}" = "off" ]; then
    # Kill switch: a requested off is never undone by a failed deploy, on any path (it is kept off).
    restore_bool_feature_flag POP_INAPP_STANDING_POLICY= 1 1 "POP_INAPP_STANDING_POLICY=false" \
      "POP in-app standing policy kept off: a requested off is never rolled back" || failed=1
  else
    restore_bool_feature_flag POP_INAPP_STANDING_POLICY= "${STANDING_POLICY_FLAG_CHANGED:-0}" "${STANDING_POLICY_FLAG_WAS_PRESENT:-0}" \
      "${STANDING_POLICY_FLAG_BACKUP:-}" "POP in-app standing policy flag restored during rollback" || failed=1
  fi
  return "$failed"
}

hold_feature_flags() {
  # Migration hold (no code rollback): restore the flag lines, then rebuild the config cache as [5/7]
  # does, so the cached config matches .env again. A failed rebuild is reported as an error.
  restore_feature_flags || echo "::error::Feature-flag .env restore failed; fix it by hand."
  [ "${FLAGS_RESTORED:-0}" -eq 1 ] || return 0
  if (cd /home/admin/backend && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php && php artisan optimize); then
    echo "✅ config cache rebuilt from the restored .env (web workers follow on opcache revalidation or the FPM refresh)"
  else
    echo "::error::Config cache rebuild after the flag restore failed; the running config may still hold the requested flag values. Fix it by hand."
  fi
}

abort_deploy() {
  echo "❌ $1"
  [ -n "$MIG_HARD" ] || [ "$(python3 -I "$PDV" range-additive "$PREV_COMMIT" "$TARGET_SHA")" != 1 ] || MIGRATION_RAN=0
  [ "${MIGRATION_RAN:-0}" -eq 0 ] || { echo "::error::Migration ran or in range: no auto rollback, no migrate:rollback. Manual fix needed."; hold_feature_flags; exit 13; }
  if ! rollback_deploy; then
    echo "❌ 自動 rollback 也失敗 — deploy run 必須標紅"
    exit 11
  fi
  exit 10
}

echo "=== [0b/7] Backup storage/app/public（#1124 防呆） ==="
# backend/storage/app/public 正在從 git-tracked 轉為 untracked
# （#1124）。轉換那次 reset 會把 git 裡已刪除的 tracked 檔案從
# working tree 清掉——這一步永久保留，每次 deploy 前都先備份，
# 之後若 reset 把檔案清空，下面的還原步驟才有東西可還原。
UPLOADS_BACKUP_DIR="/home/admin/backups/uploads"
UPLOADS_TS=$(date '+%Y-%m-%d_%H%M%S')
UPLOADS_BACKUP_FILE="${UPLOADS_BACKUP_DIR}/storage-app-public-predeploy-${UPLOADS_TS}.tar.gz"
if [ -d /home/admin/backend/storage/app/public ] && [ -n "$(find /home/admin/backend/storage/app/public -type f -print -quit)" ]; then
  mkdir -p "$UPLOADS_BACKUP_DIR"
  if ! tar -czf "$UPLOADS_BACKUP_FILE" -C /home/admin/backend storage/app/public; then
    echo "❌ storage/app/public backup 失敗 — 中止部署"
    exit 1
  fi
  echo "✅ Backup: $UPLOADS_BACKUP_FILE"
else
  echo "storage/app/public 空或不存在，略過備份"
fi

echo "=== [1/7] git fetch + reset ==="
# R62 自癒：Windows 專用 SSL backend 誤入 repo config 會讓 fetch fatal，
# 過去因無 -e 被吞掉 → reset 落在 stale tracking ref → 靜默部署舊版
git config --local --unset-all http.sslbackend 2>/dev/null || true
if ! git fetch origin main; then
  echo "❌ git fetch 失敗 — 中止部署（不得以舊 code 假裝成功）"
  exit 1
fi
REMOTE_MAIN_SHA=$(git rev-parse refs/remotes/origin/main)
# RELEASE_TRAIN=true for a release train or a merge run (#3992): main may have moved past the target.
if [ "$RELEASE_TRAIN" = "true" ]; then
  if ! git merge-base --is-ancestor "$TARGET_SHA" "$REMOTE_MAIN_SHA"; then
    echo "❌ release train target 不在 origin/main 歷史中：target=$TARGET_SHA origin/main=$REMOTE_MAIN_SHA — 中止部署"
    exit 1
  fi
  # Never downgrade: what runs now must be in the approved target's history
  # (a newer auto deploy may have landed while the train approval waited).
  if ! git merge-base --is-ancestor "$PREV_COMMIT" "$TARGET_SHA"; then
    echo "❌ release train 會降版：目前 production=$PREV_COMMIT 不在 target=$TARGET_SHA 的歷史中 — 中止部署"
    exit 1
  fi
elif [ "$REMOTE_MAIN_SHA" != "$TARGET_SHA" ]; then
  echo "❌ main 在 activation 後已前進：origin/main=$REMOTE_MAIN_SHA ≠ target=$TARGET_SHA — 中止部署"
  exit 1
fi
if ! git reset --hard "$TARGET_SHA"; then
  abort_deploy "git reset 到 target SHA 失敗"
fi
if [ "$(git rev-parse HEAD)" != "$TARGET_SHA" ]; then
  echo "❌ 部署目標不符：HEAD=$(git rev-parse HEAD) ≠ CI head=$TARGET_SHA — 中止"
  abort_deploy "部署目標 SHA 驗證失敗"
fi
echo "✅ production HEAD == CI 驗證版本（$TARGET_SHA）"
PDV=$(mktemp -d)/v.py && cp scripts/post-deploy-verify.py "$PDV" || PDV=""

# Credential rotation verified the TCP principal; a Unix socket is a separate
# MySQL User@Host (user@localhost). Force TCP: set DB_HOST, clear DB_SOCKET.
if grep -qE '^[[:space:]]*DB_HOST[[:space:]]*=' /home/admin/backend/.env; then
  sed -i -E 's/^[[:space:]]*DB_HOST[[:space:]]*=.*/DB_HOST=127.0.0.1/' /home/admin/backend/.env
else
  printf '\nDB_HOST=127.0.0.1\n' >> /home/admin/backend/.env
fi
if grep -qE '^[[:space:]]*DB_SOCKET[[:space:]]*=' /home/admin/backend/.env; then
  sed -i -E 's/^[[:space:]]*DB_SOCKET[[:space:]]*=.*/DB_SOCKET=/' /home/admin/backend/.env
fi
# Laravel gives DATABASE_URL precedence over the individual DB_*
# values. Disable it here too; otherwise it can silently restore the
# Unix-socket user@localhost path after the host normalization above.
if grep -qE '^[[:space:]]*DATABASE_URL[[:space:]]*=' /home/admin/backend/.env; then
  sed -i -E 's/^[[:space:]]*DATABASE_URL[[:space:]]*=/# DATABASE_URL disabled by deploy/' /home/admin/backend/.env
fi
echo "✅ normalized production DB connection to TCP 127.0.0.1:3306 (socket and DATABASE_URL disabled)"

echo "=== [1b/7] Restore storage/app/public（若 reset 清空，#1124） ==="
if [ -f "$UPLOADS_BACKUP_FILE" ]; then
  if [ ! -d /home/admin/backend/storage/app/public ] || [ -z "$(find /home/admin/backend/storage/app/public -type f -print -quit)" ]; then
    echo "storage/app/public 已被 reset 清空，從剛才的備份還原..."
    mkdir -p /home/admin/backend/storage/app/public
    tar -xzf "$UPLOADS_BACKUP_FILE" -C /home/admin/backend
    echo "✅ 已從 $UPLOADS_BACKUP_FILE 還原"
  else
    echo "storage/app/public 仍有檔案，無需還原"
  fi
fi

echo "=== [2/7] Composer dependencies ==="
if ! (cd /home/admin/backend && composer install --no-interaction --prefer-dist --optimize-autoloader); then
  abort_deploy "Composer install 失敗"
fi
echo "✅ Composer 完成"

echo "=== [2b/7] Storage symlink + 權限 ==="
cd /home/admin/backend
php artisan storage:link --force 2>/dev/null || true
bash /home/admin/scripts/deploy-storage-perms.sh || abort_deploy "private storage perms"
echo "✅ storage:link + chmod 完成"

echo "=== [3/7] Migration 備份 + 執行 ==="
# Laravel 8 用 "| No" (Ran? 欄)；Laravel 9+ 用 "Pending"。兩者都偵測。
# D1：migrate:status 本身失敗（DB 連不上等）不得被當成「0 筆 pending」靜默略過。
if ! MIGRATE_STATUS=$(cd /home/admin/backend && php artisan migrate:status); then
  abort_deploy "php artisan migrate:status 失敗 — 無法判斷 pending migration，中止部署並回滾"
fi
if ! printf '%s\n' "$MIGRATE_STATUS" | grep -qE '\| (Yes|No)[[:space:]]*\||\[[0-9]+\][[:space:]]+Ran|Pending'; then
  abort_deploy "Migration status is empty or unrecognized; no DDL attempted"
fi
PENDING=$(printf '%s\n' "$MIGRATE_STATUS" | grep -cE "\| No|Pending" || true)
if [ "$PENDING" -gt 0 ]; then
  echo "有 $PENDING 筆 pending migration，先備份再執行..."
  if [ "$(python3 -I "$PDV" pending-in-range "$PREV_COMMIT" "$TARGET_SHA" <<<"$MIGRATE_STATUS")" != 1 ]; then
    abort_deploy "Pending migration is outside the reviewed range or cannot be parsed; no backup/DDL attempted"
  fi
  BOUNDED_4508=0
  if printf '%s\n' "$MIGRATE_STATUS" | grep -E '\| No|Pending' | grep -q '2026_10_26_000001_add_expires_at_to_personal_access_tokens'; then
    if ! (cd /home/admin/backend && EXPECTED_BACKEND_SHA="$TARGET_SHA" php /home/admin/scripts/production-deploy/migrate-4508-bounded.php --check); then
      abort_deploy "4508 fixed-scope/native-DDL profile unverified; no backup/DDL attempted"
    fi
    BOUNDED_4508=1
  fi
  TS=$(date '+%Y-%m-%d_%H%M%S')
  BACKUP_FILE="/home/admin/backups/emergency/db_pre_migration_${TS}.sql.gz"
  mkdir -p /home/admin/backups/emergency
  DB_USER="$(grep '^DB_USERNAME=' /home/admin/backend/.env | cut -d= -f2-)"
  if ! mysqldump -h 127.0.0.1 -u "$DB_USER" -p"$(grep '^DB_PASSWORD=' /home/admin/backend/.env | cut -d= -f2-)" \
    --single-transaction --comments --dump-date AllTrue | gzip > "$BACKUP_FILE"; then
    abort_deploy "migration 前 DB backup 失敗"
  fi
  bash /home/admin/scripts/verify-db-backup.sh "$BACKUP_FILE" || abort_deploy "DB backup verification failed"
  echo "✅ 備份完成：$BACKUP_FILE"
  # R67 + D1（Founder 2026-10-13）：migration 失敗絕不可被吞，且必須立即中止部署。
  # abort_deploy 走既有 rollback_deploy：還原 PREV_COMMIT 原始碼與 Composer 依賴，
  # 不再執行前端 build／opcache reset／health／smoke；run 以 exit 1 標紅，
  # 後續「deployed / production-verified」步驟不會執行。
  # MIGRATION_RAN 維持 0：不呼叫 migrate:rollback（--step=1 可能退掉上一次部署的
  # migration）；同批已成功的 migration 依 expand/contract 規則對舊 code 相容，
  # 上方 $BACKUP_FILE 為人工復原依據（restore 屬 Founder-only）。
  if [ "$BOUNDED_4508" -eq 1 ]; then
    # Native MDL=5s, statement abort request=120s, no COPY fallback/reconnect.
    # Outer cap does NOT prove server cleanup; any failure holds without rollback/retry.
    if (cd /home/admin/backend && EXPECTED_BACKEND_SHA="$TARGET_SHA" timeout --signal=TERM --kill-after=10s 300s php /home/admin/scripts/production-deploy/migrate-4508-bounded.php); then
      MIGRATION_RAN=1
    else
      MIGRATION_RAN=1 MIG_HARD=hard
      abort_deploy "4508 bounded migration incomplete; server/schema outcome must be reconciled before retry"
    fi
  elif (cd /home/admin/backend && php artisan migrate --force); then
    MIGRATION_RAN=1
    echo "✅ Migration 完成"
  else
    abort_deploy "Migration 失敗（php artisan migrate exit != 0）— 中止部署並回滾 code；DB 備份：$BACKUP_FILE"
  fi
else
  echo "✅ 無 pending migration，略過"
fi

echo "=== [3b/7] 注入 Sentry DSN ==="
# Past [3/7] a range with a migration (or unknown) holds instead of rolling back.
git diff --quiet "$PREV_COMMIT" "$TARGET_SHA" -- ':(top)backend/database/migrations' || MIGRATION_RAN=1
# 後端 .env：寫入或更新 SENTRY_LARAVEL_DSN
if ! grep -q "^SENTRY_LARAVEL_DSN=" /home/admin/backend/.env; then
  echo "SENTRY_LARAVEL_DSN=@@DEPLOY_SENTRY_DSN@@" >> /home/admin/backend/.env
  echo "✅ SENTRY_LARAVEL_DSN 已寫入 .env"
else
  sed -i "s|^SENTRY_LARAVEL_DSN=.*|SENTRY_LARAVEL_DSN=@@DEPLOY_SENTRY_DSN@@|" /home/admin/backend/.env
  echo "✅ SENTRY_LARAVEL_DSN 已更新"
fi

echo "=== [3c/7] 一次性 APP_KEY containment（issue #1007） ==="
if ! bash /home/admin/scripts/production-operations/rotate-app-key-2026-07-19.sh; then
  abort_deploy "APP_KEY containment 失敗 — 不得進入快取重建"
fi

# 前端：Vite 只從 .env 檔讀取，寫入暫時的 .env.production.local 再 build
if ! echo "VITE_SENTRY_DSN=@@DEPLOY_SENTRY_DSN@@" > /home/admin/frontend/.env.production.local; then
  abort_deploy "frontend temporary environment 寫入失敗"
fi
apply_bool_feature_flag() {
  # $1 mode var, $2 backend key, $3 vite key ("" = backend only), $4 changed-var name, $5 present-var, $6 backup-var
  local mode="$1" key="$2" vite_key="$3"
  local value=false changed=0 present=0 backup=""
  if [ "$mode" = "on" ] || [ "$mode" = "off" ]; then
    value=$([ "$mode" = "on" ] && echo true || echo false)
    if grep -qE "^${key}=" /home/admin/backend/.env; then
      present=1
      backup=$(grep -E "^${key}=" /home/admin/backend/.env | tail -n 1)
      sed -i -E "s/^${key}=.*/${key}=${value}/" /home/admin/backend/.env
    else
      printf '\n%s=%s\n' "$key" "$value" >> /home/admin/backend/.env
    fi
    changed=1
  else
    if grep -qE "^${key}=" /home/admin/backend/.env; then
      value=$(grep -E "^${key}=" /home/admin/backend/.env | tail -n 1 | cut -d= -f2- | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')
    fi
    case "$value" in true|false) ;; *) value=false ;; esac
  fi
  [ -z "$vite_key" ] || printf '%s=%s\n' "$vite_key" "$value" >> /home/admin/frontend/.env.production.local
  echo "✅ ${key} prepared (mode=${mode:-unchanged}, value=$value)"
  eval "$4=$changed"
  eval "$5=$present"
  eval "$6=\"\$backup\""
}
apply_bool_feature_flag "$ADMISSIONS_FLAG_MODE" ADMISSIONS_FUNNEL_V1 VITE_ADMISSIONS_FUNNEL_V1 ADMISSIONS_FLAG_CHANGED ADMISSIONS_FLAG_WAS_PRESENT ADMISSIONS_FLAG_BACKUP
apply_bool_feature_flag "$CALENDAR_FLAG_MODE" COURSE_SESSION_CALENDAR_V1 VITE_COURSE_SESSION_CALENDAR_V1 CALENDAR_FLAG_CHANGED CALENDAR_FLAG_WAS_PRESENT CALENDAR_FLAG_BACKUP
apply_bool_feature_flag "$MANAGER_FLAG_MODE" COURSE_MANAGER_V1 VITE_COURSE_MANAGER_V1 MANAGER_FLAG_CHANGED MANAGER_FLAG_WAS_PRESENT MANAGER_FLAG_BACKUP
# Backend only (config/pop_inapp.php); [5/7] php artisan optimize caches it. No frontend rebuild.
apply_bool_feature_flag "$STANDING_POLICY_FLAG_MODE" POP_INAPP_STANDING_POLICY "" STANDING_POLICY_FLAG_CHANGED STANDING_POLICY_FLAG_WAS_PRESENT STANDING_POLICY_FLAG_BACKUP
echo "✅ frontend .env.production.local 已寫入"

echo "=== [4/7] 前端變動偵測 ==="
cd /home/admin
FRONTEND_CHANGED=$(git diff --name-only "$PREV_COMMIT" HEAD -- frontend/ | wc -l)
# 若 index.html 不存在（git rm --cached 後首次 deploy），強制 build
ASSETS_MISSING=0
[ ! -f /home/admin/backend/public/index.html ] && ASSETS_MISSING=1
if [ "$FRONTEND_CHANGED" -gt 0 ] || [ "$ASSETS_MISSING" -eq 1 ] || [ "$ADMISSIONS_FLAG_CHANGED" -eq 1 ] || [ "$CALENDAR_FLAG_CHANGED" -eq 1 ] || [ "$MANAGER_FLAG_CHANGED" -eq 1 ]; then
  echo "前端有 $FRONTEND_CHANGED 個檔案變動 / assets 不存在（${ASSETS_MISSING}）或招生 flag 變更（${ADMISSIONS_FLAG_CHANGED}）或行事曆 flag 變更（${CALENDAR_FLAG_CHANGED}）或課程管理 flag 變更（${MANAGER_FLAG_CHANGED}），執行 build + deploy..."
  if ! (cd /home/admin/frontend && npm install --prefer-offline && npm run deploy); then
    rm -f /home/admin/frontend/.env.production.local || true
    abort_deploy "frontend build/deploy 失敗"
  fi
  # build 完後刪除含 DSN 的暫時 env 檔
  if ! rm -f /home/admin/frontend/.env.production.local; then
    abort_deploy "frontend temporary environment 清理失敗"
  fi
  # version.json 由 Vite build 產生（vite.config.js），只在前端有重 build 時更新
  # → backend-only deploy 不更新時間戳，使用者不會看到多餘的「請重新整理」通知
  echo "✅ version.json 已由 Vite build 更新"
else
  echo "✅ 前端無變動且 assets 存在，跳過 build（version.json 不更新）"
  if ! rm -f /home/admin/frontend/.env.production.local; then
    abort_deploy "frontend temporary environment 清理失敗"
  fi
fi

# Keep runtime identity separate from the frontend build artifact.
# version.json may intentionally remain old during backend-only deploys;
# deployment.json always records the backend commit actually activated.
if ! write_deployment_manifest "$TARGET_SHA"; then
  abort_deploy "deployment manifest 寫入失敗"
fi

echo "=== [5/7] 重建 Laravel 快取 + 重載 PHP-FPM ==="
# ⛔ 禁止用 optimize:clear / config:clear（事故 B 教訓：清除會造成短暫 auth 錯亂）
# ✅ 只清 composer package discovery 快取（--no-dev 後必須，否則找不到已移除的 dev 套件）
# ✅ optimize 直接重建快取（config:cache + route:cache + event:cache），不留空窗期
cd /home/admin/backend
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
if ! php artisan optimize; then
  abort_deploy "Laravel optimize 失敗"
fi
# Read-only probe using the same Laravel config that the web process
# will load. Keep the authentication error visible without ever
# printing DB_PASSWORD; this distinguishes a remaining credential
# mismatch from an FPM worker/cache problem.
if DB_PROBE_OUTPUT=$(php artisan tinker --execute='
try {
    $db = (array) config("database.connections.mysql");
    $host = (string) ($db["host"] ?? "");
    $socket = (string) ($db["unix_socket"] ?? "");
    $port = (string) ($db["port"] ?? "");
    echo "effective_db_host={$host} effective_db_port={$port} socket_set=" . ($socket !== "" ? "yes" : "no") . PHP_EOL;
    \Illuminate\Support\Facades\DB::select("select 1");
    echo "DB OK";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}' 2>&1); then
  echo "✅ Laravel DB probe passed"
else
  echo "❌ Laravel DB probe failed (password redacted): $DB_PROBE_OUTPUT"
  abort_deploy "Laravel DB probe 失敗"
fi
# An explicit POP standing-policy mode must be what the cached config now holds (e.g. an earlier
# "POP_INAPP_STANDING_POLICY = ..." line that the ^KEY= rewrite missed would win in dotenv).
if [ "$STANDING_POLICY_FLAG_MODE" = "on" ] || [ "$STANDING_POLICY_FLAG_MODE" = "off" ]; then
  STANDING_POLICY_EXPECTED=$([ "$STANDING_POLICY_FLAG_MODE" = "on" ] && echo true || echo false)
  STANDING_POLICY_EFFECTIVE=$(php artisan tinker --execute='echo var_export(config("pop_inapp.standing_policy_enabled"), true);' 2>/dev/null | tail -n 1)
  [ "$STANDING_POLICY_EFFECTIVE" = "$STANDING_POLICY_EXPECTED" ] \
    || abort_deploy "POP_INAPP_STANDING_POLICY effective config is '${STANDING_POLICY_EFFECTIVE}', expected ${STANDING_POLICY_EXPECTED}"
  echo "✅ POP_INAPP_STANDING_POLICY effective config = ${STANDING_POLICY_EFFECTIVE}"
fi
# ✅ 清除 OPcache（mod_php 架構，用自製 artisan 指令，不需 sudo）
cd /home/admin/backend && php artisan opcache:reset 2>/dev/null \
  && echo "✅ OPcache 清除完成" \
  || echo "⚠️ OPcache reset 失敗（非致命，TTL 到期後自動清除）"

# CLI opcache:reset cannot refresh FPM workers' cached config; this endpoint does it
# in the web process (needed after DB credential rotation, no interactive sudo).
DEPLOY_SECRET="$(grep '^DEPLOY_SECRET=' /home/admin/backend/.env | cut -d= -f2-)"
if [ -z "$DEPLOY_SECRET" ]; then
  echo "❌ DEPLOY_SECRET missing; refusing to continue with stale FPM config"
  exit 1
fi
for attempt in $(seq 1 16); do
  if ! OPCACHE_RESPONSE=$(curl -skf -X POST -w '\n%{http_code}' \
      https://daan.lifenet.com.tw/api/internal/opcache-reset \
      -H "X-Deploy-Secret: ${DEPLOY_SECRET}" \
      -H "Connection: close" \
      -H "Content-Type: application/json"); then
    abort_deploy "FPM web-process refresh request failed on attempt ${attempt} http=${OPCACHE_RESPONSE##*$'\n'}"
  fi
  if ! echo "$OPCACHE_RESPONSE" | grep -q '"ok":true' || \
     ! echo "$OPCACHE_RESPONSE" | grep -q '"db_connection_refreshed":true'; then
    abort_deploy "FPM web-process refresh failed on attempt ${attempt}"
  fi
done
unset DEPLOY_SECRET OPCACHE_RESPONSE
echo "✅ FPM OPcache/config and DB connection refresh completed across 16 worker requests"

echo "=== [6/7] Health + identity (bounded retries) + Smoke ==="
export SMOKE_BASE_URL="https://daan.lifenet.com.tw"
export SMOKE_ON_PI=1
export SMOKE_TEACHER_LOGIN="@@DEPLOY_SMOKE_TEACHER_LOGIN@@"
export SMOKE_TEACHER_PASSWORD="@@DEPLOY_SMOKE_TEACHER_PASSWORD@@"
export SMOKE_BRANCH_ID="@@DEPLOY_SMOKE_BRANCH_ID@@"
export SMOKE_EXPECTED_BACKEND_SHA="$TARGET_SHA"
export SMOKE_REQUIRE_DEPLOYMENT_MANIFEST=1
SENTRY_WATCH="@@DEPLOY_SENTRY_WATCH@@" python3 -I "$PDV" gate -k --target-sha "$TARGET_SHA" --previous-sha "$PREV_COMMIT" --frontend-changed "$FRONTEND_CHANGED" --migration-ran "$MIG_HARD$MIGRATION_RAN" --watch-seconds 1200
case $? in 0) exit 0 ;; 20) ;; *) exit 12 ;; esac
abort_deploy "health check 或 smoke test 失敗"
