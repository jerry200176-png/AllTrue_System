#!/usr/bin/env bash
# Self-test for scripts/ci/check-testcase-method-collisions.php (#5201).
# Needs backend/vendor (composer install) so the guard can reflect on the real PHPUnit/Laravel TestCase.
set -euo pipefail
cd "$(dirname "$0")/../.."

out_bad=$(mktemp)
out_ok=$(mktemp)
trap 'rm -f "$out_bad" "$out_ok"' EXIT
guard=scripts/ci/check-testcase-method-collisions.php
fixtures=scripts/tests/fixtures/testcase-method-guard

if php "$guard" --tests-dir="$fixtures/colliding" >"$out_bad" 2>&1; then
  echo "FAIL: guard accepted a test class that declares count()" >&2
  cat "$out_bad" >&2
  exit 1
fi
grep -Eq 'CollidingTest.php:[0-9]+ count\(\) collides with PHPUnit\\Framework\\TestCase::count' "$out_bad" || {
  echo "FAIL: collision line missing or wrong" >&2
  cat "$out_bad" >&2
  exit 1
}
if grep -Eq 'CollidingTest.php:[0-9]* setUp' "$out_bad"; then
  echo "FAIL: allowed override setUp was reported" >&2
  exit 1
fi

php "$guard" --tests-dir="$fixtures/allowed" >"$out_ok" 2>&1 || {
  echo "FAIL: guard rejected a clean fixture" >&2
  cat "$out_ok" >&2
  exit 1
}
echo "testcase-method-guard self-test: ok"
