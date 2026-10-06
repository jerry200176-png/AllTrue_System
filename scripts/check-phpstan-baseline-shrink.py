#!/usr/bin/env python3
"""PHPStan baseline may only shrink in a PR (see CLAUDE.md Y4).

Usage: check-phpstan-baseline-shrink.py BASE_FILE HEAD_FILE   |   --self-test
Exit 1 if HEAD adds an entry or raises a count. Messages are compared with
backslashes stripped so a pure re-escape (phpstan version bump) is not growth.
"""
import re
import sys


def entries(text):
    out = {}
    for block in text.split("\n\t\t-\n")[1:]:
        msg = re.search(r"message: (.*)", block)
        path = re.search(r"path: (.*)", block)
        count = re.search(r"count: (\d+)", block)
        if msg and path and count:
            key = (msg[1].replace("\\", ""), path[1].strip())
            out[key] = out.get(key, 0) + int(count[1])
    return out


def growth(base, head):
    b, h = entries(base), entries(head)
    return [(k, h[k] - b.get(k, 0)) for k in h if h[k] > b.get(k, 0)]


def self_test():
    def mk(*e):
        return "parameters:\n\tignoreErrors:\n" + "".join(
            f"\t\t-\n\t\t\tmessage: '{m}'\n\t\t\tcount: {c}\n\t\t\tpath: {p}\n\n" for m, c, p in e)
    base = mk(("#^A\\<\\=$#", 2, "a.php"))
    assert not growth(base, mk(("#^A<=$#", 2, "a.php")))  # re-escape only
    assert not growth(base, mk())  # removal ok
    assert growth(base, mk(("#^A\\<\\=$#", 3, "a.php")))  # count up
    assert growth(base, mk(("#^B$#", 1, "a.php")))  # new entry
    print("self-test ok")


if __name__ == "__main__":
    if sys.argv[1:] == ["--self-test"]:
        self_test()
        sys.exit(0)
    bad = growth(open(sys.argv[1]).read(), open(sys.argv[2]).read())
    for (msg, path), n in bad:
        print(f"::error file=backend/phpstan-baseline.neon::+{n} {path}: {msg}")
    if bad:
        print("PHPStan baseline may only shrink. Fix the code, or add an @property docblock to the "
              "model, instead of baselining. Rare exception: label the PR `phpstan-baseline-growth` "
              "and re-run this check.", file=sys.stderr)
        sys.exit(1)
    print("phpstan baseline did not grow")
