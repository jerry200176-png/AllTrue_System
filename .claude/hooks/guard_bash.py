#!/usr/bin/env python3
"""PreToolUse hook for the Bash tool.

Reads the hook JSON payload on stdin, decides allow/deny for
destructive-git / production-deploy / credential-leak shaped commands, and
prints a PreToolUse decision JSON to stdout. Exits 0 either way (the JSON
decision is what blocks, not the exit code) so a malformed payload never
crashes the session.

This is a textual guardrail against ordinary mistakes and casual bypass
attempts (bash -c wrapping, git -C, chained commands, python/node
subprocess literals, etc.) — ported from portfolio-ops .claude/hooks (see its
docs/hook-threat-model.md) plus an AllTrue production-host rule. It is not a sandbox.
New text-matching bypasses are expected; server-side controls (GitHub ruleset,
production-activation environment) remain the real boundary.

Can be invoked standalone for testing:
  echo '{"tool_name":"Bash","tool_input":{"command":"git status"}}' | python3 guard_bash.py
Or run the regression suite:
  python3 test_guard_bash.py
"""
import json
import os
import re
import subprocess
import sys

# Statement separators we treat as ending a "unit" a dangerous pattern must
# stay within. Deliberately does not attempt real shell parsing (quotes,
# subshells) — see the module docstring.
SEP = r"[;&|\n]"

# Gap between a keyword and the next: matches any run of characters that is
# NOT a statement separator. This absorbs flags (`git -C /path push`),
# punctuation from other languages embedding the tokens (`"git","push"`
# inside a Python list literal), and plain whitespace uniformly, so we
# don't have to assume a token is immediately followed by the next one.
GAP = rf"(?:(?!{SEP}).)*?"

# Statement start: beginning of the string, right after a separator, or
# after `then` — plus tolerated no-op prefixes (env assignments, sudo,
# command, exec, time, nice, nohup) before the "real" program name. Used to
# anchor patterns built on words that are also plausible in ordinary English
# text (e.g. "deploy", "make", "release") so a commit message mentioning
# them isn't denied — only an actual invocation is.
# One alternative per token, so there is only one way to match a prefix run
# (overlapping alternatives backtrack exponentially and time the hook out = allow).
_PREFIX = r"(?:\b(?:sudo|env|command|exec|time|nice|nohup|[^\s=;&|]+=\S*)\s+)*"
ANCHOR = rf"(?:^|{SEP}|\bthen\b)\s*{_PREFIX}"

# bash/sh/zsh/dash -c "..." / -lc '...' wrapper: extracts the quoted payload
# so it can be re-checked as its own command, since ANCHOR-based patterns
# would otherwise not see past the opening quote.
_WRAPPER_RE = re.compile(
    r"\b(?:bash|sh|zsh|dash)\s+-\w*c\w*\s+(['\"])(.*?)(?<!\\)\1", re.DOTALL
)

MAX_UNWRAP_DEPTH = 3

# A hook that times out is treated as "allow", and some patterns here are
# quadratic. Refuse commands too long to scan well inside the 10s hook timeout
# (12k chars worst case is about 1s). Long text belongs in --body-file / -F.
MAX_COMMAND_CHARS = 12_000

# Descriptive-text-only flags: their entire purpose is free-form human text
# (commit message, PR/issue body/title) — never a path, ref, or nested
# command. Scoped narrowly on purpose: this is NOT "any quoted string" or
# "any heredoc" (that would let e.g. `bash -c "$(cat <<'EOF' ... EOF)"`
# smuggle a real command past the scan) — only text captured specifically
# as the value of one of these flags is treated as non-executed.
_DESC_FLAGS = r"(?:--body|--title|--description|--message|-m)"

# Case 1: `<flag> "...text..."` — plain quoted string.
_DESC_QUOTED_RE = re.compile(
    rf"{_DESC_FLAGS}(\s*=?\s*)(['\"])((?:\\.|(?!\2).)*)\2", re.DOTALL
)

# Case 2: `<flag> "$(cat <<'EOF' ... EOF)"` — the heredoc-via-command-
# substitution shape used by this project's own commit/PR conventions.
# Requires the flag to directly precede `$(cat <<DELIM`, so a heredoc used
# for any other purpose (e.g. actually piped into a shell) is untouched.
_DESC_HEREDOC_RE = re.compile(
    rf"{_DESC_FLAGS}\s*=?\s*(['\"]?)\$\(\s*cat\s+<<-?\s*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\2[ \t]*\r?\n"
    r"(.*?\r?\n)"
    r"[ \t]*\3\s*\)\1",
    re.DOTALL,
)


_SUBST_RE = re.compile(r"\$\(|`")


def _blank(s: str) -> str:
    return re.sub(r"[^\n]", " ", s)


def _strip_non_executed_text(cmd: str) -> str:
    """Blank out free-form descriptive text (commit messages, PR/issue
    bodies/titles) passed to -m/--body/--title/etc., including the
    heredoc-via-$(cat <<EOF) shape this project's own git conventions use,
    before dangerous-pattern scanning. Preserves string length/newlines so
    match spans elsewhere in `cmd` stay meaningful. Deliberately narrow —
    see module docstring on why full shell parsing is out of scope."""

    def _blank_heredoc(m: "re.Match") -> str:
        if not m.group(2) and _SUBST_RE.search(m.group(4)):
            return m.group(0)  # unquoted delimiter: $(...) / backticks still execute
        return m.group(0)[: m.start(4) - m.start(0)] + _blank(m.group(4)) + \
            m.group(0)[m.end(4) - m.start(0):]

    cmd = _DESC_HEREDOC_RE.sub(_blank_heredoc, cmd)

    def _blank_quoted(m: "re.Match") -> str:
        if m.group(2) == '"' and _SUBST_RE.search(m.group(3)):
            return m.group(0)  # "$(...)" / backticks execute inside double quotes
        return m.group(0)[: m.start(3) - m.start(0)] + _blank(m.group(3)) + \
            m.group(0)[m.end(3) - m.start(0):]

    cmd = _DESC_QUOTED_RE.sub(_blank_quoted, cmd)
    return cmd


def deny(reason: str) -> None:
    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": reason,
        }
    }))
    sys.exit(0)


def allow() -> None:
    # No output = no opinion = allowed to proceed to normal permission flow.
    sys.exit(0)


def current_branch() -> str:
    # symbolic-ref works even on an unborn branch (no commits yet);
    # rev-parse --abbrev-ref HEAD can misreport "HEAD" in that case.
    for args in (["git", "symbolic-ref", "--short", "HEAD"],
                 ["git", "rev-parse", "--abbrev-ref", "HEAD"]):
        try:
            out = subprocess.run(args, capture_output=True, text=True, timeout=5)
            branch = out.stdout.strip()
            if out.returncode == 0 and branch and branch != "HEAD":
                return branch
        except Exception:
            continue
    return ""


SECRET_FILE_RE = re.compile(
    r"(\.env(?!\.(?:example|sample|template|dist)(?![.\w]))(?:\.[A-Za-z]+)?\b|[^;&|\s\"']*\.pem\b|\bid_rsa(?:\.\w+)?\b|"
    r"\bcredentials\.json\b|\.ssh/[A-Za-z0-9_.-]*\b|\.aws/[A-Za-z0-9_.-]*\b)"
)


def check_git_patterns(cmd: str) -> None:
    # Deliberately NOT anchored to a statement boundary: "git" subcommand
    # shapes are specific enough (and false negatives here are worse than
    # the rare false positive of a commit message literally containing
    # e.g. "git push --force") that we search anywhere in the command.
    # See the module docstring for the tradeoff.

    # 1. direct commit on main/master (flags between "git" and "commit"
    #    tolerated, e.g. `git -C /path commit`, `git --no-pager commit`).
    if re.search(rf"\bgit\b{GAP}\bcommit\b", cmd):
        branch = current_branch()
        if branch in ("main", "master"):
            deny(
                f"Blocked: direct commit on '{branch}'. Branch first — "
                "see CLAUDE.md Git rules."
            )

    # 2. force push
    force_flag = r'(--force\b|--force-with-lease\b|(?:^|[\s,"\'])-[a-zA-Z]*f[a-zA-Z]*(?:[\s,"\']|$))'
    if re.search(rf"\bgit\b{GAP}\bpush\b{GAP}{force_flag}", cmd) or \
            re.search(rf"\bgit\b{GAP}\bpush\b{GAP}\s['\"]?\+[^\s;&|]", cmd):
        deny(
            "Blocked: force push. Forbidden without explicit Founder "
            "approval this session (CLAUDE.md)."
        )

    # 2b. direct push to main/master (R3: feature branch + PR only)
    if re.search(rf"\bgit\b{GAP}\bpush\b{GAP}[\s:]['\"]?(?:refs/heads/)?(?:main|master)\b(?![-/.\w])", cmd):
        deny("Blocked: push to main/master. Push a feature branch and open a PR (CLAUDE.md R3).")

    # 2c. admin merge bypasses required checks (AGENTS.md machine ban on --admin)
    if re.search(rf"\bgh\b{GAP}\bpr\b{GAP}\bmerge\b{GAP}--admin\b", cmd):
        deny("Blocked: gh pr merge --admin bypasses required checks. Forbidden (AGENTS.md).")

    # 3. git reset --hard
    if re.search(rf"\bgit\b{GAP}\breset\b{GAP}--hard\b", cmd):
        deny("Blocked: git reset --hard. Forbidden — see CLAUDE.md.")

    # 4. git clean with -f/-d
    if re.search(rf"\bgit\b{GAP}\bclean\b{GAP}-[a-zA-Z]*[fd]", cmd):
        deny(
            "Blocked: git clean. Forbidden — never discard untracked work "
            "(CLAUDE.md)."
        )

    # 5. force branch delete / remote ref deletion
    if re.search(rf"\bgit\b{GAP}\bbranch\b{GAP}-[a-zA-Z]*D\b", cmd) or (
            re.search(rf"\bgit\b{GAP}\bbranch\b{GAP}--delete\b", cmd)
            and re.search(rf"\bgit\b{GAP}\bbranch\b{GAP}(--force\b|\s-f\b)", cmd)) or (
            re.search(rf"\bgit\b{GAP}\bbranch\b{GAP}\s-[a-zA-Z]*d", cmd)
            and re.search(rf"\bgit\b{GAP}\bbranch\b{GAP}(--force\b|\s-[a-zA-Z]*f)", cmd)):
        deny(
            "Blocked: force branch delete. Forbidden without explicit "
            "Founder approval."
        )
    if re.search(
        rf"\bgit\b{GAP}\bpush\b{GAP}(--delete\b|--prune\b|--mirror\b|\s-d\b|\s:[A-Za-z0-9/_.-]+(?:\s|$))",
        cmd,
    ):
        deny(
            "Blocked: remote branch/ref deletion via push. Forbidden "
            "without explicit Founder approval."
        )


def check_deploy_patterns(cmd: str) -> None:
    # Anchored to statement start: these use words ("make", "deploy",
    # "release") that are common in ordinary English (commit messages, PR
    # text), so we only flag them as an actual command invocation, not
    # free text. bash -c wrapping is still caught via the unwrap pass in
    # main().
    deploy_patterns = [
        rf"{ANCHOR}vercel\b{GAP}--prod\b",
        rf"{ANCHOR}terraform\b{GAP}\bapply\b",
        rf"{ANCHOR}supabase\b{GAP}\bdb\b{GAP}\bpush\b",
        rf"{ANCHOR}prisma\b{GAP}\bmigrate\b{GAP}\bdeploy\b",
        rf"(?:{ANCHOR}php\b{GAP}|{ANCHOR}\S*/)artisan\b{GAP}\bmigrate\S*{GAP}--force\b",
        rf"{ANCHOR}kubectl\b{GAP}\bapply\b",
        rf"{ANCHOR}gh\b{GAP}\bworkflow\b{GAP}\brun\b{GAP}deploy",
        rf"{ANCHOR}(npm|yarn|pnpm)\b{GAP}\brun\b{GAP}\bdeploy\b",
        # generic script/target invocation shapes — best-effort, not exhaustive
        rf"{ANCHOR}make\b[ \t]+(?:-\S+[ \t]+)*\b(deploy|release)\b",
        rf"{ANCHOR}\./\S*deploy\S*\.(sh|bash|py|rb|js)\b",
        rf"{ANCHOR}(sh|bash|zsh)\b\s+\S*deploy\S*\.(sh|bash)\b",
    ]
    for pat in deploy_patterns:
        if re.search(pat, cmd):
            deny(
                "Blocked: production deploy/migration command (or a script "
                "shaped like one). Requires explicit Founder approval this "
                "session (CLAUDE.md, governance/AUTONOMY_POLICY.md)."
            )


def check_credential_leak(cmd: str) -> None:
    read_verbs = r"(cat|less|more|head|tail|sed|awk|grep|rg|base64|xxd|od|strings|nl|tac|cut|sort|jq)\b"
    if re.search(rf"{ANCHOR}{read_verbs}{GAP}{SECRET_FILE_RE.pattern}", cmd):
        deny(
            "Blocked: command outputs a credential-shaped file. If this is "
            "legitimate, read it deliberately and never paste the value "
            "into chat/logs (AGENTS.md RULE-SEC-001)."
        )
    if re.search(rf"{ANCHOR}(curl|scp|rsync)\b{GAP}{SECRET_FILE_RE.pattern}", cmd):
        deny("Blocked: command appears to transfer a credential-shaped file externally.")

    # Symlink / indirection guard: resolve file-reading commands' path
    # arguments and check the *resolved* target, not just the literal
    # argument text — catches `cat innocuous_name` where innocuous_name is
    # a symlink to .env etc.
    for segment in re.split(SEP, cmd):
        segment = segment.strip()
        if not segment:
            continue
        tokens = segment.split()
        if not tokens or tokens[0] not in ("cat", "head", "tail", "less", "more", "sed", "awk",
                                           "grep", "rg", "base64", "xxd", "od", "strings", "nl", "tac"):
            continue
        for tok in tokens[1:]:
            if tok.startswith("-"):
                continue
            candidate = os.path.expanduser(tok)
            try:
                if not os.path.exists(candidate) and not os.path.islink(candidate):
                    continue
                resolved = os.path.realpath(candidate)
            except Exception:
                continue
            if SECRET_FILE_RE.search(resolved):
                deny(
                    "Blocked: path resolves (possibly via symlink) to a "
                    f"credential-shaped file: {os.path.basename(resolved)}. "
                    "See AGENTS.md RULE-SEC-001."
                )


# AllTrue: the Raspberry Pi is production. Agents never SSH/copy to it
# (CLAUDE.md R2/R6, incident C: `php artisan test` on the Pi wiped prod DB).
PI_TARGET = r"(?i:pi\.lifenet\.com\.tw\b|\$\{?PI_(?:SSH_)?(?:HOST|USER)\b)"


def _script_targets_pi(cmd: str) -> bool:
    # Any segment may run a script (bash -x, timeout, wrappers...), except ones
    # whose program only views files.
    viewers = {"git", "sed", "cat", "less", "more", "head", "tail", "grep", "rg",
               "diff", "wc", "ls", "stat", "file", "jq", "nl", "tac",
               "shellcheck", "chmod"}
    toks = []
    for seg in re.split(SEP, cmd):
        words = seg.split()
        if words and (words[0] in viewers or re.match(r"(?:bash|sh|zsh)\s+-n\b", seg.strip())):
            continue
        toks += re.findall(r"[\w./-]+\.(?:sh|bash)\b", seg)
    for tok in toks:
        try:
            with open(tok, encoding="utf-8", errors="ignore") as fh:
                body = fh.read(200_000)
        except OSError:
            continue
        if re.search(r"(?i)pi\.lifenet\.com\.tw", body) and re.search(r"\b(?:ssh|scp|rsync)\b", body):
            return True
    return False


def check_production_host(cmd: str) -> None:
    if re.search(rf"\b(?:ssh|scp|sftp|rsync)\b{GAP}{PI_TARGET}", cmd) or _script_targets_pi(cmd):
        deny(
            "Blocked: SSH/copy to the production Pi. Forbidden for agents — "
            "all changes go branch -> PR -> CI -> deploy.yml (CLAUDE.md R2/R6)."
        )


def check_redirect_read(cmd: str) -> None:
    # `< .env`, `$(<.env)`: the shell itself reads the file, whatever the command.
    if re.search(rf"<\s*['\"]?[^\s;&|'\"()]*{SECRET_FILE_RE.pattern}", cmd):
        deny("Blocked: shell redirection reads a credential-shaped file (AGENTS.md RULE-SEC-001).")


def check_file_flags(cmd: str) -> None:
    # git commit -F <file> / gh --body-file <file> publish the file's contents.
    if re.search(rf"(?:\s-F|--body-file|--file)\s*=?\s*['\"]?[^\s;&|'\"]*{SECRET_FILE_RE.pattern}", cmd):
        deny("Blocked: a credential-shaped file would be published as a commit message or PR/issue body.")


def check_all(cmd: str, depth: int = 0) -> None:
    scan_cmd = _strip_non_executed_text(cmd)
    check_git_patterns(scan_cmd)
    check_deploy_patterns(scan_cmd)
    check_credential_leak(scan_cmd)
    check_production_host(scan_cmd)
    check_file_flags(scan_cmd)
    check_redirect_read(scan_cmd)

    if depth >= MAX_UNWRAP_DEPTH:
        return
    # Unwrap against the ORIGINAL (unstripped) cmd — a real bash -c payload
    # is never itself the value of a -m/--body/--title flag, so stripping
    # has no legitimate reason to touch it, and unwrapping the stripped
    # version would risk missing a wrapper whose quotes happened to look
    # like a descriptive flag's.
    for m in _WRAPPER_RE.finditer(cmd):
        inner = m.group(2)
        if inner:
            check_all(inner, depth + 1)


def main() -> None:
    try:
        payload = json.load(sys.stdin)
    except Exception:
        allow()
        return

    cmd = (payload.get("tool_input") or {}).get("command", "") or ""
    if not cmd:
        allow()
        return

    # Join backslash-newline line continuations so a genuinely multi-line
    # command isn't split into separate "statements" that individually
    # look safe.
    cmd = re.sub(r"\\\r?\n", " ", cmd)

    if len(cmd) > MAX_COMMAND_CHARS:
        deny(
            f"Blocked: command is {len(cmd)} chars, over the guard's {MAX_COMMAND_CHARS}-char "
            "scan limit. Put long text in a file (--body-file / -F) and pass the path."
        )

    check_all(cmd)

    allow()


if __name__ == "__main__":
    main()
