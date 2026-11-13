#!/usr/bin/env bash
# Self-test for scripts/ci/check-testcase-method-collisions.php (#5201).
# Needs backend/vendor (composer install): the guard reflects on the real PHPUnit TestCase and parses with nikic/php-parser.
set -euo pipefail
cd "$(dirname "$0")/../.."

out=$(mktemp)
trap 'rm -f "$out"' EXIT
guard=scripts/ci/check-testcase-method-collisions.php
fixtures=scripts/tests/fixtures/testcase-method-guard
export ALLTRUE_GUARD_EXTRA_AUTOLOAD="$PWD/$fixtures/stubs.php"

# expect_fail <dir> <extended regex the output must match>
expect_fail() {
  if timeout 30 php "$guard" --tests-dir="$fixtures/$1" >"$out" 2>&1; then
    echo "FAIL: guard accepted fixture $1" >&2
    cat "$out" >&2
    exit 1
  fi
  grep -Eq "$2" "$out" || {
    echo "FAIL: fixture $1 failed without the expected line: $2" >&2
    cat "$out" >&2
    exit 1
  }
  if grep -Eq 'setUp\(\) collides' "$out"; then
    echo "FAIL: allowed override setUp was reported for $1" >&2
    exit 1
  fi
}

expect_fail colliding 'CollidingTest.php:[0-9]+ count\(\) collides with PHPUnit\\Framework\\TestCase::count'
expect_fail laravel-trait 'SessionTest.php:[0-9]+ session\(\) collides with .*::session'
expect_fail grouped-trait 'CountHelper.php:[0-9]+ count\(\) collides with PHPUnit\\Framework\\TestCase::count'
expect_fail uppercase 'UpperCaseTest.php:[0-9]+ Count\(\) collides with PHPUnit\\Framework\\TestCase::count'
expect_fail grouped-function-import 'CountTrait.php:[0-9]+ count\(\) collides with PHPUnit\\Framework\\TestCase::count'
expect_fail ampersand 'AmpersandTest.php:[0-9]+ count\(\) collides with PHPUnit\\Framework\\TestCase::count'
expect_fail trait-alias 'AliasTest.php:[0-9]+ count\(\) collides with PHPUnit\\Framework\\TestCase::count'
expect_fail mixed-case-trait 'MixedCaseTest.php:[0-9]+ count\(\) collides with PHPUnit\\Framework\\TestCase::count'
expect_fail unresolved 'extends unresolvable Missing\\NoSuchBase'

php "$guard" --tests-dir="$fixtures/allowed" >"$out" 2>&1 || {
  echo "FAIL: guard rejected a clean fixture" >&2
  cat "$out" >&2
  exit 1
}
grep -q '3 test classes checked' "$out" || { echo "FAIL: checked count missing" >&2; cat "$out" >&2; exit 1; }
echo "testcase-method-guard self-test: ok"
