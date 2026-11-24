#!/usr/bin/env python3
"""Fail if a PR makes tests check less: fewer assertions, fewer exact assertions,
fewer test cases, or new skips, in any test file.

Catches the "make it green" failure: an agent turns `toBe('NT$1,200')` into
`toContain('NT$')`, deletes the failing expect, or deletes the failing test.

Counts per file over the PR diff (merge-base..head, renames followed).
Waiver: a PR body line `Test-Weakening: <reason>` (e.g. the feature was removed).
ponytail: line regex, not a parser; a multi-line assertion counts once, fine for a net count.
Usage: check-test-weakening.py [--base origin/main] [--head HEAD] [--body-file pr.md]
"""
import argparse
import re
import subprocess
import sys

TEST_FILE = re.compile(r"(^backend/tests/.*\.php$|\.(test|spec)\.(js|mjs|ts|py|sh)$|(^|/)test_[^/]*\.py$)")
COUNTERS = {
    "assertions": re.compile(r"\bexpect(\.soft)?\(|->assert\w+\(|\$this->expectException|\bassert[\w.]*\(|^\s*assert\s"),
    "exact assertions": re.compile(
        r"\.(toBe|toEqual|toStrictEqual|toHaveText|toHaveValue|toHaveCount|toHaveURL|toHaveLength)\(|"
        r"assert(Same|Equals|Count|EqualsCanonicalizing)\(|assertEqual\(|assert\.(equal|strictEqual|deepEqual|deepStrictEqual)\(|"
        r"^\s*assert .*==[^=]"),
    "test cases": re.compile(r"\b(it|test)(\.(skip|only|fixme)|\.each\(.*?\))?\(\s*['\"`]|\bfunction test\w+\(|#\[Test\]|\*\s*@test\b|\bdef test_\w+\("),
}
SKIP = re.compile(r"\.(skip|only|fixme)\(|markTestSkipped|markTestIncomplete|@pytest\.mark\.skip|unittest\.skip")
WAIVER = re.compile(r"^[ \t]*Test-Weakening:[ \t]*\S.{9,}", re.M)  # reason on the same line


def counts(lines):
    return {k: sum(len(list(rx.finditer(l))) for l in lines) for k, rx in COUNTERS.items()}  # every match, not lines


def parse_diff(text):
    """-> {path: [removed_lines, added_lines, renamed_to]} for test files only.

    renamed_to is set when a test file is renamed to a non-test name (its checks would vanish unseen);
    its edited lines keep counting under the old test path. ---/+++ are headers only before the first @@,
    so a removed or added line whose text starts with "-- a/" or "++ b/" stays a content line.
    """
    files, path, header, old = {}, None, False, None
    for line in text.splitlines():
        if line.startswith("diff --git"):
            path, header, old = None, True, None
        elif line.startswith("@@"):
            header = False
        elif header:
            if line.startswith("rename from "):
                old = line[12:]
            elif line.startswith("rename to ") and old and TEST_FILE.search(old) and not TEST_FILE.search(line[10:]):
                files.setdefault(old, [[], [], None])[2] = line[10:]
            elif line.startswith("--- a/") and TEST_FILE.search(line[6:]):
                path = line[6:]  # deleted file: +++ is /dev/null, so key it by the old path
                files.setdefault(path, [[], [], None])
            elif line.startswith("+++ b/") and TEST_FILE.search(line[6:]):
                path = line[6:]
                files.setdefault(path, [[], [], None])
        elif path and line.startswith("-"):
            files[path][0].append(line[1:])
        elif path and line.startswith("+"):
            files[path][1].append(line[1:])
    return files


def findings(files):
    out = []
    for path, (removed, added, renamed_to) in sorted(files.items()):
        if renamed_to:
            out.append(f"{path}: test file renamed to non-test name {renamed_to}")
        before, after = counts(removed), counts(added)
        for k in COUNTERS:
            if after[k] < before[k]:
                out.append(f"{path}: {k} {before[k]} removed, {after[k]} added")
        new_skips = sum(bool(SKIP.search(l)) for l in added) - sum(bool(SKIP.search(l)) for l in removed)
        if new_skips > 0:
            out.append(f"{path}: {new_skips} new skip/only/fixme")
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="origin/main")
    ap.add_argument("--head", default="HEAD")
    ap.add_argument("--body-file")
    a = ap.parse_args()
    git = lambda *x: subprocess.run(["git", "-c", "core.quotepath=off", *x], check=True,
                                    capture_output=True, text=True, errors="replace").stdout
    base = git("merge-base", a.head, a.base).strip()
    rows = findings(parse_diff(git("diff", "-M", "--unified=0", base, a.head)))
    if not rows:
        print("OK: no test file checks less than before")
        return 0
    body = open(a.body_file, encoding="utf-8").read() if a.body_file else ""
    print("Tests check less than before:\n  " + "\n  ".join(rows))
    if WAIVER.search(body):
        print("::warning::waived by PR body line `Test-Weakening:`")
        return 0
    print("\nIf a test was red, fix the code, not the test. If the behaviour really changed or was removed,\n"
          "add a PR body line `Test-Weakening: <why, at least 10 characters>`.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
