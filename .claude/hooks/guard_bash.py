#!/usr/bin/env python3
"""PreToolUse hook for the Bash tool: AST-based, fails closed.

The command is parsed by `shfmt --to-json` (mvdan/sh, pinned by
scripts/install-shfmt.sh). Every simple command in the tree (including ones
nested in $(), backticks, <(), subshells, control flow, pipelines and
`bash -c`/`eval` payloads) is checked semantically on its argv, so quoting,
`git -C`, wrappers and free-text commit messages no longer matter.

Fail closed: parse error, shfmt timeout, any exception => deny. If shfmt is
not installed (e.g. cloud session) the old regex guard (guard_bash_regex.py)
is used instead. shfmt lookup: $ALLTRUE_SHFMT (if set, only that), PATH,
.claude/hooks/bin/shfmt.

Not a sandbox: server-side controls (GitHub ruleset, production-activation
environment) remain the real boundary. Known gaps: `xargs`/`find -exec` fed
from stdin, ssh host aliases from ~/.ssh/config, bash/sh reading a script
from a pipe is denied but other interpreters are only regex-scanned.
"""
import contextlib
import io
import json
import os
import re
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
MAX_COMMAND_CHARS = 12_000
MAX_DEPTH = 3
MAX_SCRIPTS = 20
DYN = "\x00"  # placeholder for a non-literal word part

SHELLS = {"bash", "sh", "zsh", "dash", "source", "."}
INTERP = re.compile(r"(?:awk|gawk|mawk|perl|ruby|node|nodejs|php|lua|deno|bun|python[\d.]*)$")
READERS = set("cat less more head tail sed awk grep rg base64 xxd od strings nl tac cut sort jq tee cp scp rsync curl".split())
SSH_FAMILY = {"ssh", "scp", "sftp", "rsync"}
VIEWERS_N = {"-n"}  # `bash -n script` only parses
PI_RE = re.compile(r"(?i)pi\.lifenet\.com\.tw|\bPI_(?:SSH_)?(?:HOST|USER)\b")
SECRET_OK = {".env.example", ".env.sample", ".env.template", ".env.dist"}
# wrapper -> options that take a value
WRAPPERS = {
    "command": set(), "exec": set(), "builtin": set(), "nohup": set(), "time": set(),
    "setsid": set(), "env": {"-u", "-C", "-S"}, "nice": {"-n"}, "timeout": {"-s", "-k"},
    "sudo": set("-u -g -h -p -C -D -R -T -U".split()), "stdbuf": {"-i", "-o", "-e"},
    "ionice": {"-c", "-n", "-p"}, "local-heavy-gate": set(),
    "xargs": set("-I -n -P -d -E -L -s -a".split()),
}
GIT_OPT_VALUE = {"--git-dir", "--work-tree", "--namespace", "--exec-path", "--super-prefix", "--config-env"}


class Deny(Exception):
    pass


class W:
    """A shell word: text with DYN where non-literal, dynamic flag, raw JSON."""
    __slots__ = ("text", "dyn", "raw")

    def __init__(self, node):
        self.raw = json.dumps(node)
        self.text, self.dyn = "", False
        for p in node.get("Parts", []):
            t = p.get("Type")
            if t == "Lit":
                v = re.sub(r"\\(.)", r"\1", p["Value"], flags=re.S)
                if re.search(r"\{[^}]*(,|\.\.)[^}]*\}", v):  # brace expansion
                    self.dyn = True
                self.text += v
            elif t == "SglQuoted" and not p.get("Dollar"):
                self.text += p["Value"]
            elif t == "DblQuoted" and all(q.get("Type") == "Lit" for q in p.get("Parts", [])):
                self.text += "".join(re.sub(r"\\(.)", r"\1", q["Value"], flags=re.S) for q in p.get("Parts", []))
            else:
                self.dyn, self.text = True, self.text + DYN


def base(w):
    return os.path.basename(w.text)


def find_shfmt():
    env = os.environ.get("ALLTRUE_SHFMT")
    cands = [env] if env else [shutil.which("shfmt"), os.path.join(HERE, "bin", "shfmt")]
    for c in cands:
        if c and os.path.isfile(c) and os.access(c, os.X_OK):
            return c
    return None


def parse(shfmt, cmd):
    try:
        r = subprocess.run([shfmt, "-ln", "bash", "--to-json"], input=cmd, capture_output=True,
                           text=True, timeout=3)
    except subprocess.TimeoutExpired:
        raise Deny("shfmt timed out parsing the command")
    if r.returncode != 0:
        raise Deny("cannot parse command (fail closed): " + r.stderr.strip()[:200])
    return json.loads(r.stdout)


def is_secret(path, cwd):
    p = os.path.expanduser(path)
    cands = [p]
    full = p if os.path.isabs(p) else os.path.join(cwd, p)
    try:
        if os.path.exists(full) or os.path.islink(full):
            cands.append(os.path.realpath(full))
    except OSError:
        pass
    for c in cands:
        parts = c.split("/")
        b = parts[-1]
        if (b.endswith(".env") or b.startswith(".env.") and b not in SECRET_OK or b.endswith(".pem")
                or b.startswith("id_rsa") or b == "credentials.json"
                or ".ssh" in parts[:-1] or ".aws" in parts[:-1]):
            return True
    return False


def secret_arg(text, cwd):
    # also catches `file=@.env` / `--file=.env`
    return any(is_secret(v, cwd) for v in {text, text.split("=", 1)[-1], text.split("@", 1)[-1]} if v)


def unwrap(argv):
    while argv:
        if argv[0].dyn:
            raise Deny("cannot verify dynamic command name")
        n = base(argv[0])
        if n not in WRAPPERS:
            return argv
        i = 1
        if n == "local-heavy-gate":
            while i < len(argv) and argv[i].text != "--":
                i += 1
            i += 1
        else:
            while i < len(argv) and (argv[i].text.startswith("-") or n == "env" and "=" in argv[i].text):
                if argv[i].text == "--":
                    i += 1
                    break
                i += 2 if argv[i].text in WRAPPERS[n] else 1
            if n == "timeout":
                i += 1  # duration
        argv = argv[i:]
    return argv


def abbr(a, full, minlen=4):
    n = a.split("=", 1)[0]
    return a.startswith("--") and len(n) >= minlen and full.startswith(n)


def shorts(a):
    return a[1:] if re.match(r"-[A-Za-z]+$", a) else ""


def branch_of(d):
    for args in (["symbolic-ref", "--short", "HEAD"], ["rev-parse", "--abbrev-ref", "HEAD"]):
        try:
            r = subprocess.run(["git", "-C", d] + args, capture_output=True, text=True, timeout=5)
            b = r.stdout.strip()
            if r.returncode == 0 and b and b != "HEAD":
                return b
        except Exception:
            continue
    return ""


def check_git(args, cwd):
    i, dirs = 0, []
    while i < len(args):
        a = args[i].text
        if a == "-C" and i + 1 < len(args):
            dirs.append(args[i + 1])
            i += 2
        elif a == "-c" and i + 1 < len(args):
            if args[i + 1].text.lower().startswith("alias.") or args[i + 1].dyn:
                raise Deny("git -c alias/dynamic config can hide a subcommand")
            i += 2
        elif a in GIT_OPT_VALUE:
            i += 2
        elif a.startswith("-"):
            i += 1
        else:
            break
    if i >= len(args):
        return
    if args[i].dyn:
        raise Deny("cannot verify dynamic git subcommand")
    sub, rest = args[i].text, args[i + 1:]
    texts = [w.text for w in rest]
    if sub in ("push", "reset", "clean", "branch") and any(w.dyn for w in rest):
        raise Deny(f"cannot verify dynamic arguments to git {sub} (fail closed)")
    if sub == "commit":
        d = cwd
        for w in dirs:
            if w.dyn:
                raise Deny("cannot verify dynamic git -C directory for commit")
            d = os.path.join(d, os.path.expanduser(w.text))
        if branch_of(d) in ("main", "master"):
            raise Deny(f"direct commit on '{branch_of(d)}'. Branch first - see CLAUDE.md Git rules.")
        for j, t in enumerate(texts[:-1]):
            if t in ("-F", "--file") and secret_arg(texts[j + 1], cwd) or \
                    t.startswith("--file=") and secret_arg(t, cwd):
                raise Deny("a credential-shaped file would be published as a commit message.")
    elif sub == "push":
        pos = []
        for t in texts:
            c = shorts(t)
            if abbr(t, "--force") or t.startswith("--force-") or "f" in c:
                raise Deny("force push. Forbidden without explicit Founder approval (CLAUDE.md).")
            if abbr(t, "--delete") or abbr(t, "--prune") or abbr(t, "--mirror") or "d" in c:
                raise Deny("remote branch/ref deletion via push. Forbidden without Founder approval.")
            if not t.startswith("-"):
                pos.append(t)
        for t in pos:
            if t.startswith("+"):
                raise Deny("force push (+refspec). Forbidden without explicit Founder approval.")
            if t.startswith(":"):
                raise Deny("remote ref deletion via push (:ref). Forbidden without Founder approval.")
            dst = t.rsplit(":", 1)[-1]
            dst = dst[len("refs/heads/"):] if dst.startswith("refs/heads/") else dst
            if dst in ("main", "master"):
                raise Deny("push to main/master. Push a feature branch and open a PR (CLAUDE.md R3).")
    elif sub == "reset":
        if any(abbr(t, "--hard") for t in texts):
            raise Deny("git reset --hard. Forbidden - see CLAUDE.md.")
    elif sub == "clean":
        if any(set(shorts(t)) & set("fd") or abbr(t, "--force") for t in texts):
            raise Deny("git clean. Forbidden - never discard untracked work (CLAUDE.md).")
    elif sub == "branch":
        cl = [shorts(t) for t in texts]
        force = any("f" in c for c in cl) or any(abbr(t, "--force") for t in texts)
        delete = any("d" in c for c in cl) or any(abbr(t, "--delete") for t in texts)
        if any("D" in c for c in cl) or (force and delete):
            raise Deny("force branch delete. Forbidden without explicit Founder approval.")


def check_gh(args):
    t = [w.text for w in args]
    if t[:2] == ["pr", "merge"] and any(abbr(a, "--admin") for a in t):
        raise Deny("gh pr merge --admin bypasses required checks. Forbidden (AGENTS.md).")
    if t[:2] == ["workflow", "run"] and any("deploy" in a.lower() for a in t[2:]):
        raise Deny("gh workflow run deploy*: production deploy needs explicit Founder approval.")
    for j, a in enumerate(t[:-1]):
        if a in ("-F", "--body-file", "--file") and secret_arg(t[j + 1], os.getcwd()) or \
                a.startswith(("--body-file=", "--file=")) and secret_arg(a, os.getcwd()):
            raise Deny("a credential-shaped file would be published as a PR/issue body.")


def check_deploy(n, argv):
    t = [w.text for w in argv[1:]]
    hit = (
        n == "terraform" and "apply" in t or n == "kubectl" and "apply" in t
        or n == "vercel" and any(a == "--prod" or a.startswith("--prod=") for a in t)
        or n == "supabase" and "db" in t and "push" in t[t.index("db"):]
        or n == "prisma" and "migrate" in t and "deploy" in t
        or n in ("npm", "yarn", "pnpm") and "deploy" in t
        or n == "make" and any(a in ("deploy", "release") for a in t)
        or (n == "php" and any(base(w) == "artisan" for w in argv[1:]) or n == "artisan")
        and any(a.startswith("migrate") for a in t) and any(a == "--force" or a.startswith("--force=") for a in t)
    )
    if hit:
        raise Deny("production deploy/migration command. Requires explicit Founder approval "
                   "(CLAUDE.md, governance/AUTONOMY_POLICY.md).")


def regex_scan(text):
    """Run the legacy regex guard over free-form code embedded in interpreter args."""
    import guard_bash_regex as rx
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        try:
            rx.check_all(text)
        except SystemExit:
            pass
    if buf.getvalue():
        raise Deny("(regex) " + json.loads(buf.getvalue())["hookSpecificOutput"]["permissionDecisionReason"])


class Walker:
    def __init__(self, shfmt):
        self.shfmt = shfmt
        self.scripts = []  # (path, cwd)

    def run(self, cmd, cwd, depth=0):
        if depth > MAX_DEPTH:
            raise Deny("shell payload nested too deeply to verify")
        self.walk(parse(self.shfmt, cmd), cwd, depth)

    def walk(self, n, cwd, depth):
        if isinstance(n, list):
            for x in n:
                cwd = self.walk(x, cwd, depth)
            return cwd
        if not isinstance(n, dict):
            return cwd
        for k, v in n.items():
            if k == "Redirs":
                for r in v:
                    self.redirect(r, cwd)
            cwd = self.walk(v, cwd, depth)
        if n.get("Type") == "CallExpr" and n.get("Args"):
            cwd = self.call(n, cwd, depth) or cwd
        return cwd

    def redirect(self, r, cwd):
        if r.get("Op") not in ("<<", "<<-", "<<<") and r.get("Word"):
            w = W(r["Word"])
            if secret_arg(w.text, cwd):
                raise Deny(f"shell redirection {r['Op']} touches a credential-shaped file (AGENTS.md RULE-SEC-001).")

    def call(self, n, cwd, depth):
        argv = unwrap([W(a) for a in n["Args"]])
        if not argv:
            return None
        name, args = base(argv[0]), argv[1:]
        if name == "cd":
            return os.path.join(cwd, os.path.expanduser(args[0].text)) if args and not args[0].dyn else None
        if name in SSH_FAMILY:
            if any(PI_RE.search(w.raw) for w in args):
                raise Deny("SSH/copy to the production Pi. Forbidden for agents - all changes go "
                           "branch -> PR -> CI -> deploy.yml (CLAUDE.md R2/R6).")
            if any(w.dyn for w in args):
                raise Deny(f"cannot verify dynamic arguments to {name} (fail closed)")
        if name == "git":
            check_git(args, cwd)
        elif name == "gh":
            check_gh(args)
        check_deploy(name, argv)
        if name in READERS:
            for w in args:
                if (not w.text.startswith("-") or "=" in w.text) and secret_arg(w.text, cwd):
                    raise Deny(f"{name} reads/copies a credential-shaped file (AGENTS.md RULE-SEC-001).")
        if INTERP.match(name):
            regex_scan(" ".join(w.text for w in argv))
        if name == "eval":
            if any(w.dyn for w in args):
                raise Deny("cannot verify dynamic eval payload")
            self.run(" ".join(w.text for w in args), cwd, depth + 1)
        if name in SHELLS:
            self.shell(name, args, cwd, depth)
        elif "/" in argv[0].text and re.search(r"\.(sh|bash)$", argv[0].text):
            self.script(argv[0].text, cwd)
        if re.search(r"deploy[^/]*\.(sh|bash|py|rb|js)$", argv[0].text if "/" in argv[0].text else "", re.I):
            raise Deny("deploy script. Requires explicit Founder approval (CLAUDE.md).")
        return None

    def shell(self, name, args, cwd, depth):
        flags = [w.text for w in args if re.match(r"-[A-Za-z]+$", w.text)]
        pos = [w for w in args if not w.text.startswith("-")]
        if name in ("source", "."):
            pos, flags = pos[:1], []
        elif any("c" in f for f in flags):
            if not pos or pos[0].dyn:
                raise Deny("cannot verify dynamic shell -c payload (fail closed)")
            self.run(pos[0].text, cwd, depth + 1)
            return
        if "-n" in flags:
            return
        if not pos:
            raise Deny("shell reading commands from stdin cannot be verified (fail closed)")
        for w in pos[:1]:
            if re.search(r"deploy[^/]*\.(sh|bash|py|rb|js)$", w.text, re.I):
                raise Deny("deploy script. Requires explicit Founder approval (CLAUDE.md).")
        for w in args:  # first positional is the script; any *.sh arg is checked too
            if w is pos[0] or re.search(r"\.(sh|bash)$", w.text):
                self.script(w.text, cwd)

    def script(self, path, cwd):
        self.scripts.append((path, cwd))
        if len(self.scripts) > MAX_SCRIPTS:
            raise Deny("too many scripts to verify inside the hook timeout (fail closed)")
        for c in {os.path.join(cwd, path), os.path.join(os.getcwd(), path)}:
            try:
                with open(c, encoding="utf-8", errors="ignore") as fh:
                    body = fh.read(200_000)
            except OSError:
                continue
            if re.search(r"(?i)pi\.lifenet\.com\.tw", body) and re.search(r"\b(?:ssh|scp|rsync)\b", body):
                raise Deny(f"script {path} targets the production Pi (CLAUDE.md R2/R6).")


def emit_deny(reason):
    out = json.dumps({"hookSpecificOutput": {"hookEventName": "PreToolUse",
                                             "permissionDecision": "deny",
                                             "permissionDecisionReason": reason}})
    try:
        print(out)
        sys.stdout.flush()
    except Exception:
        sys.exit(2)
    sys.exit(0)


def main():
    try:
        cmd = (json.load(sys.stdin).get("tool_input") or {}).get("command", "") or ""
        if not cmd:
            return
        if len(cmd) > MAX_COMMAND_CHARS:
            raise Deny(f"command is {len(cmd)} chars, over the guard's {MAX_COMMAND_CHARS}-char limit. "
                       "Put long text in a file (--body-file / -F) and pass the path.")
        shfmt = find_shfmt()
        if shfmt is None:
            sys.stderr.write("guard_bash: shfmt not found (scripts/install-shfmt.sh); using regex fallback\n")
            import guard_bash_regex as rx
            rx.check_all(re.sub(r"\\\r?\n", " ", cmd))
            return
        Walker(shfmt).run(cmd, os.getcwd())
    except Deny as e:
        emit_deny(f"Blocked (AST guard): {e}")
    except SystemExit:
        raise
    except BaseException as e:  # fail closed
        emit_deny(f"Blocked (AST guard): internal error, failing closed: {type(e).__name__}: {e}")


if __name__ == "__main__":
    main()
