// #1387: fail if any workflow can print DB credentials / .env content to the public Actions log.
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = '.github/workflows';
const FIXTURES = new Set(['ci-ephemeral', 'alltruepw', 'rootpw', 'root']); // non-sensitive CI-only DB fixtures
const SECRET_VAR = '[A-Za-z_]*(?:PASS(?:WORD)?|SECRET)[A-Za-z_]*'; // session tokens are add-mask'ed per workflow
const rules = [
  ['shell tracing', /(?:^|[;&\s])set\s+-[a-z]*x|bash\s+-[a-z]*x|xtrace/],
  ['env dump', /(?:^|[;&|]\s*|^\s*)(?:printenv|export\s+-p|env)\s*(?:$|[|;&])/],
  ['cat/print .env', /\b(?:cat|less|more|bat)\s+(?:-\S+\s+)*\S*\.env\b(?!\.)/],
  ['here-string into a shell (#4029)', /\b(?:ba|z|da)?sh(?:\s+-\S+)*\s*<<</],
  ['indirect expansion (#4029)', /\b(?:echo|printf)\b[^\n]*\$\{![A-Za-z_]+\}/],
  ['let of a password var (#4029)', /\blet\s+\S*=\S*PASS/i],
  ['ps environment dump (#4030)', /\bps\s+(?!-)[a-z]*e[a-z]*\b/],
  ['/proc/<pid>/environ (#4030)', /\/proc\/[^\s/]+\/environ/],
  ['whole-env serialisation (#4033)', /JSON\.stringify\(\s*process\.env\s*\)/],
  ['destructured password env (#4033)', /\{[^}]*PASS(?:WORD)?[^}]*\}\s*=\s*process\.env/i],
  ['echo of secret var', new RegExp(`\\b(?:echo|printf)\\b(?![^\\n]*(?:>>?|\\|\\s*(?:sha|base64|openssl)))[^\\n]*\\$\\{?${SECRET_VAR}\\b`, 'i')],
];
// literal value assigned to a *PASSWORD* key (workflow env/yaml or .env heredoc) must be a known fixture
const literal = new RegExp(`^\\s*(${'[A-Z_]*PASSWORD[A-Z_]*'})\\s*[:=]\\s*(['"]?)([^\\s'"$]+)\\2\\s*$`);

const LOGGERS = /\b(?:echo|printf|cat|head|tail|less|more|xxd|strings|od|console\.\w+|core\.(?:info|warning|notice|error|debug|setOutput))\b/;
// Stateful rules, per file: staged files (#4031), env aliases of secrets (#4032), process.env aliases (#4033),
// python heredoc captures (#4036). ponytail: line-based, one file at a time; a YAML/AST parser if false negatives matter.
export function scan(name, text) {
  const bad = [];
  const lines = text.split('\n');
  const staged = new Set(), aliases = new Set(), envAliases = new Set(), captured = new Set();
  const flag = (i, why) => bad.push(`${name}:${i + 1} ${why}: ${lines[i].trim()}`);
  lines.forEach((raw, i) => {
    const am = raw.match(/^\s*([A-Za-z_]\w*):\s*\$\{\{\s*(?:secrets\.|steps\.[^}]*(?:pass|secret|token)[^}]*)/i);
    if (am) aliases.add(am[1]);
    const em = raw.match(/\b(?:const|let|var)\s+(\w+)\s*=\s*process\.env\s*;?\s*$/);
    if (em) envAliases.add(em[1]);
    const py = raw.match(/(\w+)="?\$\(\s*python3?\s+-\s*<<-?\s*['"]?(\w+)['"]?/);
    if (py) {
      for (let j = i + 1; j < lines.length && lines[j].trim() !== py[2]; j++) {
        if (new RegExp(`(?:environ|getenv)\\W*['"]?${SECRET_VAR}`, 'i').test(lines[j])) captured.add(py[1]);
      }
    }
    // drop comments and $(...) captures: a secret used as a command arg inside capture is not printed
    const line = raw.replace(/^\s*#.*/, '').replace(/\$\(.*\)/g, "");
    if (/::add-mask::/.test(line)) return;
    const wr = raw.match(new RegExp(`(?:\\b(?:echo|printf)\\b[^\\n>]*\\$\\{?${SECRET_VAR}[^\\n>]*|\\b(?:env|printenv)\\b)\\s*>\\s*(\\S+)`, 'i'));
    if (wr && !/^\/dev\/|GITHUB_/.test(wr[1])) staged.add(wr[1].replace(/['"]/g, ''));
    for (const f of staged) if (LOGGERS.test(line) && /\b(?:cat|head|tail|less|more|xxd|strings|od)\b/.test(line) && line.includes(f) && !/>/.test(line.split(f)[0])) flag(i, `staged secret file read (#4031)`);
    for (const a of aliases) if (new RegExp(`\\b(?:echo|printf)\\b[^\\n]*\\$\\{?${a}\\b[^\\n]*\\|\\s*(?:base64|rev|sed|xxd|tr|od)\\b(?![^\\n]*>)`).test(line)) flag(i, 'env alias of a secret printed through a transform (#4032)');
    for (const c of captured) if (new RegExp(`\\b(?:echo|printf)\\b[^\\n]*\\$\\{?${c}\\b`).test(line)) flag(i, 'python-captured secret printed (#4036)');
    for (const e of envAliases) if (new RegExp(`\\b${e}\\.${SECRET_VAR}|\\b${e}\\[`, 'i').test(line) && LOGGERS.test(line)) flag(i, 'process.env alias read for the log (#4033)');
    // ponytail: `\` continuation (piped) and `=%q` (state-file group redirect) are not stdout echoes
    if (/\\$|=%q/.test(line)) return;
    for (const [why, re] of rules) if (re.test(line)) bad.push(`${name}:${i + 1} ${why}: ${raw.trim()}`);
    const m = line.match(literal);
    if (m && !m[3].includes('{{') && !FIXTURES.has(m[3])) bad.push(`${name}:${i + 1} literal credential: ${m[1]}=<redacted>`);
  });
  return bad;
}

// self-test: the guard itself must trip
const probes = ['set -x', 'cat backend/.env', '  printenv', 'echo "pw=$DB_PASSWORD"', 'DB_PASSWORD: hunter2x',
  'name=DB_PASSWORD; echo "${!name}"', 'bash -s <<< printenv', 'let x=DB_PASSWORD', 'ps eww $$', 'ps auxe', 'strings /proc/$$/environ',
  'echo "$DB_PASSWORD" > /tmp/pw\ncat /tmp/pw', 'env > /tmp/e\ncat /tmp/e', 'env:\n  VALUE: ${{ secrets.DB_PASSWORD }}\nrun: echo "$VALUE" | base64',
  'const { DB_PASSWORD } = process.env; core.info(DB_PASSWORD)', 'const e = process.env;\nconsole.log(e.DB_PASSWORD)', 'core.info(JSON.stringify(process.env))',
  `X="$(python3 - <<'PY'\nimport os; print(os.environ['DB_PASSWORD'])\nPY\n)"; echo "$X"`];
for (const p of probes) if (!scan('probe', p).length) { console.error('guard missed probe:', p); process.exit(1); }
if (scan('ok', 'echo "::add-mask::$DB_PASS"\nDB_PASSWORD: ci-ephemeral\nDB_PASS="$(grep ^DB_PASSWORD= .env | cut -d= -f2-)"\nps -ef\nps aux\necho "$DB_PASSWORD" > /tmp/pw\nmysql --defaults-extra-file=/tmp/pw\nVALUE: ${{ secrets.X }}\necho "$VALUE" | sha256sum\nconst e = process.env; core.info(e.HOME)').length) {
  console.error('guard false positive'); process.exit(1);
}

const failures = readdirSync(dir).filter((f) => /\.ya?ml$/.test(f)).flatMap((f) => scan(f, readFileSync(join(dir, f), 'utf8')));
if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
console.log('workflow-secret-echo: ok');
