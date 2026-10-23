#!/usr/bin/env python3
"""Render the Pi deploy script from pi-remote-deploy.sh for the Deploy step's ssh stdin (#4051).

Each former ${{ }} is an @@DEPLOY_<NAME>@@ placeholder, fed by the step env: DEPLOY_<NAME> and
substituted as plain text in one pass, as GitHub did, so the Pi receives the same bytes as the
old inline heredoc. Exits non-zero (nothing sent) on a missing template or unknown placeholder.
"""
import os
import re
import sys

# Every value the remote script receives; an unset one renders as "", like an empty ${{ }}.
PLACEHOLDERS = (
    "TARGET_SHA",
    "RELEASE_TRAIN",
    "ADMISSIONS_FUNNEL_V1",
    "COURSE_SESSION_CALENDAR_V1",
    "COURSE_MANAGER_V1",
    "POP_INAPP_STANDING_POLICY",
    "SENTRY_DSN",
    "SMOKE_TEACHER_LOGIN",
    "SMOKE_TEACHER_PASSWORD",
    "SMOKE_BRANCH_ID",
)
PATTERN = re.compile(r"@@DEPLOY_([A-Z0-9_]+)@@")
# Feature-flag modes are pasted into the script's *_FLAG_MODE="..." lines: only these values may be.
FLAG_PLACEHOLDERS = ("ADMISSIONS_FUNNEL_V1", "COURSE_SESSION_CALENDAR_V1", "COURSE_MANAGER_V1", "POP_INAPP_STANDING_POLICY")
FLAG_VALUES = ("", "unchanged", "on", "off")


def render(template, environ):
    unknown = sorted({name for name in PATTERN.findall(template) if name not in PLACEHOLDERS})
    if unknown:
        raise ValueError(f"unknown placeholder(s): {', '.join(unknown)}")
    invalid = [name for name in FLAG_PLACEHOLDERS if environ.get(f"DEPLOY_{name}", "") not in FLAG_VALUES]
    if invalid:
        raise ValueError(f"feature-flag value not unchanged/on/off: {', '.join(invalid)}")
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
