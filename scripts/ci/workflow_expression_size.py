#!/usr/bin/env python3
"""Fail when a workflow run block nears GitHub's 21,000-byte expression limit (#4051).

GitHub turns a `run:` block containing `${{ }}` into one format() expression; above 21,000 UTF-8
bytes it silently stops parsing the workflow (a failed 0-job run per push, nothing runs). Plain
blocks are not expressions and are only reported. Forms this line-based reader cannot size
exactly (anchor, alias, tag: #4085; `|2`; quoted or flow-style keys) fail closed. Covers
workflows and composite actions. Stdlib only.

Usage: python3 scripts/ci/workflow_expression_size.py [--limit BYTES] [workflow ...]
"""
from pathlib import Path
import argparse
import re
import sys

GITHUB_LIMIT = 21_000
# Fail well before the hard limit so the next edit still has room to land a fix.
DEFAULT_LIMIT = 18_000
ROOT = Path(__file__).resolve().parents[2]
RUN_KEY = re.compile(r"^(\s*)(?:- )?run:(?:\s+(.*))?$")
# A quoted `"run":` key or a flow mapping `{..., run: ...}` is not read line by line: fail closed.
UNREADABLE_KEY = re.compile(r"^\s*(?:- )?(?:[\"']run[\"']\s*:|\{.*\brun\s*:)")
BLOCK_INDICATOR = re.compile(r"[|>][-+]?(\s+#.*)?")
UNMEASURABLE = re.compile(r"[&*!]|[|>][-+]?[0-9]")


class UnmeasurableRunBlock(ValueError):
    pass


def expression_size(script):
    """Bytes of the format('<template>', expr, ...) GitHub builds for a script with ${{ }}."""
    exprs = re.findall(r"\$\{\{(.*?)\}\}", script, re.S)
    template = re.sub(r"\$\{\{.*?\}\}", "\0", script, flags=re.S)
    template = template.replace("'", "''").replace("{", "{{").replace("}", "}}")
    parts = template.split("\0")
    template = "".join(p + (f"{{{n}}}" if n < len(exprs) else "") for n, p in enumerate(parts))
    return len((f"format('{template}', " + ", ".join(e.strip() for e in exprs) + ")").encode("utf-8"))


def run_blocks(workflow_text):
    """(line, script) for every run value; raises UnmeasurableRunBlock for forms it cannot size."""
    lines = workflow_text.split("\n")
    blocks = []
    for i, line in enumerate(lines):
        if UNREADABLE_KEY.match(line):
            raise UnmeasurableRunBlock(f"line {i + 1}: quoted or flow-style run key; use a plain `run: |` block")
        m = RUN_KEY.match(line)
        if not m:
            continue
        key_indent, rest = len(m.group(1)), (m.group(2) or "").strip()
        if UNMEASURABLE.match(rest):
            raise UnmeasurableRunBlock(f"line {i + 1}: run value `{rest}` uses an anchor, alias, tag or "
                                       "explicit indentation indicator; use a plain `run: |` block")
        inline = rest and not BLOCK_INDICATOR.fullmatch(rest)
        body = []
        for nxt in lines[i + 1:]:
            if nxt.strip() and len(nxt) - len(nxt.lstrip(" ")) <= key_indent:
                break
            body.append(nxt)
        if inline:
            # A quoted or plain inline value; continuation lines belong to the same scalar.
            script = " ".join([rest] + [x.strip() for x in body if x.strip()])
            if len(script) >= 2 and script[0] == script[-1] and script[0] in "'\"":
                script = script[1:-1]
        else:
            indents = [len(x) - len(x.lstrip(" ")) for x in body if x.strip()]
            script = "\n".join(x[min(indents):] for x in body) if indents else ""
        blocks.append((i + 1, script))
    return blocks


def run_block_expression_sizes(workflow_text):
    """(line, bytes) for each run block containing ${{ }}: the size of the format() GitHub builds."""
    return [(line, expression_size(s)) for line, s in run_blocks(workflow_text) if "${{" in s]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--limit", type=int, default=DEFAULT_LIMIT)
    parser.add_argument("workflows", nargs="*", type=Path)
    args = parser.parse_args(argv)
    paths = args.workflows or sorted((ROOT / ".github" / "workflows").glob("*.y*ml")) + sorted(
        (ROOT / ".github" / "actions").glob("*/action.y*ml"))
    failures, largest, largest_plain = [], (0, "", 0), (0, "", 0)
    for path in paths:
        try:
            blocks = run_blocks(path.read_text(encoding="utf-8"))
        except UnmeasurableRunBlock as exc:
            failures.append(f"{path.name}: {exc}")
            continue
        for line, script in blocks:
            if "${{" not in script:
                largest_plain = max(largest_plain, (len(script.encode("utf-8")), path.name, line))
                continue
            size = expression_size(script)
            largest = max(largest, (size, path.name, line))
            if size >= args.limit:
                failures.append(f"{path.name}:{line}: run block expression is {size} bytes (limit "
                                f"{args.limit}, GitHub rejects the workflow above {GITHUB_LIMIT}). Move "
                                "${{ }} values into the step's env: or the shell into a checked-in script")
    for failure in failures:
        print(f"::error::{failure}.")
    print(f"Largest run block expression: {largest[0]} bytes ({largest[1]}:{largest[2]}); limit {args.limit}.")
    print(f"Largest plain run block (no ${{{{ }}}}, not an expression): {largest_plain[0]} bytes "
          f"({largest_plain[1]}:{largest_plain[2]}).")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
