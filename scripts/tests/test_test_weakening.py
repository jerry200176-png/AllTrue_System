#!/usr/bin/env python3
"""Tests for scripts/check-test-weakening.py (run: python3 scripts/tests/test_test_weakening.py)."""
import importlib.util
import pathlib
import unittest

SRC = pathlib.Path(__file__).resolve().parents[1] / "check-test-weakening.py"
spec = importlib.util.spec_from_file_location("guard", SRC)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


def diff(path, removed, added, deleted=False):
    plus = "/dev/null" if deleted else f"b/{path}"
    body = [f"-{l}" for l in removed] + [f"+{l}" for l in added]
    return "\n".join([f"diff --git a/{path} b/{path}", f"--- a/{path}", f"+++ {plus}", "@@ -1 +1 @@", *body])


def run(text):
    return guard.findings(guard.parse_diff(text))


SPEC = "frontend/e2e/checkout.spec.js"


class T(unittest.TestCase):
    def test_exact_amount_loosened(self):
        rows = run(diff(SPEC, ["  await expect(total).toHaveText('NT$1,200');"],
                        ["  await expect(total).toContainText('NT$');"]))
        self.assertEqual(rows, [f"{SPEC}: exact assertions 1 removed, 0 added"])

    def test_expect_deleted(self):
        rows = run(diff(SPEC, ["  expect(x).toBe(1);", "  expect(y).toBe(2);"], ["  expect(x).toBe(1);"]))
        self.assertIn(f"{SPEC}: assertions 2 removed, 1 added", rows)

    def test_test_case_deleted(self):
        rows = run(diff(SPEC, ["test('invalid coupon', async () => {"], []))
        self.assertIn(f"{SPEC}: test cases 1 removed, 0 added", rows)

    def test_php_test_file_deleted(self):
        p = "backend/tests/Feature/BillTest.php"
        rows = run(diff(p, ["    public function test_total(): void", "        $this->assertSame(1200, $t);"],
                        [], deleted=True))
        self.assertIn(f"{p}: assertions 1 removed, 0 added", rows)
        self.assertIn(f"{p}: test cases 1 removed, 0 added", rows)

    def test_new_skip(self):
        skipped = "test.sk" + "ip('vip', async () => {"  # split so this file does not trip the check
        rows = run(diff(SPEC, ["test('vip', async () => {"], [skipped]))
        self.assertEqual(rows, [f"{SPEC}: 1 new skip/only/fixme"])

    def test_equal_rewrite_ok(self):
        self.assertEqual(run(diff(SPEC, ["  expect(a).toBe(1);"], ["  expect(b).toBe(2);"])), [])

    def test_more_checks_ok(self):
        self.assertEqual(run(diff(SPEC, [], ["test('vip', () => {", "  expect(v).toEqual(9);"])), [])

    def test_production_file_ignored(self):
        self.assertEqual(run(diff("frontend/src/lib/a.js", ["expect(x).toBe(1);"], [])), [])

    def test_node_assert_deleted(self):
        p = "scripts/tests/x.test.mjs"
        rows = run(diff(p, ["  assert.equal(a, 1);", "  assert.ok(b);"], []))
        self.assertIn(f"{p}: assertions 2 removed, 0 added", rows)
        self.assertIn(f"{p}: exact assertions 1 removed, 0 added", rows)

    def test_empty_waiver_does_not_borrow_next_line(self):
        self.assertFalse(guard.WAIVER.search("Test-Weakening:\n## Risk-Class and more text\n"))

    def test_chained_assertion_removed(self):
        p = "backend/tests/Feature/AT.php"
        rows = run(diff(p, ["        $r->assertStatus(409)->assertJsonPath('code', 'x');"], ["        $r->assertStatus(409);"]))
        self.assertIn(f"{p}: assertions 2 removed, 1 added", rows)

    def test_phpunit_test_annotation_removed(self):
        p = "backend/tests/Unit/BT.php"
        rows = run(diff(p, ["    /** @test */"], ["    /** helper */"]))
        self.assertEqual(rows, [f"{p}: test cases 1 removed, 0 added"])

    def test_renamed_to_non_test_name_flagged(self):  # #5595 item 1
        text = "\n".join(["diff --git a/x.spec.js b/x.spec.js.bak", "similarity index 90%",
                          "rename from x.spec.js", "rename to x.spec.js.bak", "--- a/x.spec.js", "+++ b/x.spec.js.bak",
                          "@@ -1 +1 @@", "-  expect(a).toBe(1);", "+  // gone"])
        rows = run(text)
        self.assertIn("x.spec.js: test file renamed to non-test name x.spec.js.bak", rows)
        self.assertIn("x.spec.js: assertions 1 removed, 0 added", rows)

    def test_pure_rename_to_non_test_flagged(self):
        text = "\n".join(["diff --git a/a/t.test.mjs b/a/t.mjs.off", "similarity index 100%",
                          "rename from a/t.test.mjs", "rename to a/t.mjs.off"])
        self.assertEqual(run(text), ["a/t.test.mjs: test file renamed to non-test name a/t.mjs.off"])

    def test_more_test_file_patterns(self):  # #5595 item 2
        for p in ["scripts/x.test.py", "scripts/x.test.sh", ".claude/hooks/test_guard.py", "scripts/bug-writeback/test_w.py"]:
            self.assertIn(f"{p}: assertions 1 removed, 0 added", run(diff(p, ["assert ok(x)"], [])), p)

    def test_content_line_looking_like_header(self):  # #5595 item 3
        p = "scripts/tests/test_x.py"
        text = diff(p, ["-- a/not-a-header", "    assert a == 1"], ["++ b/not-a-header"])
        self.assertIn(f"{p}: assertions 1 removed, 0 added", run(text))

    def test_waiver_needs_reason(self):
        self.assertTrue(guard.WAIVER.search("x\nTest-Weakening: coupon feature removed in #1\n"))
        self.assertFalse(guard.WAIVER.search("Test-Weakening: ok\n"))


if __name__ == "__main__":
    unittest.main()
