// #1387: fail the required Control Plane lint if a workflow can print DB credentials / .env content to the Actions log.
// Fail-closed: every `run:` / `with.script` occurrence of a credential-named expansion, an .env path, or an environment
// dump must be one of a few exact safe forms; anything else needs `# secret-echo-allow: <reason, 10+ chars>` on the line.
// Lines that already existed when the guard became fail-closed sit in a shrink-only baseline
// (scripts/ci/workflow-secret-echo.baseline.json): CI fails on any finding not in it and on any baseline growth.
// Regenerate (only to shrink it) with: node scripts/ci/workflow-secret-echo.test.mjs --write-baseline
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = '.github/workflows';
const ACTIONS = '.github/actions'; // local composite actions run inside the workflows that use them
const BASELINE = 'scripts/ci/workflow-secret-echo.baseline.json';
const FIXTURES = new Set(['ci-ephemeral', 'alltruepw', 'rootpw', 'root']); // non-sensitive CI-only DB fixtures
// credential-like: PASS/PASSWD/PASSWORD/SECRET(S) is one of its `_`-separated tokens, it contains PASSWORD,
// or it is MYSQL_PWD (the MySQL client's password variable; the shell's own PWD stays non-secret)
const isSecretName = (n) => /PASSWORD/i.test(n) || /^MYSQL_PWD$/i.test(n) || n.split('_').some((t) => /^(?:PASS|PASSWD|PASSWORD|SECRETS?)$/i.test(t));
const ALLOW_ALL = /#(?=\s*secret-echo-allow:(.*)$)/gm; // every candidate `#`, reason captured in the lookahead
// a trailing glob / brace (`.env*`, `.env{,.local}`) can expand to any .env variant
const ENV_FILE = /(?<=^|[\s"'`=<>(|;&,:{])(?:[^\s"'`<>|;&()=,{]*\/)?\.env(?!\.(?:example|sample|dist|template)\b)(?:\.[\w-]+)*(?:[*?[{][^\s;&|)"'`<>]*)?(?=[\s;&|)"'`<>,}]|$)/g;
const CRED = /\$\{?!?([A-Za-z_][A-Za-z0-9_]*)|\x01(\d+)\x01/g; // $NAME ${NAME ${!NAME, or a ${{ }} placeholder
// `set` traces when any of its option groups holds x (`set -x`, `set -e -x`, `set -o pipefail -x`)
const TRACE = /(?:^|[;&\s(])set(?:[ \t]+[^\s;&|#]+)*?[ \t]+-[a-zA-Z]*x|\bset\s+-o\s+xtrace\b|\b(?:ba|da|z|k|a)?sh\s+(?:-\S+\s+)*-[a-z]*x|-[a-z]*o\s*xtrace\b|SHELLOPTS\W+\S*xtrace/;
const LITERAL = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*[:=]\s*(['"]?)([^\s'"$`]+)\2\s*$/;
const HASHES = new Set(['sha1sum', 'sha224sum', 'sha256sum', 'sha384sum', 'sha512sum', 'shasum', 'md5sum', 'b2sum']);
// commands whose only output is their stdout: a redirect/hash of that stdout is a safe sink
const STDOUT_ONLY = new Set(['echo', 'printf', 'cat', 'head', 'tail', 'grep', 'sed', 'awk', 'cut', 'tr', 'sort', 'uniq', 'base64', 'jq', 'envsubst', 'env', 'printenv', 'export', 'readonly', 'set', 'declare', 'typeset']);
const FILTERS = new Set(['grep', 'sed', 'awk', 'cut', 'tr', 'sort', 'uniq', 'head', 'tail', 'base64', 'jq']);
// never trusted to keep an argument quiet (they print or execute it)
const NO_ARG_TRUST = new Set(['', 'echo', 'printf', 'print', 'cat', 'tee', 'logger', 'eval', 'bash', 'sh', 'zsh', 'dash', 'ksh', 'xargs', 'timeout', 'nohup', 'stdbuf', 'watch']);
const ALIAS = /\/dev\/(?!null\b)|\/proc\//;
const RESERVED = /^(?:\{|\}|if|then|elif|else|fi|do|done|while|until|!|time|esac)$/;

// --- shell lexer: commands with words (char text + source offset + quote per char), redirects, heredocs, nested substitutions
function matchClose(src, i, open, close) {
  let d = 0;
  for (let j = i; j < src.length; j++) {
    const c = src[j];
    if (c === '\\') j++;
    else if (c === "'") { j = src.indexOf("'", j + 1); if (j < 0) return -1; }
    else if (c === '"') { for (j++; j < src.length && src[j] !== '"'; j++) if (src[j] === '\\') j++; }
    else if (c === open) d++;
    else if (c === close && --d === 0) return j;
  }
  return -1;
}
function lex(src, base = 0, heredocBody = false) {
  const cmds = [], comments = [], pending = [];
  let i = 0, bad = false, cur = { words: [], redirs: [] };
  const push = (sep) => { if (cur.words.length || cur.redirs.length) cmds.push({ ...cur, sep }); cur = { words: [], redirs: [] }; };
  const sub = (w, kind, from, to, q) => {
    const script = lex(src.slice(from, to), base + from);
    comments.push(...script.comments);
    w.subs.push({ kind, at: w.text.length, script });
    w.text += '\x03'; w.pos.push(base + from); w.q.push(q);
  };
  function expansion(w, q) { // at `$` or backtick; returns false when it is a plain `$`
    const c = src[i];
    if (c === '`') { let j = i + 1; for (; j < src.length && src[j] !== '`'; j++) if (src[j] === '\\') j++; if (j >= src.length) bad = true; sub(w, '`', i + 1, j, q); i = j + 1; return true; }
    if (src[i + 1] !== '(' && src[i + 1] !== '{') return false;
    const j = matchClose(src, i + 1, src[i + 1], src[i + 1] === '(' ? ')' : '}');
    if (j < 0) { bad = true; i = src.length; return true; }
    if (src[i + 1] === '(' && src[i + 2] !== '(') sub(w, '$(', i + 2, j, q);
    else for (let k = i; k <= j; k++) { w.text += src[k]; w.pos.push(base + k); w.q.push(q); } // ${...} / $((...)) literal
    i = j + 1; return true;
  }
  function word() {
    const w = { text: '', pos: [], q: [], subs: [], off: base + i }, start = i;
    const add = (q, ch, at) => { w.text += ch; w.pos.push(base + at); w.q.push(q); };
    while (i < src.length && !/[ \t\r\n;&|<>()]/.test(src[i])) {
      const c = src[i];
      if (c === '\\') { if (src[i + 1] !== '\n') add('\\', src[i + 1] ?? '', i + 1); i += 2; }
      else if (c === "'") { const j = src.indexOf("'", i + 1); if (j < 0) { bad = true; return w; } for (let k = i + 1; k < j; k++) add("'", src[k], k); i = j + 1; }
      else if (c === '"') {
        for (i++; i < src.length && src[i] !== '"';) {
          if (src[i] === '\\' && /[$`"\\\n]/.test(src[i + 1] ?? '')) { if (src[i + 1] !== '\n') add('"', src[i + 1], i + 1); i += 2; }
          else if ((src[i] === '$' || src[i] === '`') && expansion(w, '"')) continue;
          else { add('"', src[i], i); i++; }
        }
        if (i >= src.length) bad = true; i++;
      } else if ((c === '$' || c === '`') && expansion(w, '')) continue;
      else { add('', c, i); i++; }
    }
    w.empty = i === start;
    return w;
  }
  if (heredocBody) { // unquoted heredoc body: expansions as in double quotes, `"` is literal
    const w = { text: '', pos: [], q: [], subs: [], off: base };
    while (i < src.length) {
      if (src[i] === '\\' && /[$`\\\n]/.test(src[i + 1] ?? '')) { w.text += src[i + 1]; w.pos.push(base + i + 1); w.q.push('"'); i += 2; }
      else if ((src[i] === '$' || src[i] === '`') && expansion(w, '"')) continue;
      else { w.text += src[i]; w.pos.push(base + i); w.q.push('"'); i++; }
    }
    return { word: w, bad };
  }
  function heredocs() {
    for (const r of pending.splice(0)) {
      const start = i; let body = '';
      while (i < src.length) {
        const eol = src.indexOf('\n', i), e = eol < 0 ? src.length : eol, line = src.slice(i, e).replace(/\r$/, '');
        i = e + 1;
        if ((r.op === '<<-' ? line.replace(/^\t+/, '') : line) === r.delim) break;
        body += line + '\n';
      }
      r.heredoc = { body, off: base + start };
    }
  }
  while (i < src.length) {
    const c = src[i], two = src.slice(i, i + 2);
    if (c === ' ' || c === '\t' || c === '\r' || two === '\\\n') i += c === '\\' ? 2 : 1;
    else if (c === '\n') { push('\n'); i++; heredocs(); }
    else if (c === '#') { const e = src.indexOf('\n', i); comments.push([base + i, base + (e < 0 ? src.length : e)]); i = e < 0 ? src.length : e; }
    else if (two === '&&' || two === '||' || two === '|&' || two === ';;') { push(two === ';;' ? ';' : two); i += 2; }
    else if (c === ';' || c === '(' || c === ')' || c === '|' || (c === '&' && src[i + 1] !== '>')) { push(c === '|' || c === '&' ? c : ';'); i++; }
    else if ((c === '<' || c === '>') && src[i + 1] === '(') { // process substitution word
      const j = matchClose(src, i + 1, '(', ')'); if (j < 0) { bad = true; break; }
      const w = { text: '\x03', pos: [base + i], q: [''], subs: [], off: base + i, procOut: c === '>' };
      w.subs.push({ kind: c + '(', at: 0, script: lex(src.slice(i + 2, j), base + i + 2) });
      comments.push(...w.subs[0].script.comments);
      cur.words.push(w); i = j + 1;
    } else if (/^(?:\d+|&)?[<>]/.test(src.slice(i, i + 12))) {
      const fd = /^(\d+|&)?/.exec(src.slice(i))[0]; i += fd.length;
      const op = /^(?:<<<|<<-|<<|<>|<&|>>|>\||>&|<|>)/.exec(src.slice(i))[0]; i += op.length;
      while (src[i] === ' ' || src[i] === '\t') i++;
      const r = { fd, op, off: base + i };
      if (src[i] === '>' && src[i + 1] === '(') { r.target = { text: '/dev/fd/proc-subst', pos: [], q: [], subs: [] }; const j = matchClose(src, i + 1, '(', ')'); i = j < 0 ? src.length : j + 1; }
      else r.target = word();
      if (op === '<<' || op === '<<-') { r.delim = r.target.text; r.quoted = r.target.q.some((q) => q); pending.push(r); }
      cur.redirs.push(r);
    } else {
      const w = word();
      if (w.empty) { bad = true; i++; continue; }
      if (!cur.words.length && RESERVED.test(w.text) && !w.q.some((q) => q)) continue;
      cur.words.push(w);
    }
  }
  push('');
  return { cmds, comments, bad, src };
}

// --- analysis ---------------------------------------------------------------------------------------------------
const assignRe = /^[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]*\])?\+?=/;
function resolve(words) { // skip assignments and wrapper commands; `dump` marks an environment-listing command
  let k = 0;
  while (k < words.length && assignRe.test(words[k].text)) k++;
  for (;;) {
    const name = (words[k]?.text ?? '').replace(/^.*\//, ''), args = words.slice(k + 1).map((w) => w.text);
    if (/^(?:command|exec|nohup|builtin|nice)$/.test(name)) { // consume the wrapper's own options before the wrapped command
      for (k++; words[k] && /^-/.test(words[k].text); k++) {
        const o = words[k].text;
        if (o === '--') { k++; break; }
        if (name === 'command' && /[vV]/.test(o)) return { k, name }; // command -v / -V only describes the command
        if ((name === 'exec' && o === '-a') || (name === 'nice' && o === '-n')) k++; // option with an operand
      }
      continue;
    }
    if (name === 'sudo') { k++; while (words[k] && /^-/.test(words[k].text)) k += /^-[ugCh]$/.test(words[k].text) ? 2 : 1; continue; }
    if (name === 'env') {
      if (args.length === 1 && /^--(?:help|version)$/.test(args[0])) return { k, name, help: true };
      let j = k + 1;
      for (; j < words.length; j++) {
        const a = words[j].text;
        if (/^-[uCS]$|^--(?:unset|chdir|split-string)$/.test(a)) j++;
        else if (a === '--') continue;
        else if (!a.startsWith('-') && !assignRe.test(a)) break;
      }
      if (j >= words.length) return { k, name, dump: true };
      k = j; continue;
    }
    if (name === 'printenv') {
      if (args.length === 1 && /^--?(?:help|version)$/.test(args[0])) return { k, name, help: true };
      const names = args.filter((a) => !a.startsWith('-'));
      return { k, name, dump: !names.length || names.some((n) => isSecretName(n.replace(/^\$\{?|\}$/g, ''))) };
    }
    const opts = args.every((a) => a.startsWith('-'));
    const dump = (/^(?:export|readonly)$/.test(name) && args.every((a) => a === '-p')) || (name === 'set' && !args.length)
      || (/^(?:declare|typeset)$/.test(name) && (opts || (args.some((a) => /^-[a-zA-Z]*p/.test(a)) && args.some((a) => !a.startsWith('-') && isSecretName(a)))));
    return { k, name, dump };
  }
}
const isAlias = (w) => w.procOut || ALIAS.test(w.text);
const hasAlias = (c) => c.words.some(isAlias);
function stdoutTarget(c, initial) { // final destination of fd 1 after the command's own redirects
  let fd1 = initial;
  for (const r of c.redirs) {
    if (/^</.test(r.op) || (r.fd !== '' && r.fd !== '1' && r.fd !== '&')) continue;
    const t = r.target?.text ?? '';
    if (r.op === '>&') fd1 = t === '1' ? fd1 : t === '-' ? 'null' : 'log';
    else fd1 = isAlias(r.target ?? { text: '' }) ? 'log' : t === '/dev/null' ? 'null' : 'file';
  }
  return fd1;
}
const isHash = (c) => { const r = resolve(c.words); return HASHES.has(r.name) && c.words.slice(r.k + 1).every((w) => /^-|^\d+$/.test(w.text)); };
const varOnly = (t) => /^\$\{?[A-Za-z_][A-Za-z0-9_]*\}?$|^\x01\d+\x01\x02*$/.test(t);
// where does sensitive output of stage s of pipeline P end up? 'safe' | 'unsafe' | 'taint' (captured by $(...))
function sink(P, s, ctx, cred) {
  const c = P[s];
  if (hasAlias(c)) return 'unsafe';
  const fd1 = stdoutTarget(c, s < P.length - 1 ? 'pipe' : ctx === 'sub' ? 'capture' : 'log');
  if (fd1 === 'file' || fd1 === 'null') return 'safe';
  if (fd1 === 'capture') return 'taint';
  if (fd1 !== 'pipe') return 'unsafe';
  const next = P[s + 1], nr = resolve(next.words);
  if (isHash(next)) return hasAlias(next) || stdoutTarget(next, 'x') === 'log' ? 'unsafe' : 'safe';
  if (cred && s === 0 && P.length === 2 && /^(?:echo|printf)$/.test(resolve(c.words).name) && !c.redirs.length
    && next.words.some((w) => w.text === '--password-stdin') && !hasAlias(next)
    && c.words.slice(resolve(c.words).k + 1).filter((w, i) => !/^-[neE]+$/.test(w.text) && !(i === 0 && /^%/.test(w.text))).every((w) => varOnly(w.text))) return 'safe';
  if (FILTERS.has(nr.name)) return sink(P, s + 1, ctx, cred);
  return 'unsafe';
}
// a capture whose inner text cannot reach the log: no redirect other than to /dev/null or from a file, no log alias
const cleanCapture = (inner) => !ALIAS.test(inner) && !/>/.test(inner.replace(/(?:\d+|&)?>>?\s*\/dev\/null\b/g, '').replace(/<<?<?-?/g, ''));
const credExpr = (expr) => expr.replace(/\bsecrets\.\w+/g, '').split(/[^\w]+/).some(isSecretName); // secrets.* are masked
function credHits(w, exprs) {
  const hits = [];
  for (const m of w.text.matchAll(CRED)) {
    if (m[2] !== undefined ? credExpr(exprs[+m[2]]) : isSecretName(m[1])) hits.push(m.index); // '$X' too: fail closed
  }
  return hits;
}

function analyze(script, ctx, out, exprs) { // returns true when (ctx 'sub') sensitive data reaches the captured stdout
  let taint = false;
  const rec = (off, verdict) => { if (verdict === 'taint') taint = true; out.push({ off, safe: verdict !== 'unsafe' }); };
  const pipes = [[]];
  for (const c of script.cmds) { pipes[pipes.length - 1].push(c); if (c.sep !== '|' && c.sep !== '|&') pipes.push([]); }
  for (const P of pipes) P.forEach((c, s) => {
    const info = resolve(c.words);
    const args = c.words.slice(info.k + 1).map((w) => w.text);
    const printer = /^(?:echo|printf)$/.test(info.name);
    const nonRead = !hasAlias(c) && (/^(?:rm|cp|mv|test|\[|\[\[|touch|chmod|chown|for|case)$/.test(info.name)
      || (info.name === 'sed' && args.some((a) => /^-[a-zA-Z]*i|^--in-place/.test(a)))
      || (info.name === 'grep' && args.some((a) => /^-[a-zA-Z]*q|^--(?:quiet|silent)$/.test(a))));
    const pure = c.redirs.length === 0 && (info.k >= c.words.length || (/^(?:export|local|readonly|declare|typeset)$/.test(info.name)
      && c.words.slice(info.k + 1).every((w) => assignRe.test(w.text) || /^-/.test(w.text))));
    const viaSink = (cred) => (STDOUT_ONLY.has(info.name) ? sink(P, s, ctx, cred) : 'unsafe');
    if (info.dump) rec(c.words[info.k]?.off ?? c.redirs[0]?.off, viaSink(false));
    for (const w of c.words) {
      const capture = pure && assignRe.test(w.text) && /^[^=]*=\x03$/.test(w.text) && w.subs.length === 1;
      if (capture && cleanCapture(w.subs[0].script.src)) { collectRaw(w.subs[0].script, out); continue; } // NAME="$(...)"
      const subHits = w.subs.filter((sb) => analyze(sb.script, sb.kind === '>(' ? 'top' : 'sub', out, exprs) && sb.kind !== '>(').map((sb) => sb.at);
      for (const at of [...credHits(w, exprs), ...subHits]) {
        const before = w.text.slice(0, at);
        if (!NO_ARG_TRUST.has(info.name) && !pure && (/^(?:-p|--password=)$/.test(before) && varOrSub(w.text.slice(at))
          || /IDENTIFIED\s+BY\s*\\?['"]?$/i.test(before))) rec(w.pos[at], 'safe');
        else if (printer && /^::add-mask::/.test(w.text) && startsOutput(info, c.words, w)) rec(w.pos[at], 'safe');
        else rec(w.pos[at], pure ? 'unsafe' : viaSink(true));
      }
      for (const m of w.text.matchAll(ENV_FILE)) {
        if ((printer && w.q[m.index]) || nonRead) rec(w.pos[m.index], 'safe');
        else rec(w.pos[m.index], viaSink(false));
      }
    }
    for (const r of c.redirs) {
      if (!r.target) continue;
      for (const sb of r.target.subs) if (analyze(sb.script, 'sub', out, exprs)) rec(r.off, 'unsafe');
      for (const at of credHits(r.target, exprs)) rec(r.target.pos[at] ?? r.off, 'unsafe');
      for (const m of r.target.text.matchAll(ENV_FILE)) {
        const pos = r.target.pos[m.index] ?? r.off;
        if (!/^</.test(r.op) || r.op === '<<' || r.op === '<<-') rec(pos, 'safe'); // write target / heredoc word
        else rec(pos, nonRead ? 'safe' : viaSink(false)); // `cmd < .env` reads it
      }
      if (!r.heredoc) continue;
      const { body, off } = r.heredoc;
      if (!/^(?:cat|mysql|mariadb|psql)$/.test(info.name)) { const hs = lex(body, off); out.comments?.push(...hs.comments); analyze(hs, 'top', out, exprs); continue; } // a shell runs it
      // data heredoc: .env text is data; expansions (unquoted delimiter) are printed by cat or executed as SQL
      const bw = r.quoted ? { text: '', pos: [], q: [], subs: [] } : lex(body, off, true).word;
      if (r.quoted) for (const m of body.matchAll(CRED)) rec(off + m.index, 'safe'); // printed/executed literally
      for (const m of body.matchAll(ENV_FILE)) rec(off + m.index, 'safe');
      const sh = bw.subs.filter((sb) => analyze(sb.script, 'sub', out, exprs)).map((sb) => sb.at);
      for (const at of [...credHits(bw, exprs), ...sh]) {
        const sql = info.name !== 'cat' && /IDENTIFIED\s+BY\s*\\?['"]?$/i.test(bw.text.slice(0, at).split('\n').pop());
        rec(bw.pos[at] ?? off, sql ? 'safe' : info.name === 'cat' ? viaSink(true) : 'unsafe');
      }
    }
  });
  return taint;
}
// `::add-mask::` works only at the start of the emitted line: first echo operand, or printf format / sole `%s` operand
function startsOutput(info, words, w) {
  const a = words.slice(info.k + 1);
  if (info.name === 'echo') return a.find((x) => !/^-[neE]+$/.test(x.text)) === w;
  return a[0] === w || (/^%s(?:\\n)?$/.test(a[0]?.text ?? '') && a[1] === w);
}
const varOrSub = (t) => /^(?:\$\{?[A-Za-z_][A-Za-z0-9_]*\}?|\x01\d+\x01\x02*|\x03)$/.test(t);
function collectRaw(script, out) { // occurrences inside a clean capture: record as safe so the raw cross-check is satisfied
  for (const c of script.cmds) for (const w of [...c.words, ...c.redirs.map((r) => r.target).filter(Boolean)]) {
    for (const p of w.pos) out.push({ off: p, safe: true });
    for (const sb of w.subs) collectRaw(sb.script, out);
  }
}

function scanScript(where, script0) {
  const exprs = [];
  // ${{ }} is substituted by GitHub before the shell runs: same-length placeholder keeps offsets stable
  const script = script0.replace(/\$\{\{[^}]*\}\}/g, (e) => { exprs.push(e); const id = `\x01${exprs.length - 1}\x01`; return id + '\x02'.repeat(Math.max(0, e.length - id.length)); });
  const starts = [0]; for (let i = 0; i < script.length; i++) if (script[i] === '\n') starts.push(i + 1);
  const lineOf = (off) => { let lo = 0, hi = starts.length - 1; while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (starts[mid] <= off) lo = mid; else hi = mid - 1; } return lo; };
  const lines = script0.split('\n');
  const why = lines.map(() => new Set());
  const parsed = lex(script);
  const occ = Object.assign([], { comments: parsed.comments });
  if (!parsed.bad) analyze(parsed, 'top', occ, exprs);
  const covered = new Set(occ.map((o) => o.off));
  for (const o of occ) if (!o.safe) why[lineOf(o.off)].add('not a safe form');
  const inComment = (off) => parsed.comments.some(([a, b]) => off >= a && off < b);
  // raw cross-check: an occurrence the lexer did not account for (or an unparsable script) is a finding
  for (const re of [CRED, ENV_FILE]) for (const m of script.matchAll(re)) {
    const hit = m[0].startsWith('$') ? isSecretName(m[1]) : m[2] !== undefined ? credExpr(exprs[+m[2]]) : true;
    if (hit && !inComment(m.index) && (parsed.bad || !covered.has(m.index))) why[lineOf(m.index)].add('unparsed occurrence');
  }
  // tracing split by a line continuation: join continuations (same length keeps offsets) and flag the starting line
  const TRACE_ALL = new RegExp(TRACE.source, 'g');
  for (const m of script0.replace(/\\\r?\n/g, (x) => ' '.repeat(x.length)).matchAll(TRACE_ALL)) {
    const at = m.index + (/^[;&\s(]/.test(m[0]) ? 1 : 0);
    if (!inComment(at)) why[lineOf(at)].add('shell tracing');
  }
  const bad = [];
  lines.forEach((raw, i) => {
    if (/^\s*#/.test(raw) && inComment(starts[i] + raw.indexOf('#'))) return; // not a `#` line inside a quoted string
    // the marker counts only inside a comment the lexer found (not in a quoted string or heredoc data)
    const allow = [...raw.matchAll(ALLOW_ALL)].find((m) => inComment(starts[i] + m.index)) ?? null;
    const line = allow ? raw.slice(0, allow.index) : raw;
    if (TRACE.test(line)) why[i].add('shell tracing');
    const m = line.match(LITERAL);
    if (m && isSecretName(m[1]) && !FIXTURES.has(m[3])) why[i].add('literal credential');
    if (allow && allow[1].trim().length >= 10) return;
    if (allow) why[i].add('secret-echo-allow reason must be at least 10 characters');
    if (why[i].size) bad.push({ where: `${where} line ${i + 1}`, text: raw, why: [...why[i]].join(', ') });
  });
  return bad;
}

// Python: every parsed `run:` / `with.script` string, every shell value, every scalar (BaseLoader keeps all as strings)
const PY = `
import sys, json, yaml
def leaves(n):
    if isinstance(n, str): yield n
    elif isinstance(n, dict):
        for v in n.values(): yield from leaves(v)
    elif isinstance(n, list):
        for v in n: yield from leaves(v)
def walk(n, p, acc):
    if isinstance(n, dict):
        for k, v in n.items():
            q = p + '.' + str(k)
            if k == 'matrix': acc['shells'] += [[q, x] for x in leaves(v)]
            if k in ('run', 'script') and isinstance(v, str): acc['runs'].append([q, v])
            elif k == 'shell': acc['shells'] += [[q, x] for x in leaves(v)]
            elif isinstance(v, str): acc['kvs'].append([q, str(k), v])
            else: walk(v, q, acc)
    elif isinstance(n, list):
        for i, v in enumerate(n): walk(v, p + '[' + str(i) + ']', acc)
out = []
for it in json.load(sys.stdin):
    acc = {'name': it['name'], 'runs': [], 'shells': [], 'kvs': [], 'all': []}
    for doc in yaml.load_all(it['text'], Loader=yaml.BaseLoader):
        walk(doc, '', acc)
        acc['all'] += list(leaves(doc))
    out.append(acc)
json.dump(out, sys.stdout)
`;
function scanAll(items) {
  const parsed = JSON.parse(execFileSync('python3', ['-c', PY], { input: JSON.stringify(items), maxBuffer: 1 << 28 }));
  return parsed.flatMap(({ name, runs, shells, kvs, all }) => [
    ...runs.flatMap(([p, s]) => scanScript(`${name}${p}`, s)),
    // `shell: bash -x {0}` traces every command; an expression shell may pull a traced value from anywhere in the file
    ...shells.filter(([, v]) => TRACE.test(v)).map(([p, v]) => ({ where: `${name}${p}`, text: `shell: ${v}`, why: 'shell tracing' })),
    ...(shells.some(([, v]) => v.includes('${{')) ? all.filter((v) => TRACE.test(v)).map((v) => ({ where: name, text: `expression shell: ${v}`, why: 'shell tracing' })) : []),
    ...kvs.filter(([, k, v]) => /^SHELLOPTS$/i.test(k) && /xtrace/.test(v)).map(([p, k, v]) => ({ where: `${name}${p}`, text: `${k}: ${v}`, why: 'shell tracing' })),
    ...kvs.filter(([, k, v]) => isSecretName(k) && v.trim() !== '' && !FIXTURES.has(v) && !v.includes('${{'))
      .map(([p, k]) => ({ where: `${name}${p}`, text: `literal ${k}`, why: 'literal credential' })),
  ].map((f) => ({ ...f, wf: name, fp: fingerprint(f.text) })));
}
// stable across line moves: hash of the whitespace-normalised line text (never the line number or YAML path)
export const fingerprint = (text) => createHash('sha256').update(text.trim().replace(/\s+/g, ' ')).digest('hex').slice(0, 16);
export const scan = (name, text) => scanAll([{ name, text }]);

// --- baseline ratchet ---------------------------------------------------------------------------------------------
export function applyBaseline(findings, baseline) { // multiset match per workflow: returns uncovered findings and stale entries
  const left = new Map(Object.entries(baseline).filter(([k]) => !k.startsWith('//')).map(([wf, fps]) => [wf, [...fps]]));
  const fresh = findings.filter((f) => { const l = left.get(f.wf) ?? []; const i = l.indexOf(f.fp); if (i < 0) return true; l.splice(i, 1); return false; });
  return { fresh, stale: [...left].flatMap(([wf, l]) => l.map((fp) => `${wf} ${fp}`)) };
}
export const growth = (cur, base) => applyBaseline(Object.entries(cur).filter(([k]) => !k.startsWith('//'))
  .flatMap(([wf, fps]) => fps.map((fp) => ({ wf, fp }))), base).fresh.map((f) => `${f.wf} ${f.fp}`);
// every reason the check fails; a stale entry fails too, so a fixed line cannot keep an allowance for later reuse
export const problems = (fresh, stale, grown, baseRef) => [
  ...stale.map((s) => `workflow-secret-echo: stale baseline entry (line fixed or changed), remove it from ${BASELINE}: ${s}`),
  ...grown.map((g) => `workflow-secret-echo: ${BASELINE} grew vs ${baseRef} (shrink-only; fix or annotate the line instead): ${g}`),
  ...fresh.map((f) => `${f.where} ${f.why} (fix, or add "# secret-echo-allow: <reason>"): ${f.text.trim()}`),
];
const toBaseline = (findings) => {
  const by = {};
  for (const f of findings) (by[f.wf] ??= []).push(f.fp);
  return Object.fromEntries(Object.keys(by).sort().map((k) => [k, by[k].sort()]));
};
const formatBaseline = (b) => `{\n  "//": "#1387 shrink-only: existing findings of scripts/ci/workflow-secret-echo.test.mjs. Never add entries; fix or annotate instead.",\n${
  Object.entries(b).map(([k, v]) => `  ${JSON.stringify(k)}: ${JSON.stringify(v)}`).join(',\n')}\n}\n`;

// --- self-test --------------------------------------------------------------------------------------------------
const wf = (run) => `jobs:\n  j:\n    steps:\n      - run: ${run}`;
const blk = (...l) => wf(`|\n${l.map((x) => `          ${x}`).join('\n')}`);
export const trip = {
  'yaml anchor/alias run': 'jobs:\n  j:\n    steps:\n      - run: &r |\n          echo "$DB_PASSWORD"\n      - run: *r',
  'yaml block chomp |-': wf('|-\n          echo "$DB_PASSWORD"'),
  'yaml folded >+': wf('>+\n          echo\n          "$DB_PASSWORD"'),
  'yaml flow-style map': 'jobs: {j: {steps: [{run: "echo $DB_PASSWORD"}]}}',
  'yaml multi-document': 'a: 1\n---\n' + blk('echo "$DB_PASSWORD"'),
  'yaml quoted keys': 'jobs:\n  j:\n    steps:\n      - "shell": bash -x {0}\n        \'run\': echo hi',
  'shell expression, traced default': 'on:\n  workflow_dispatch:\n    inputs:\n      sh:\n        default: bash -x {0}\njobs:\n  j:\n    steps:\n      - shell: ${{ inputs.sh }}\n        run: echo hi',
  'env SHELLOPTS=xtrace': 'jobs:\n  j:\n    env:\n      SHELLOPTS: xtrace\n    steps:\n      - run: echo hi',
  'with.script set -x': 'jobs:\n  j:\n    steps:\n      - uses: x/y@v1\n        with:\n          script: |\n            set -x',
  'echo of ${{ env.*PASSWORD }}': blk('echo "${{ env.DB_PASSWORD }}"'),
  'redirect to process substitution': blk('echo "$DB_PASSWORD" > >(cat)'),
  'redirect to /proc/self/fd/2': blk('echo "$DB_PASSWORD" > /proc/self/fd/2'),
  'redirect to /dev/fd/2': blk('echo "$DB_PASSWORD" >/dev/fd/2'),
  'group to file, inner tee /dev/stderr': blk('{', '  echo "$DB_PASSWORD" | tee /dev/stderr', '} > "$F"'),
  'group to file, inner >&2': blk('{', '  echo "$DB_PASSWORD" >&2', '} > "$F"'),
  'matrix shell list': 'jobs:\n  j:\n    strategy:\n      matrix:\n        shell: ["bash -x {0}"]\n    steps:\n      - shell: ${{ matrix.shell }}\n        run: echo hi',
  'matrix include shell': 'jobs:\n  j:\n    strategy:\n      matrix:\n        include:\n          - sh: "bash -o xtrace {0}"\n    steps:\n      - shell: ${{ matrix.sh }}\n        run: echo hi',
  'group redirected to stderr': blk('{', '  echo "$DB_PASSWORD"', '} >&2'),
  'echo secret >&2': blk('echo "$DB_PASSWORD" >&2'),
  'echo secret 2>&1': blk('echo "$DB_PASSWORD" 2>&1'),
  'echo secret > /dev/stderr': blk('echo "$DB_PASSWORD" > /dev/stderr'),
  'printf %q of secret': blk("printf 'DB_PASSWORD=%q\\n' \"$DB_PASSWORD\""),
  'secret echo then unrelated redirect': blk('echo "$DB_PASSWORD"; echo hi > out.txt'),
  'secret inside quoted ;': blk('echo "a; $DB_PASSWORD"'),
  'shell: bash -x (step)': 'jobs:\n  j:\n    steps:\n      - shell: bash -x {0}\n        run: echo hi',
  'shell: bash -o xtrace (job defaults)': 'jobs:\n  j:\n    defaults:\n      run:\n        shell: bash -eo xtrace {0}\n    steps:\n      - run: echo hi',
  'shell: sh -ex (workflow defaults)': 'defaults:\n  run:\n    shell: sh -ex {0}\njobs:\n  j:\n    steps:\n      - run: echo hi',
  'assignment then cat .env': blk('X="$(date)"; cat backend/.env'),
  'assignment then cat .env.production': blk('X=$(date) && cat .env.production'),
  'set -x': blk('set -x'),
  'cat .env': blk('cat backend/.env'),
  printenv: blk('printenv'),
  'echo secret': blk('echo "pw=$DB_PASSWORD"'),
  'literal PASSWORD': 'env:\n  DB_PASSWORD: hunter2x',
  'command substitution': blk('echo "$(cat backend/.env)"'),
  base64: blk('echo "$DB_PASSWORD" | base64'),
  '.env.production / staging / local': blk('cat .env.production'),
  'backend/.env.staging': blk('cat backend/.env.staging'),
  '.env.production.local': blk('cat backend/.env.production.local'),
  'DB_PASS / DB_PASSWD literal': 'env:\n  DB_PASS: hunter2\n  DB_PASSWD: hunter2',
  'sh/dash/zsh -x': blk('sh -x deploy.sh'),
  'add-mask then secret on the same line': blk('echo "::add-mask::$TOKEN"; echo "$DB_PASSWORD"'),
  'printenv VAR / lowercase': blk('printenv db_password'),
  'folded >': wf('>\n          echo\n          "$DB_PASSWORD"'),
  'folded >- cat': wf('>-\n          cat\n          backend/.env'),
  'tee /dev/stderr before file sink': blk('echo "$DB_PASSWORD" | tee /dev/stderr > artifact'),
  'tee /proc fd before hash': blk('echo "$DB_PASSWORD" | tee /proc/self/fd/2 | sha256sum'),
  'env -0 / printenv --null': blk('printenv --null'),
  'env -0': blk('env -0'),
  'cat < .env / 0<': blk('cat 0< backend/.env'),
  'nested add-mask then secret': blk('echo "prefix $(echo ::add-mask::$TOKEN) $DB_PASSWORD"'),
  'with.script execSync cat .env': "jobs:\n  j:\n    steps:\n      - uses: actions/github-script@v7\n        with:\n          script: |\n            require('child_process').execSync('cat backend/.env', {stdio: 'inherit'})",
  'env -u NAME / -C dir (no command)': blk('env -C /tmp'),
  'env --unset=NAME | sort': blk('env --unset=HOME | sort'),
  'quoted diagnostic with --password-stdin': blk('echo "Running: echo $DB_PASSWORD | docker login --password-stdin"'),
  'quoted ; then real redirect elsewhere': blk('echo "$DB_PASSWORD"; echo "a > b"'),
  'cat .env | tee /dev/stderr > copy': blk('cat backend/.env | tee /dev/stderr > copy'),
  'continued line': blk("printf '%s\\n' \"$DB_PASSWORD\" \\", "  ''"),
  // round 5 (Codex review of 0b4ed07)
  'escaped quote keeps > inside the string': blk('echo "prefix \\" $DB_PASSWORD > credentials.txt"'),
  'background & before unrelated redirect': blk('cat backend/.env & echo done > artifact'),
  'env -u --help (consumed by -u)': blk('env -u --help'),
  'capture redirected to stderr': blk('X=$(cat backend/.env >&2)'),
  'env 2>/dev/null': blk('env 2>/dev/null'),
  'env -u HOME 2>/dev/null': blk('env -u HOME 2>/dev/null'),
  'redirect before .env operand': blk('cat 2>/dev/null backend/.env'),
  'redirect 2>&1 before .env operand': blk('cat 2>&1 backend/.env'),
  'group then unrelated redirect': blk('{', '  echo "$DB_PASSWORD"', '}; echo done > artifact'),
  'hash then >&2 (needs annotation)': blk('echo "$DB_PASSWORD" | sha256sum >&2'),
  // fail-closed extras
  'annotation reason too short': blk('curl -u "u:$DB_PASSWORD" x # secret-echo-allow: short'),
  'secret as an unknown command argument': blk('curl -u "u:$DB_PASSWORD" https://x'),
  'plain assignment of a secret': blk('X="$DB_PASSWORD"'),
  '-p form under echo': blk('echo -p"$DB_PASSWORD"'),
  'IDENTIFIED BY under echo': blk('echo "CREATE USER u IDENTIFIED BY \'$DB_PASSWORD\'"'),
  'cat heredoc expands secret to log': blk('cat <<EOF', 'pw=$DB_PASSWORD', 'EOF'),
  'cat heredoc capture prints .env': blk('cat <<EOF', 'X=$(cat .env)', 'EOF'),
  'ssh heredoc runs cat .env': blk("ssh host bash <<'EOF'", 'cat .env', 'EOF'),
  'multi-line quoted echo': blk('echo "first', '$DB_PASSWORD"'),
  'capture via ssh string with >&2': blk("X=$(ssh h 'cat .env >&2')"),
  'source .env (unlisted command)': blk('. backend/.env'),
  'bare set dumps variables': blk('set'),
  'export -p': blk('export -p'),
  'unterminated quote': blk('echo "$DB_PASSWORD'),
  // round 7 (Codex review of be2591d)
  'allow marker inside quotes': blk('echo "$DB_PASSWORD # secret-echo-allow: this is not a shell comment"'),
  'allow marker in heredoc data': blk('cat <<EOF', 'pw=$DB_PASSWORD # secret-echo-allow: heredoc text, not a comment', 'EOF'),
  '# line inside a quoted string': blk('echo "first', '# $DB_PASSWORD"'),
  'add-mask after an echo prefix': blk('echo prefix "::add-mask::$DB_PASSWORD"'),
  'add-mask after a printf prefix': blk("printf 'prefix%s\\n' \"::add-mask::$DB_PASSWORD\""),
  '/usr/bin/env': blk('/usr/bin/env'),
  '/usr/bin/printenv | sort': blk('/usr/bin/printenv | sort'),
  'declare -p NAME': blk('declare -p DB_PASSWORD'),
  'typeset -p NAME': blk('typeset -p HOME DB_PASS'),
  'cat .env*': blk('cat backend/.env*'),
  'cat .env{,.local}': blk('cat backend/.env{,.local}'),
  'cat {.env,.env.local}': blk('cat backend/{.env,.env.local}'),
  // round 8 (Codex review of 6039f32)
  'readonly -p': blk('readonly DB_PASSWORD', 'readonly -p'),
  'echo MYSQL_PWD': blk('echo "$MYSQL_PWD"'),
  'printenv MYSQL_PWD': blk('printenv MYSQL_PWD'),
  'command -p env': blk('command -p env'),
  'exec -a name env': blk('exec -a name env'),
  'nice -n 5 env': blk('nice -n 5 env'),
  'set -e -x': blk('set -e -x'),
  'set -o pipefail -x': blk('set -o pipefail -x'),
  'set -e continued -x': blk('set -e \\', '  -x'),
  'composite action run step': 'runs:\n  using: composite\n  steps:\n    - shell: bash\n      run: echo "$DB_PASSWORD"',
};
export const ok = {
  'echo > file': blk('echo "$DB_PASSWORD" > credentials.txt'),
  'echo >> "$F" / 1> / &> / &>> / > /dev/null': blk('echo "$DB_PASSWORD" >> "$F"', 'echo "$DB_PASSWORD" 1> a', 'echo "$DB_PASSWORD" &> a', 'echo "$DB_PASSWORD" &>> a', 'echo "$DB_PASSWORD" > /dev/null'),
  'quoted ; inside value to file': blk('echo "$DB_PASSWORD;mode=prod" > credentials.txt'),
  'YAML-quoted scalar to file': "jobs:\n  j:\n    steps:\n      - run: 'echo \"$DB_PASSWORD\" > credentials.txt'",
  'hash pipe': blk('echo "$DB_PASSWORD" | sha256sum', 'cat backend/.env | sha256sum'),
  'add-mask': blk('echo "::add-mask::$DB_PASSWORD"', "printf '%s\\n' \"::add-mask::$DB_PASSWORD\""),
  'single-line run add-mask': 'jobs:\n  j:\n    steps:\n      - run: echo "::add-mask::${{ env.DB_PASSWORD }}"',
  'password-stdin from a variable': blk('echo "$REGISTRY_PASSWORD" | docker login -u u --password-stdin ghcr.io'),
  'secrets.* are masked by GitHub': blk('echo "${{ secrets.DB_PASSWORD }}" | docker login -u u --password-stdin ghcr.io', 'mysql -u root -p${{ secrets.DB_PASSWORD }} -e x'),
  'printenv non-secret / env help / version': blk('printenv HOME', 'env --help', 'printenv --version'),
  'env running a command': blk('env -u HOME ./run.sh', 'env -i PATH="$PATH" ./run.sh'),
  'pure capture': blk('DB_PASS="$(grep ^DB_PASSWORD= backend/.env | cut -d= -f2-)"', 'export X=$(cat .env 2>/dev/null)', 'N=$(mysql -p"$DB_PASSWORD" -e "SELECT 1")'),
  '-p / --password= / IDENTIFIED BY': blk('mysql -u root -p"$DB_PASSWORD" -e "SELECT 1"', 'mysqldump --password=$DB_PASSWORD db > dump.sql',
    'sudo mysql -e "CREATE USER \'u\'@\'%\' IDENTIFIED BY \'$DB_PASSWORD\'"', 'echo "n=$(mysql -p"$db_pass" -e "SELECT 1")" > out'),
  'mysql heredoc IDENTIFIED BY': blk('mysql -u root <<SQL', "ALTER USER 'u'@'%' IDENTIFIED BY '$DB_PASSWORD';", 'SQL'),
  'cat heredoc to file': blk('cat > backend/.env <<EOF', 'DB_PASSWORD=$DB_PASSWORD', 'EOF'),
  '.env in a quoted message, in-place edits, writes': blk('echo "Writing backend/.env"', "sed -i 's/^X=.*/X=1/' backend/.env", 'grep -q ^APP_KEY= backend/.env',
    'rm -f .env.bak .env', 'cp .env.example .env', 'test -f backend/.env', 'if [ -f backend/.env ]; then echo yes; fi', 'echo "DB_HOST=127.0.0.1" >> backend/.env', 'cat .env.example'),
  'annotated line': blk('curl -u "u:$DB_PASSWORD" https://x # secret-echo-allow: basic auth argument, curl never prints it',
    'curl -u "u:$DB_PASSWORD" "https://x/#a" # secret-echo-allow: basic auth argument, curl never prints it'),
  'declare -p non-secret / .envrc / .env.example*': blk('declare -p HOME', 'cat .envrc', 'cp .env.example* /tmp/'),
  'non-credential names, fixtures, untraced set': blk('echo "$BYPASS_REASON"', 'echo "$PWD"', 'set +x', 'set -euo pipefail', 'set -e -o pipefail', 'command -v env', 'readonly X=1') + '\nenv:\n  BYPASS_REASON: approved\n  COMPASS_MODE: north\n  DB_PASSWORD: ci-ephemeral',
  'empty credential value': "env:\n  DB_PASSWORD: ''\njobs:\n  j:\n    steps:\n      - uses: x/y@v1\n        with: { password: '' }",
  'step name mentions xtrace / untraced shells': 'jobs:\n  j:\n    steps:\n      - name: Diagnose xtrace\n        shell: bash -eo pipefail {0}\n        run: echo hi',
  'github-script reading process.env': "jobs:\n  j:\n    steps:\n      - uses: actions/github-script@v7\n        with:\n          script: |\n            const n = process.env.ISSUE_NUMBER",
};
const fail = (...m) => { console.error(...m); process.exit(1); };
for (const [k, y] of Object.entries(trip)) if (!scan('probe', y).length) fail('guard missed probe:', k);
for (const [k, y] of Object.entries(ok)) { const r = scan('ok', y); if (r.length) fail('guard false positive:', k, r); }
{ // baseline: suppresses only its exact fingerprint, an edited line stops matching, growth is detected
  const two = scan('w.yml', blk('cat backend/.env', 'printenv'));
  const base = { 'w.yml': [two[0].fp] };
  const r1 = applyBaseline(two, base);
  if (r1.fresh.length !== 1 || r1.fresh[0].fp !== two[1].fp || r1.stale.length) fail('baseline must suppress exactly its own fingerprint', r1);
  const edited = scan('w.yml', blk('cat  backend/.env.production', 'printenv'));
  if (applyBaseline(edited, { 'w.yml': [two[0].fp, two[1].fp] }).fresh.length !== 1) fail('an edited line must stop matching its baseline entry');
  if (applyBaseline(scan('w.yml', blk('  cat   backend/.env')), base).fresh.length) fail('whitespace-only re-indent must keep matching');
  if (applyBaseline(scan('other.yml', blk('cat backend/.env')), base).fresh.length !== 1) fail('a fingerprint is scoped to its workflow');
  if (applyBaseline([], base).stale.length !== 1) fail('stale baseline entries must be reported');
  if (problems([], applyBaseline([], base).stale, [], 'origin/main').length !== 1) fail('a stale baseline entry must fail the check');
  if (growth({ 'w.yml': ['a', 'b'] }, { 'w.yml': ['a'] }).join() !== 'w.yml b') fail('baseline growth must be detected');
  if (growth({ 'w.yml': ['a', 'a'] }, { 'w.yml': ['a'] }).length !== 1) fail('a duplicated entry is growth');
  if (growth({ 'w.yml': ['a'] }, { 'w.yml': ['a', 'b'], 'x.yml': ['c'] }).length) fail('shrinking is allowed');
}

// --- repository check ---------------------------------------------------------------------------------------------
// workflows keep their bare file name (baseline key); composite action manifests use their repository path
export const inputs = () => [
  ...readdirSync(dir).filter((f) => /\.ya?ml$/.test(f)).sort().map((f) => ({ name: f, path: join(dir, f) })),
  ...(existsSync(ACTIONS) ? readdirSync(ACTIONS, { recursive: true }).map(String).filter((f) => /(?:^|\/)action\.ya?ml$/.test(f)).sort()
    .map((f) => ({ name: join(ACTIONS, f), path: join(ACTIONS, f) })) : []),
];
const files = inputs();
if (existsSync(ACTIONS) && readdirSync(ACTIONS, { recursive: true }).map(String).some((f) => /(?:^|\/)action\.ya?ml$/.test(f))
  && !files.some((f) => f.name.startsWith(`${ACTIONS}/`))) fail('local composite action manifests must be scanned');
const findings = scanAll(files.map((f) => ({ name: f.name, text: readFileSync(f.path, 'utf8') })));
if (process.argv.includes('--write-baseline')) {
  writeFileSync(BASELINE, formatBaseline(toBaseline(findings)));
  console.log(`wrote ${BASELINE} (${findings.length} entries); CI rejects it if it grew`);
  process.exit(0);
}
const baseline = JSON.parse(readFileSync(BASELINE, 'utf8'));
// growth is judged against the base branch's committed baseline (fail closed when the base ref is unavailable)
const baseRef = `origin/${process.env.GITHUB_BASE_REF || 'main'}`;
const mergeBase = execFileSync('git', ['merge-base', 'HEAD', baseRef], { encoding: 'utf8' }).trim();
let base = null;
try { base = JSON.parse(execFileSync('git', ['show', `${mergeBase}:${BASELINE}`], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })); }
catch (e) { if (!/does not exist|exists on disk, but not in/.test(String(e.stderr))) throw e; }
const grown = base ? growth(baseline, base) : [];
const { fresh, stale } = applyBaseline(findings, baseline);
const errors = problems(fresh, stale, grown, baseRef);
if (errors.length) fail(errors.join('\n'));
console.log(`workflow-secret-echo: ok (${findings.length} baselined finding(s), ${base ? 'ratchet vs ' + baseRef : 'baseline introduced in this change'})`);
