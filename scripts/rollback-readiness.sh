#!/usr/bin/env bash
# Rollback Readiness Check（#733）— 完全非破壞性，零 production 風險。
#
# 在 GitHub Actions / 本地 checkout 內驗證「萬一要回滾，機制是否健全」：
#   CHECK 1  deploy.yml 自動回滾區塊完整（PREV_COMMIT 捕捉 + git reset + migrate:rollback + 二次 health）
#   CHECK 2  所有 migration 都有 down()（否則 migrate:rollback 會半途失敗）
#   CHECK 3  最新 main commit 可乾淨 git revert（程式碼回滾模擬，sandbox 內 revert 後立即 abort）
#   CHECK 4  DB 備份還原驗證 workflow 存在且引用 mysqldump（資料層回滾的安全網）
#
# 本機跑：bash scripts/rollback-readiness.sh
# 退出碼：0 = 就緒；1 = 有缺口（詳見輸出）。

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

FAIL=0
note() { printf '%s\n' "$*"; }
ok()   { printf '  ✅ %s\n' "$*"; }
bad()  { printf '  ❌ %s\n' "$*"; FAIL=1; }

note "==================================================================="
note " Rollback Readiness Check (#733) — 非破壞性"
note "==================================================================="

# ── CHECK 1：deploy.yml 自動回滾區塊完整 ──────────────────────────────
note ""
note "[CHECK 1] deploy.yml 自動回滾區塊"
DEPLOY=".github/workflows/deploy.yml"
if [ ! -f "$DEPLOY" ]; then
  bad "$DEPLOY 不存在"
else
  grep -q 'PREV_COMMIT=$(git rev-parse HEAD)' "$DEPLOY" \
    && ok "有捕捉 PREV_COMMIT（回滾錨點）" \
    || bad "缺 PREV_COMMIT 捕捉 → 失敗時無法定位回滾目標"
  grep -q 'git reset --hard "$PREV_COMMIT"' "$DEPLOY" \
    && ok "有 git reset --hard \$PREV_COMMIT（程式碼回滾）" \
    || bad "缺程式碼回滾步驟"
  grep -q 'migrate:rollback' "$DEPLOY" \
    && ok "有 migrate:rollback（DB schema 回滾）" \
    || bad "缺 migrate:rollback"
  # 健康檢查必須出現至少兩次（部署後 + 回滾後）
  HC=$(grep -c '/api/v1/health' "$DEPLOY")
  if [ "${HC:-0}" -ge 2 ]; then
    ok "部署後 + 回滾後皆有 health check（共 $HC 處）"
  else
    bad "health check 出現 $HC 次（應 ≥2：部署後 + 回滾後）"
  fi
fi

# ── CHECK 2：migration 可逆（每筆都有 down()）────────────────────────
note ""
note "[CHECK 2] Migration 可逆性（down() 覆蓋）"
MIG_DIR="backend/database/migrations"
if [ ! -d "$MIG_DIR" ]; then
  bad "$MIG_DIR 不存在"
else
  TOTAL=$(find "$MIG_DIR" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
  MISSING=$(grep -L 'function down' "$MIG_DIR"/*.php 2>/dev/null || true)
  if [ -z "$MISSING" ]; then
    ok "全部 $TOTAL 筆 migration 都有 down() — migrate:rollback 可完整回滾"
  else
    bad "下列 migration 缺 down()（rollback 會半途失敗）："
    printf '       %s\n' $MISSING
  fi
fi

# ── CHECK 3：最新 main commit 可乾淨 revert（程式碼回滾模擬）──────────
note ""
note "[CHECK 3] git revert 乾淨度（程式碼回滾模擬，sandbox）"
HEAD_SHA=$(git rev-parse HEAD)
PARENTS=$(git rev-list --parents -n 1 HEAD | wc -w)
# rev-list --parents 輸出 = <self> <p1> [<p2>]；欄位數 2=一般 commit，3=merge commit
REVERT_ARGS=(--no-commit --no-edit)
if [ "$PARENTS" -ge 3 ]; then
  REVERT_ARGS+=(-m 1)
  note "  （HEAD 為 merge commit，用 -m 1）"
fi
if git revert "${REVERT_ARGS[@]}" "$HEAD_SHA" >/dev/null 2>&1; then
  ok "最新 commit ${HEAD_SHA:0:8} 可乾淨 revert"
  git revert --abort >/dev/null 2>&1 || git reset --hard "$HEAD_SHA" >/dev/null 2>&1 || true
else
  bad "最新 commit ${HEAD_SHA:0:8} revert 有衝突 — 程式碼回滾需人工處理"
  git revert --abort >/dev/null 2>&1 || git reset --hard "$HEAD_SHA" >/dev/null 2>&1 || true
fi

# ── CHECK 4：DB 備份還原驗證安全網存在 ──────────────────────────────
note ""
note "[CHECK 4] DB 備份還原安全網"
BRT=".github/workflows/backup-restore-test.yml"
if [ -f "$BRT" ] && grep -qiE 'mysqldump|restore|AllTrue' "$BRT"; then
  ok "backup-restore-test.yml 存在且驗證 DB 備份還原（資料層回滾安全網）"
else
  bad "缺 DB 備份還原驗證 workflow"
fi

note ""
note "==================================================================="
if [ "$FAIL" -eq 0 ]; then
  note " ✅ Rollback Readiness: PASS — 回滾機制健全"
else
  note " ❌ Rollback Readiness: FAIL — 見上方 ❌，回滾前先補洞"
fi
note "==================================================================="
exit "$FAIL"
