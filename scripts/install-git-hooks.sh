#!/bin/bash
# 安裝／卸載本地 git hooks（一次性設定，不進 git 追蹤）
# 安裝：bash scripts/install-git-hooks.sh
# 卸載：bash scripts/install-git-hooks.sh --uninstall
#
# 這些是「本地」hooks，只影響你這台開發機的 commit/push，不影響 CI checkout 行為。
# bypass 政策：緊急時可用 `git commit --no-verify` 跳過，但 CI 仍會把關（pre-commit 的檢查多數
# 在 CI 有對應 gate）。git-index-audit 屬安全護欄，請勿長期 --no-verify 規避（見 §R58）。

set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOOKS_DIR="$REPO_ROOT/.git/hooks"

if [ "${1:-}" = "--uninstall" ]; then
  echo "=== 卸載 git hooks ==="
  for h in pre-push pre-commit commit-msg post-merge; do
    if [ -f "$HOOKS_DIR/$h" ]; then
      rm -f "$HOOKS_DIR/$h"
      echo "🗑️  移除 $h"
    fi
  done
  echo "完成！已移除本地 hooks（不影響 git 追蹤檔案與 CI）。"
  exit 0
fi

echo "=== 安裝 git hooks ==="

# 1. pre-push：禁止直接 push main
cat > "$HOOKS_DIR/pre-push" << 'EOF'
#!/bin/bash
branch=$(git branch --show-current)
if [ "$branch" = "main" ]; then
  echo "❌ 禁止直接 push main！"
  echo "   請走 feature branch → PR → CI → merge 流程。"
  echo "   違反此規則曾造成 5 次生產事故（見 .cursorrules 事故 A-F）"
  exit 1
fi
exit 0
EOF

# 2. pre-commit：PHP syntax check + 檢查禁用字串
cat > "$HOOKS_DIR/pre-commit" << 'EOF'
#!/bin/bash
set -euo pipefail
ERRORS=0

# 0. git index 稽核（§R58）：保護路徑（backend/ frontend/ scripts/ .github/ docs/）的
#    tracked 檔案不得被 assume-unchanged / skip-worktree 旗標隱藏，否則改動會在 status/diff
#    中隱形、規避 PR review。偵測到就擋下 commit。
if [ -x scripts/git-index-audit.sh ]; then
  if ! scripts/git-index-audit.sh protected >/dev/null 2>&1; then
    echo "❌ 偵測到保護路徑檔案被 git index flag 隱藏（assume-unchanged/skip-worktree）："
    scripts/git-index-audit.sh protected || true
    echo "   請先清除旗標再 commit；詳見 docs/AI_REGRESSION_LESSONS.md §R58"
    exit 1
  fi
fi

# PHP syntax check（只掃暫存的 .php 檔；若環境沒裝 PHP 則跳過）
if command -v php &>/dev/null; then
  PHP_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)
  if [ -n "$PHP_FILES" ]; then
    while IFS= read -r file; do
      if ! php -l "$file" >/dev/null 2>&1; then
        echo "❌ PHP syntax error: $file"
        ERRORS=$((ERRORS + 1))
      fi
    done <<< "$PHP_FILES"
  fi
else
  echo "⚠️  PHP not found locally — skipping PHP syntax check (CI will catch errors)"
fi

# 禁止把 console.log、dd()、var_dump() 提交進去
STAGED=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.(php|vue|js)$' || true)
if [ -n "$STAGED" ]; then
  while IFS= read -r file; do
    if git diff --cached "$file" | grep -qE '^\+.*(console\.log\(|dd\(|var_dump\()'; then
      echo "⚠️  $file 含有 debug 語句（console.log/dd/var_dump），請確認是否要提交"
    fi
  done <<< "$STAGED"
fi

[ "$ERRORS" -gt 0 ] && exit 1
exit 0
EOF

# 3. commit-msg：commitlint — 強制 conventional commits 格式
cat > "$HOOKS_DIR/commit-msg" << 'EOF'
#!/bin/bash
# Conventional Commits 格式驗證
# 格式：type(scope): description
# type: feat|fix|docs|chore|refactor|test|perf|ci|revert|td
MSG=$(cat "$1")
PATTERN='^(feat|fix|docs|chore|refactor|test|perf|ci|revert|td|style|build)(\([a-z0-9_-]+\))?: .{1,100}'
if ! echo "$MSG" | grep -qE "$PATTERN"; then
  echo ""
  echo "❌ Commit message 格式不符 Conventional Commits："
  echo "   你寫的：$MSG"
  echo ""
  echo "   正確格式：type(scope): description"
  echo "   type 可以是：feat | fix | docs | chore | refactor | test | perf | ci | revert | td"
  echo "   範例：feat(attendance): add RFID swipe debounce"
  echo "         fix(billing): correct remaining sessions calculation"
  echo "         chore(deps): update phpunit to 9.6"
  echo ""
  exit 1
fi
exit 0
EOF

# 4. post-merge：PR merge 後自動 mine MemPalace
cat > "$HOOKS_DIR/post-merge" << 'EOF'
#!/bin/bash
MEMPALACE=~/.local/bin/mempalace
TRANSCRIPT_DIR=~/.cursor/projects/home-jerry-alltrue/agent-transcripts
if command -v "$MEMPALACE" >/dev/null 2>&1 && [ -d "$TRANSCRIPT_DIR" ]; then
  echo "🧠 MemPalace: mining latest session..."
  "$MEMPALACE" mine "$TRANSCRIPT_DIR" --mode convos --wing alltrue-sessions >/dev/null 2>&1 &
fi
exit 0
EOF

chmod +x "$HOOKS_DIR/pre-push" "$HOOKS_DIR/pre-commit" "$HOOKS_DIR/commit-msg" "$HOOKS_DIR/post-merge"

echo "✅ pre-push hook   → 禁止直接 push main"
echo "✅ pre-commit hook → git index 稽核(§R58) + PHP syntax check + debug 語句警告"
echo "✅ commit-msg hook → Conventional Commits 格式驗證"
echo "✅ post-merge hook → 自動 mine MemPalace"
echo ""
echo "完成！hooks 已安裝到 .git/hooks/"
echo "卸載：bash scripts/install-git-hooks.sh --uninstall"
