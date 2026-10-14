#!/usr/bin/env python3
"""Fail when a workflow run block nears GitHub's 21,000-byte expression limit (#4051).

GitHub turns a `run:` block that contains `${{ }}` into one format() expression. Above
21,000 UTF-8 bytes it silently stops parsing the whole workflow: every push then shows a
failed 0-job run named after the file, and nothing in that workflow runs (for deploy.yml:
no deploy and no release train). Stdlib only, so it runs in any job without installs.

Usage: python3 scripts/ci/workflow_expression_size.py [--limit BYTES] [workflow ...]
"""
from pathlib import Path
import argparse
import re
import sys

GITHUB_LIMIT = 21_000
# Fail well before the hard limit so the next edit still has room to land a fix.
DEFAULT_LIMIT = 20_000
ROOT = Path(__file__).resolve().parents[2]


def run_block_expression_sizes(workflow_text):
    """(line, bytes) for each run block containing ${{ }}: the size of the format() GitHub builds."""
    lines = workflow_text.split("\n")
    sizes = []
    for i, line in enumerate(lines):
        m = re.match(r"^(\s*)(?:- )?run: [|>][-+]?\s*$", line)
        if not m:
            continue
        key_indent, body = len(m.group(1)), []
        for nxt in lines[i + 1:]:
            if nxt.strip() and len(nxt) - len(nxt.lstrip(" ")) <= key_indent:
                break
            body.append(nxt)
        indents = [len(x) - len(x.lstrip(" ")) for x in body if x.strip()]
        script = "\n".join(x[min(indents):] for x in body) if indents else ""
        if "${{" not in script:
            continue
        # format('<template>', expr, ...): the template doubles every ' { } and each ${{ }} becomes {N}.
        exprs = re.findall(r"\$\{\{(.*?)\}\}", script, re.S)
        template = re.sub(r"\$\{\{.*?\}\}", "\0", script, flags=re.S)
        template = template.replace("'", "''").replace("{", "{{").replace("}", "}}")
        parts = template.split("\0")
        template = "".join(p + (f"{{{n}}}" if n < len(exprs) else "") for n, p in enumerate(parts))
        expression = f"format('{template}', " + ", ".join(e.strip() for e in exprs) + ")"
        sizes.append((i + 1, len(expression.encode("utf-8"))))
    return sizes


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--limit", type=int, default=DEFAULT_LIMIT)
    parser.add_argument("workflows", nargs="*", type=Path)
    args = parser.parse_args(argv)
    paths = args.workflows or sorted((ROOT / ".github" / "workflows").glob("*.y*ml"))
    failures, largest = [], (0, "", 0)
    for path in paths:
        for line, size in run_block_expression_sizes(path.read_text(encoding="utf-8")):
            largest = max(largest, (size, path.name, line))
            if size >= args.limit:
                failures.append(f"{path.name}:{line}: run block expression is {size} bytes "
                                f"(limit {args.limit}, GitHub rejects the workflow above {GITHUB_LIMIT})")
    for failure in failures:
        print(f"::error::{failure}. Move ${{{{ }}}} values into the step's env: or the shell into a script.")
    print(f"Largest run block expression: {largest[0]} bytes ({largest[1]}:{largest[2]}); limit {args.limit}.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
