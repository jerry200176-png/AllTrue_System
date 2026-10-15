#!/usr/bin/env python3
"""Render the remote deploy script that the Deploy step sends to the Pi over ssh stdin (#4051).

deploy.yml used to inline this script as a quoted heredoc, with nine ${{ }} values that GitHub
substituted as plain text before the step ran. That made the run block a single format()
expression close to GitHub's 21,000-byte limit. The script now lives in
scripts/production-deploy/pi-remote-deploy.sh, and each former ${{ }} is an @@DEPLOY_<NAME>@@ placeholder.
The step passes the same values through its env: as DEPLOY_<NAME>, and this renderer substitutes
them as plain text in one pass, exactly as GitHub did. The output is byte-for-byte the script
the Pi received before. It writes to stdout and exits non-zero, so nothing is sent, when the
template is missing or names a placeholder outside the allowlist.

Usage: python3 scripts/production-deploy/render_pi_remote_deploy.py TEMPLATE > rendered.sh
"""
import os
import re
import sys

# Every value the remote script receives. An unset variable renders as "", like a ${{ }}
# expression that evaluates to nothing.
PLACEHOLDERS = (
    "TARGET_SHA",
    "RELEASE_TRAIN",
    "ADMISSIONS_FUNNEL_V1",
    "COURSE_SESSION_CALENDAR_V1",
    "COURSE_MANAGER_V1",
    "SENTRY_DSN",
    "SMOKE_TEACHER_LOGIN",
    "SMOKE_TEACHER_PASSWORD",
    "SMOKE_BRANCH_ID",
)
PATTERN = re.compile(r"@@DEPLOY_([A-Z0-9_]+)@@")


def render(template, environ):
    unknown = sorted({name for name in PATTERN.findall(template) if name not in PLACEHOLDERS})
    if unknown:
        raise ValueError(f"unknown placeholder(s): {', '.join(unknown)}")
    # One pass, like GitHub's substitution: a value that itself looks like a placeholder stays literal.
    return PATTERN.sub(lambda m: environ.get(f"DEPLOY_{m.group(1)}", ""), template)


def main(argv):
    if len(argv) != 2:
        print("usage: render_pi_remote_deploy.py TEMPLATE", file=sys.stderr)
        return 2
    try:
        with open(argv[1], encoding="utf-8", newline="") as handle:
            rendered = render(handle.read(), os.environ)
    except (OSError, ValueError) as exc:
        print(f"::error::cannot render remote deploy script: {exc}; nothing deployed", file=sys.stderr)
        return 1
    sys.stdout.buffer.write(rendered.encode("utf-8"))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
