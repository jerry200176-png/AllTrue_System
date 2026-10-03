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
import glob as globmod
import io
import json
import os
import re
import shlex
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
MAX_COMMAND_CHARS = 12_000
MAX_DEPTH = 3
MAX_SCRIPTS = 20
DYN = "\x00"  # placeholder for a non-literal word part

SHELLS = {"bash", "sh", "zsh", "dash", "ksh", "mksh", "yash", "fish", "source", "."}
INTERP = re.compile(r"(?:awk|gawk|mawk|perl|ruby|node|nodejs|php|lua|deno|bun|python[\d.]*)$")
READERS = set("cat less more head tail sed awk grep rg base64 xxd od strings nl tac cut sort jq tee cp scp rsync curl".split())
SSH_FAMILY = {"ssh", "scp", "sftp", "rsync"}
# Programs whose argv is fully covered by semantic rules (or inert); every other program also gets the
# legacy regex scan over its argv (defense in depth for unknown executors).
SAFE_CMDS = set("echo printf true false test [ : cd pwd ls date sleep wc mkdir touch head tail cat grep rg "
                "diff stat file".split())
KNOWN_GIT = set("commit push reset clean branch config status log diff add fetch pull checkout switch show merge "
                "rebase stash tag remote rev-parse ls-files worktree restore mv rm init clone cherry-pick describe "
                "grep blame apply am bisect rev-list show-ref symbolic-ref ls-remote cat-file shortlog submodule difftool "
                "filter-branch ls-tree diff-tree merge-base name-rev reflog fsck gc prune repack pack-refs update-ref "
                "for-each-ref hash-object mktree read-tree write-tree commit-tree archive bundle notes format-patch "
                "revert sparse-checkout maintenance count-objects check-ignore check-attr var version help range-diff "
                "whatchanged verify-commit verify-tag".split())
KNOWN_GH = set("pr issue repo run workflow release api auth browse config gist label project search secret status "
               "variable cache completion help ruleset org codespace ssh-key attestation version alias extension".split())
FREE_TEXT = re.compile(r"--(?:body|title|message|description)=|-m.")
CARRIERS = {"su", "script", "flock", "watch", "parallel", "find", "xargs", "busybox"}
PI_RE = re.compile(r"(?i)pi\.lifenet\.com\.tw|\bPI_(?:SSH_)?(?:HOST|USER)\b")
SECRET_OK = {".env.example", ".env.sample", ".env.template", ".env.dist"}
# wrapper -> (flags, options taking a value); anything else => deny.
_L = lambda *a: set(a)
WRAPPERS = {
    "command": (_L("-p", "-v", "-V"), set()), "exec": (_L("-c", "-l"), _L("-a")),
    "builtin": (set(), set()), "nohup": (set(), set()),
    "time": (_L("-p", "-v", "-a", "--portability", "--verbose", "--append"), _L("-f", "-o", "--format", "--output")),
    "setsid": (_L("-c", "-f", "-w", "--ctty", "--fork", "--wait"), set()),
    "env": (_L("-i", "-0", "-v", "--ignore-environment", "--null", "--debug"), _L("-u", "--unset", "-C", "--chdir")),
    "nice": (set(), _L("-n", "--adjustment")),
    "timeout": (_L("--foreground", "--preserve-status", "-v", "--verbose"), _L("-s", "--signal", "-k", "--kill-after")),
    "sudo": (_L("-E", "-H", "-n", "-S", "-b", "-i", "-s", "-A", "-k", "-K", "--preserve-env", "--login", "--shell",
                "--non-interactive", "--background", "--stdin"),
             _L("-u", "--user", "-g", "--group", "-h", "--host", "-p", "--prompt", "-C", "--close-from", "-D",
                "--chdir", "-R", "--chroot", "-T", "--command-timeout", "-U", "--other-user")),
    "stdbuf": (set(), _L("-i", "-o", "-e", "--input", "--output", "--error")),
    "ionice": (_L("-t", "--ignore"), _L("-c", "-n", "-p", "--class", "--classdata", "--pid")),
    "local-heavy-gate": (set(), set()),
    "xargs": (_L("-0", "-r", "-t", "-p", "-x", "--null", "--no-run-if-empty", "--verbose", "--interactive", "--exit"),
              _L("-I", "-n", "-P", "-d", "-E", "-L", "-s", "-a", "--max-args", "--max-procs", "--delimiter",
                 "--arg-file", "--max-lines", "--max-chars")),
}
# git global options: flags and options taking a separate value
GIT_FLAGS = set("--no-pager -p --paginate -P --no-replace-objects --bare --literal-pathspecs --glob-pathspecs "
                "--noglob-pathspecs --icase-pathspecs --no-optional-locks --no-lazy-fetch --no-advice --version -v "
                "--help -h --html-path --man-path --info-path --exec-path --list-cmds".split())
GIT_OPT_VALUE = {"--git-dir", "--work-tree", "--namespace", "--super-prefix", "--config-env", "--attr-source"}
# git -c/--config-env/config keys allowed by default-deny; everything else can run programs or redirect pushes
CFG_OK = re.compile(r"(?i)(?:user\.(?:name|email)|color\..+|core\.quotepath|advice\..+|init\.defaultbranch"
                    r"|safe\.directory|log\..+|format\..+)$")
GIT_ENV_BAD = re.compile(r"(?i)GIT_(?:CONFIG\w*|PAGER|EDITOR|SEQUENCE_EDITOR|SSH|SSH_COMMAND|EXTERNAL_DIFF|ASKPASS"
                         r"|EXEC_PATH|PROXY_COMMAND|DIR|WORK_TREE)$")
CFG_READ = {"--get", "--get-all", "--get-regexp", "--list", "-l", "--get-urlmatch", "--get-color", "--get-colorbool"}
CFG_VAL = {"-f", "--file", "--blob", "--type", "--default", "--comment"}


class Deny(Exception):
    pass


class Resplit(Exception):
    """env -S: the value is a command line to re-parse."""


class W:
    """A shell word: text with DYN where non-literal, dynamic flag, raw JSON."""
    __slots__ = ("text", "dyn", "raw", "glob")

    def __init__(self, node):
        self.raw = json.dumps(node)
        self.text, self.dyn, self.glob = "", False, False
        for p in node.get("Parts", []):
            t = p.get("Type")
            if t == "Lit":
                if re.search(r"(?<!\\)[*?\[]", p["Value"]):
                    self.glob = True
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


def unk(w):
    """Value cannot be known statically (expansion or glob)."""
    return w.dyn or w.glob


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


def secret_arg(text, cwd, g=False):
    # also catches `file=@.env` / `--file=.env`; g: unquoted glob, test every match
    vs = {text, text.split("=", 1)[-1], text.split("@", 1)[-1]}
    if re.match(r"-[^-]", text):
        vs.add(text[2:])  # -Xvalue
    vs = {v.lstrip("@<") for v in vs} - {""}
    if g:
        vs |= {m for v in vs for m in globmod.glob(os.path.join(cwd, os.path.expanduser(v)))}
    return any(is_secret(v, cwd) for v in vs)


def unwrap(argv, seen):
    while argv:
        if argv[0].dyn or argv[0].glob and argv[0].text not in ("[", "[["):
            raise Deny("cannot verify dynamic command name")
        n = base(argv[0])
        if n not in WRAPPERS:
            return argv
        flags, vals = WRAPPERS[n]
        i = 1
        if n == "local-heavy-gate":
            while i < len(argv) and argv[i].text != "--":
                i += 1
            i += 1
        else:
            while i < len(argv):
                t = argv[i].text
                if argv[i].dyn:
                    raise Deny(f"cannot verify dynamic {n} option")
                if t == "--":
                    i += 1
                    break
                if n == "env" and re.match(r"-S|--split-string", t):
                    att = t.split("=", 1)[1] if t.startswith("--") and "=" in t else t[2:] if not t.startswith("--") else ""
                    val, rest = (att, argv[i + 1:]) if att else (argv[i + 1].text if i + 1 < len(argv) else "", argv[i + 2:])
                    if any(w.dyn for w in rest) or any(w.dyn for w in argv):
                        raise Deny("cannot verify dynamic env -S command")
                    raise Resplit(val + " " + " ".join(shlex.quote(w.text) for w in rest))
                if n == "env" and "=" in t and not t.startswith("-"):
                    env_name_check(t.split("=", 1)[0], seen)
                    i += 1
                elif not t.startswith("-"):
                    break
                elif t.startswith("--") and "=" in t:
                    if t.split("=", 1)[0] not in vals:
                        raise Deny(f"unknown {n} option {t.split('=', 1)[0]} (fail closed)")
                    i += 1
                elif t in flags:
                    i += 1
                elif t in vals:
                    i += 2
                elif not t.startswith("--") and t[:2] in vals and len(t) > 2:
                    i += 1  # attached value, e.g. -n5
                elif n == "nice" and re.match(r"-\d+$", t):
                    i += 1
                elif not t.startswith("--") and len(t) > 2 and all("-" + c in flags for c in t[1:]):
                    i += 1  # cluster of flags
                else:
                    raise Deny(f"unknown {n} option {t} (fail closed)")
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


def check_cfg(text, dyn=False):
    if dyn or not CFG_OK.match(text.split("=", 1)[0]):
        raise Deny("git -c/--config-env key is not on the safe allowlist (fail closed)")


def env_name_check(name, seen):
    if GIT_ENV_BAD.match(name):
        raise Deny(f"{name} can inject git config/programs (fail closed)")
    seen.append(name)


def check_git(args, cwd):
    i, dirs = 0, []
    while i < len(args):
        w = args[i]
        a = w.text
        if unk(w):
            break
        if a == "-C" and i + 1 < len(args):
            dirs.append(args[i + 1])
            i += 2
        elif a in ("-c", "--config-env") and i + 1 < len(args):
            check_cfg(args[i + 1].text, args[i + 1].dyn)
            i += 2
        elif a.startswith("--config-env="):
            check_cfg(a.split("=", 1)[1], w.dyn)
            i += 1
        elif a in GIT_OPT_VALUE:
            i += 2
        elif a.startswith("--") and a.split("=")[0] in GIT_OPT_VALUE or a.startswith(("--exec-path=", "--list-cmds=")) \
                or a in GIT_FLAGS:
            i += 1
        elif a.startswith("-"):
            raise Deny(f"unknown git global option {a} (fail closed)")
        else:
            break
    if i >= len(args):
        return
    if unk(args[i]):
        raise Deny("cannot verify dynamic git subcommand")
    sub, rest = args[i].text, args[i + 1:]
    texts = [w.text for w in rest]
    d = cwd
    for w in dirs:
        if w.dyn:
            if sub == "commit":
                raise Deny("cannot verify dynamic git -C directory for commit")
            continue
        d = os.path.join(d, os.path.expanduser(w.text))
    if sub == "config" and not CFG_READ & set(texts):
        keys, k = [], 0
        while k < len(texts):
            if texts[k] in CFG_VAL:
                k += 1
            elif texts[k] in ("-e", "--edit"):
                raise Deny("git config --edit runs an editor (fail closed)")
            elif not texts[k].startswith("-"):
                keys.append(texts[k])
                break
            k += 1
        if not keys or not CFG_OK.match(keys[0]):
            raise Deny("git config write key is not on the safe allowlist (fail closed)")
    if sub not in KNOWN_GIT:
        raise Deny(f"unknown git subcommand '{sub}' (alias/external command; fail closed)")
    if (sub == "rebase" and any(t == "--exec" or abbr(t, "--exec") or "x" in shorts(t) for t in texts)
            or sub == "bisect" and "run" in texts or sub == "submodule" and "foreach" in texts
            or sub == "difftool" and any(abbr(t, "--extcmd") or "x" in shorts(t) for t in texts)
            or sub == "filter-branch"):
        raise Deny(f"git {sub} can execute arbitrary programs (fail closed)")
    if sub in ("push", "reset", "clean", "branch") and any(unk(w) for w in rest):
        raise Deny(f"cannot verify dynamic arguments to git {sub} (fail closed)")
    if sub == "commit":
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
    if t and (t[0] not in KNOWN_GH or any(unk(w) for w in args[:1])):
        raise Deny(f"unknown gh subcommand {t[0]!r} (fail closed)")
    if t[:2] in (["alias", "set"], ["alias", "import"]):
        raise Deny("gh alias set/import can hide commands (fail closed)")
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
        or n in ("npm", "yarn", "pnpm") and any(re.search(r"\bdeploy\b", a) for a in t)
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
        self.fed = None  # stdin of the command being walked: None | "pipe" | "file" | ("lit", text)

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
        saved = self.fed
        if n.get("Type") == "DeclClause":
            for a in n.get("Args", []):
                env_name_check((a.get("Name") or {}).get("Value") or "", [])
        for r in n.get("Redirs", []):
            op = r.get("Op")
            if op in ("<<", "<<-"):
                parts = (r.get("Hdoc") or {}).get("Parts", [])
                self.fed = ("lit", "".join(p["Value"] for p in parts)) if all(p.get("Type") == "Lit" for p in parts) else "pipe"
            elif op == "<<<":
                w = W(r["Word"])
                self.fed = "pipe" if w.dyn else ("lit", w.text)
            elif op == "<":
                self.fed = "file"
        for k, v in n.items():
            if k == "Redirs":
                for r in v:
                    self.redirect(r, cwd)
            if k == "Y" and n.get("Op") in ("|", "|&"):
                self.fed = "pipe"
            cwd = self.walk(v, cwd, depth)
        if n.get("Type") == "CallExpr":
            seen = []
            for a in n.get("Assigns", []):
                env_name_check(a["Name"]["Value"], seen)
        if n.get("Type") == "CallExpr" and n.get("Args"):
            cwd = self.cmd([W(a) for a in n["Args"]], cwd, depth, seen) or cwd
        self.fed = saved
        return cwd

    def redirect(self, r, cwd):
        if r.get("Op") not in ("<<", "<<-", "<<<") and r.get("Word"):
            w = W(r["Word"])
            if secret_arg(w.text, cwd, w.glob):
                raise Deny(f"shell redirection {r['Op']} touches a credential-shaped file (AGENTS.md RULE-SEC-001).")

    def tail(self, argv, cwd, depth):
        """Payload carriers: treat any later literal word as a possible command start."""
        if depth > MAX_DEPTH:
            raise Deny("carrier nested too deeply to verify")
        for k in range(1, len(argv)):
            if not unk(argv[k]) and argv[k].text != ".":  # `.` is usually a find/path operand
                self.cmd(argv[k:], cwd, depth + 1)
            if " " in argv[k].text and not argv[k].dyn and base(argv[0]) in ("su", "script", "flock", "watch", "parallel"):
                self.run(argv[k].text, cwd, depth + 1)

    def cmd(self, argv, cwd, depth, seen=None):
        seen = [] if seen is None else seen
        try:
            argv = unwrap(argv, seen)
        except Resplit as r:
            self.run(str(r), cwd, depth + 1)
            return None
        if not argv:
            return None
        name, args = base(argv[0]), argv[1:]
        if name == "cd":
            return os.path.join(cwd, os.path.expanduser(args[0].text)) if args and not args[0].dyn else None
        if name in SSH_FAMILY:
            if any(PI_RE.search(w.raw) for w in args):
                raise Deny("SSH/copy to the production Pi. Forbidden for agents - all changes go "
                           "branch -> PR -> CI -> deploy.yml (CLAUDE.md R2/R6).")
            if any(unk(w) for w in args):
                raise Deny(f"cannot verify dynamic arguments to {name} (fail closed)")
        if name == "git":
            if any(n.upper() in ("PAGER", "EDITOR") for n in seen):
                raise Deny("PAGER/EDITOR assignment for git can run programs (fail closed)")
            check_git(args, cwd)
        elif name == "gh":
            check_gh(args)
        check_deploy(name, argv)
        if name in READERS:
            for w in args:
                if secret_arg(w.text, cwd, w.glob):
                    raise Deny(f"{name} reads/copies a credential-shaped file (AGENTS.md RULE-SEC-001).")
        if name not in SAFE_CMDS:
            keep, skip = [], False
            for w in argv:  # free-text values (-m/--body/...) are inert; AST already exposes substitutions
                if skip or name in ("git", "gh") and FREE_TEXT.match(w.text):
                    skip = w.text in ("-m", "--message", "--body", "--title", "--description")
                    continue
                keep.append(w.text)
                skip = name in ("git", "gh") and w.text in ("-m", "--message", "--body", "--title", "--description")
            regex_scan(" ".join(keep))
        if name in CARRIERS:
            self.tail(argv, cwd, depth)
        if name == "set" and (any(re.match(r"-[A-Za-z]*a", w.text) for w in args)
                              or any(args[k].text in ("-o", "+o") and args[k + 1].text.startswith("a")
                                     for k in range(len(args) - 1)) or any(unk(w) for w in args)):
            raise Deny("set -a/allexport (or dynamic set args) can export injected variables (fail closed)")
        if name == "eval":
            if any(w.dyn for w in args):
                raise Deny("cannot verify dynamic eval payload")
            self.run(" ".join(w.text for w in args), cwd, depth + 1)
        if name in SHELLS:
            self.shell(name, args, cwd, depth)
        elif INTERP.match(name):
            pos = [w for w in args if not w.text.startswith("-") or w.text == "-"]
            if (not pos or pos[0].text == "-") and not any(re.match(r"-[a-z]*[ceEr]$", w.text) for w in args):
                self.stdin_code(name, cwd, depth, False)
        elif "/" in argv[0].text and re.search(r"\.(sh|bash)$", argv[0].text):
            self.script(argv[0].text, cwd, depth, True)
        if re.search(r"deploy[^/]*\.(sh|bash|py|rb|js)$", argv[0].text if "/" in argv[0].text else "", re.I):
            raise Deny("deploy script. Requires explicit Founder approval (CLAUDE.md).")
        return None

    def stdin_code(self, name, cwd, depth, shell):
        f = self.fed
        if isinstance(f, tuple):
            if shell:
                self.run(f[1], cwd, depth + 1)
            else:
                regex_scan(f[1])
        elif f is not None or shell:
            raise Deny(f"{name} reading code from stdin/pipe/file cannot be verified (fail closed)")

    def shell(self, name, args, cwd, depth):
        if name in ("source", "."):
            ops, c, nflag, s_flag = args[:1], False, False, False
        else:
            i = c = nflag = s_flag = 0
            while i < len(args) and not unk(args[i]):
                t = args[i].text
                if t == "--":
                    i += 1
                    break
                if t in ("-", "") or t[0] not in "-+":
                    break
                if t.startswith("--"):
                    if t in ("--rcfile", "--init-file"):
                        i += 1
                    elif t not in ("--login", "--noprofile", "--norc", "--posix", "--restricted", "--verbose",
                                   "--debug", "--noediting", "--version", "--help"):
                        raise Deny(f"unknown shell option {t} (fail closed)")
                else:
                    cl = t[1:]
                    if "a" in cl and t[0] == "-" or re.search(r"[oO]", cl) and i + 1 < len(args) \
                            and args[i + 1].text.startswith("a"):
                        raise Deny("shell -a/allexport exports injected variables (fail closed)")
                    if t[0] == "-":
                        c, nflag, s_flag = c or "c" in cl, nflag or "n" in cl, s_flag or "s" in cl
                    if re.search(r"[oO]", cl):
                        if cl[-1] not in "oO":
                            raise Deny(f"ambiguous shell option cluster {t} (fail closed)")
                        i += 1
                i += 1
            ops = args[i:]
        if c:
            if not ops or unk(ops[0]):
                raise Deny("cannot verify shell -c payload (fail closed)")
            return self.run(ops[0].text, cwd, depth + 1)
        if nflag:
            return
        if not ops or s_flag or ops[0].text == "-":
            return self.stdin_code(name, cwd, depth, True)
        t = ops[0].text
        if unk(ops[0]) or t.startswith("/dev/") or re.match(r"/proc/[^/]+/fd/", t):
            raise Deny("shell script operand is dynamic or a device/fd (fail closed)")
        if re.search(r"deploy[^/]*\.(sh|bash|py|rb|js)$", t, re.I):
            raise Deny("deploy script. Requires explicit Founder approval (CLAUDE.md).")
        self.script(t, cwd, depth, True)
        for w in ops[1:]:  # other *.sh operands: Pi check only
            if re.search(r"\.(sh|bash)$", w.text):
                self.script(w.text, cwd, depth, False)

    def script(self, path, cwd, depth, strict):
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
            if strict:
                self.run(body, cwd, depth + 1)
            return
        if strict:
            raise Deny(f"script {path} cannot be opened to verify (fail closed)")


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
