// #1387: fail if any workflow can print DB credentials / .env content to the public Actions log.
// YAML is parsed (PyYAML, preinstalled on ubuntu runners) so folded/literal/quoted multi-line
// `run:` scalars are scanned as the single shell text GitHub actually executes.
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = '.github/workflows';
const FIXTURES = new Set(['ci-ephemeral', 'alltruepw', 'rootpw', 'root']); // non-sensitive CI-only DB fixtures
const SECRET_VAR = '[A-Za-z_]*(?:PASS(?:WORD)?|SECRET)[A-Za-z_]*'; // session tokens are add-mask'ed per workflow
const ENV_FILE = String.raw`\S*\.env(?!\.(?:example|sample|dist|template)\b)(?:\.[\w-]+)?(?=[\s;&|)"']|$)`;
const rules = [
  ['shell tracing', /(?:^|[;&\s])set\s+-[a-z]*x|\b(?:ba|da|z|k|a)?sh\s+(?:-\S+\s+)*-[a-z]*x|xtrace/],
  ['env dump', new RegExp(`(?:^|[;&|]\\s*|^\\s*)(?:printenv|export\\s+-p|env)\\s*(?:$|[|;&])|\\bprintenv\\s+(?:-\\S+\\s+)*\\$?${SECRET_VAR}\\b`)],
  ['cat/print .env', new RegExp(String.raw`\b(?:cat|less|more|bat)\s+(?:-\S+\s+)*` + ENV_FILE)],
  // only hashing / redirects to a file are safe; base64 is reversible so it is NOT exempt
  ['echo of secret var', new RegExp(`\\b(?:echo|printf)\\b(?![^\\n]*(?:>>?|\\|\\s*(?:sha\\d*sum|shasum|md5sum|openssl\\s+dgst)))[^\\n]*\\$\\{?${SECRET_VAR}\\b`, 'i')],
];
// literal value assigned to a *PASS(WORD)* key (workflow env/yaml or .env heredoc) must be a known fixture
const literal = new RegExp(`^\\s*([A-Z_]*PASS(?:WORD)?[A-Z_]*)\\s*[:=]\\s*(['"]?)([^\\s'"$]+)\\2\\s*$`);

// Python: yield every parsed `run:` string and every PASS-named scalar, with a YAML path.
const PY = `
import sys, json, re, yaml
out = []
def walk(n, p, acc):
    if isinstance(n, dict):
        for k, v in n.items():
            q = p + '.' + str(k)
            if k == 'run' and isinstance(v, str): acc['runs'].append([q, v])
            elif re.search(r'PASS', str(k), re.I) and isinstance(v, (str, int, float)) and not isinstance(v, bool): acc['kvs'].append([q, str(k), str(v)])
            else: walk(v, q, acc)
    elif isinstance(n, list):
        for i, v in enumerate(n): walk(v, p + '[' + str(i) + ']', acc)
for it in json.load(sys.stdin):
    acc = {'name': it['name'], 'runs': [], 'kvs': []}
    walk(yaml.safe_load(it['text']), '', acc)
    out.append(acc)
json.dump(out, sys.stdout)
`;

// Drop balanced $(...) spans that do not themselves echo/printf: a secret used as an argument to a
// capturing command (mysql -p"$db_pass") is not printed. Spans that echo, or any other rule, still see the text.
function dropQuietCaptures(line) {
  let out = '';
  for (let i = 0; i < line.length; i++) {
    if (line[i] === '$' && line[i + 1] === '(') {
      let d = 0, j = i + 1;
      for (; j < line.length; j++) { if (line[j] === '(') d++; else if (line[j] === ')' && --d === 0) break; }
      const inner = line.slice(i + 2, j);
      if (!/\b(?:echo|printf)\b/.test(inner)) { i = j; continue; }
    }
    out += line[i];
  }
  return out;
}

function scanScript(where, script) {
  const bad = [];
  // join backslash continuations so a split command is matched as one
  script.replace(/\\\r?\n\s*/g, ' ').split('\n').forEach((raw, i) => {
    const line = raw.replace(/^\s*#.*/, '')
      // ponytail: only the add-mask command is exempt, not the rest of the line
      .replace(/\b(?:echo|printf)\s+['"]?::add-mask::[^;&|\n]*/g, '');
    // plain assignment from a capture ($(cat .env)) is not printed; a capture inside echo is still scanned
    const assign = /^\s*(?:export\s+)?\w+=["']?\$\(/.test(line);
    // `=%q` (state-file group redirect) is not a stdout echo
    if (/=%q/.test(line)) return;
    for (const [why, re] of rules) {
      if (assign && why === 'cat/print .env') continue;
      if (re.test(why === 'echo of secret var' ? dropQuietCaptures(line) : line)) bad.push(`${where} line ${i + 1} ${why}: ${raw.trim()}`);
    }
    const m = line.match(literal);
    if (m && !m[3].includes('{{') && !FIXTURES.has(m[3])) bad.push(`${where} line ${i + 1} literal credential: ${m[1]}=<redacted>`);
  });
  return bad;
}

function scanAll(items) {
  const parsed = JSON.parse(execFileSync('python3', ['-c', PY], { input: JSON.stringify(items), maxBuffer: 1 << 28 }));
  return parsed.flatMap(({ name, runs, kvs }) => [
    ...runs.flatMap(([p, s]) => scanScript(`${name}${p}`, s)),
    ...kvs.flatMap(([p, k, v]) => (FIXTURES.has(v) || v.includes('{{') ? [] : [`${name}${p} literal credential: ${k}=<redacted>`])),
  ]);
}
export const scan = (name, text) => scanAll([{ name, text }]);

// self-test: each bypass reported on #3884 (Codex review 5484099102) must trip; benign forms must not
const wf = (run, indent = '') => `jobs:\n  j:\n    steps:\n      - run: ${run}`.replace(/\n/g, `\n${indent}`);
const trip = {
  'set -x': wf('|\n          set -x'),
  'cat .env': wf('|\n          cat backend/.env'),
  printenv: wf('|\n          printenv'),
  'echo secret': wf('|\n          echo "pw=$DB_PASSWORD"'),
  'literal PASSWORD': 'env:\n  DB_PASSWORD: hunter2x',
  'P2 command substitution': wf('|\n          echo "$(cat backend/.env)"'),
  'P2 base64': wf('|\n          echo "$DB_PASSWORD" | base64'),
  'P2 .env.production': wf('|\n          cat .env.production'),
  'P2 backend/.env.staging': wf('|\n          cat backend/.env.staging'),
  'P2 DB_PASS literal': 'env:\n  DB_PASS: hunter2',
  'P2 sh -x': wf('|\n          sh -x deploy.sh'),
  'P2 dash -x': wf('|\n          dash -x deploy.sh'),
  'P2 zsh -x': wf('|\n          zsh -x deploy.sh'),
  'P2 add-mask same line': wf('|\n          echo "::add-mask::$TOKEN"; echo "$DB_PASSWORD"'),
  'P2 printenv VAR': wf('|\n          printenv DB_PASSWORD'),
  'P2 folded >': wf('>\n          echo\n          "$DB_PASSWORD"'),
  'P2 folded >-': wf('>-\n          cat\n          backend/.env'),
  'P2 continued line': wf("|\n          printf '%s\\n' \"$DB_PASSWORD\" \\\n            ''"),
};
for (const [k, y] of Object.entries(trip)) if (!scan('probe', y).length) { console.error('guard missed probe:', k); process.exit(1); }
const ok = {
  'add-mask + fixture + capture assign': wf('|\n          echo "::add-mask::$DB_PASS"\n          DB_PASS="$(grep ^DB_PASSWORD= .env | cut -d= -f2-)"\n          X="$(cat .env)"') + '\nenv:\n  DB_PASSWORD: ci-ephemeral',
  'secret as arg inside non-echo capture': wf('|\n          echo "n=$(mysql -p"$db_pass" -e "SELECT COUNT(*) FROM t")"'),
  'hash / redirect / example': wf('|\n          echo "$DB_PASSWORD" | sha256sum\n          echo "$DB_PASSWORD" >> "$F"\n          cat .env.example\n          set +x'),
};
for (const [k, y] of Object.entries(ok)) if (scan('ok', y).length) { console.error('guard false positive:', k, scan('ok', y)); process.exit(1); }

const files = readdirSync(dir).filter((f) => /\.ya?ml$/.test(f));
const failures = scanAll(files.map((f) => ({ name: f, text: readFileSync(join(dir, f), 'utf8') })));
if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
console.log('workflow-secret-echo: ok');
