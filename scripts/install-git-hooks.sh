#!/bin/bash
# 安裝本地 git hooks（一次性設定，不進 git 追蹤）
# 執行：bash scripts/install-git-hooks.sh

set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOOKS_DIR="$REPO_ROOT/.git/hooks"

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

# PHP syntax check（只掃暫存的 .php 檔）
PHP_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)
if [ -n "$PHP_FILES" ]; then
  while IFS= read -r file; do
    if ! php -l "$file" >/dev/null 2>&1; then
      echo "❌ PHP syntax error: $file"
      ERRORS=$((ERRORS + 1))
    fi
  done <<< "$PHP_FILES"
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
echo "✅ pre-commit hook → PHP syntax check + debug 語句警告"
echo "✅ commit-msg hook → Conventional Commits 格式驗證"
echo "✅ post-merge hook → 自動 mine MemPalace"
echo ""
echo "完成！hooks 已安裝到 .git/hooks/"
