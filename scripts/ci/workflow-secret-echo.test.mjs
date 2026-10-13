// #1387: fail if any workflow can print DB credentials / .env content to the public Actions log.
// YAML is parsed (PyYAML, preinstalled on ubuntu runners) so folded/literal/quoted multi-line
// `run:` scalars are scanned as the single shell text GitHub actually executes.
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = '.github/workflows';
const FIXTURES = new Set(['ci-ephemeral', 'alltruepw', 'rootpw', 'root']); // non-sensitive CI-only DB fixtures
const SECRET_VAR = '[A-Za-z_]*(?:PASS(?:WORD)?|SECRET)[A-Za-z_]*'; // session tokens are add-mask'ed per workflow
const ENV_FILE = String.raw`\S*\.env(?!\.(?:example|sample|dist|template)\b)(?:\.[\w-]+)*(?=[\s;&|)"']|$)`;
// Each echo/printf is judged on its own text (up to the next `;`/`&&`/`||` after the secret var).
// Safe sinks: redirect to a real file (NOT `>&2`, `/dev/stderr`, `2>`), or a hash pipe. base64 is reversible, so not safe.
// stdout (`>`, `1>`, `&>`, `>>`) to a real path; not `2>`, `>&N`, process substitution `>(..)`, or /dev|/proc aliases (/dev/null is fine).
const FILE_REDIRECT = /(?:^|[^\d&>])(?:1|&)?>>?\s*(?!&|>?\(|\/dev\/(?!null\b)|\/proc\/)["']?[^\s"']/;
// output inside an outer `{ ...; } > file` group that can still reach the log (tee, >&N, device/proc paths, >(..))
const LEAKS_BACK = />&(?![1-]\b)|\btee\b|\/dev\/(?!null\b)|\/proc\/|>\(/;
// anywhere in the command: a stage that still writes to the log (tee /dev/stderr, >(..), /proc fd, >&2)
const LOG_ALIAS = /\/dev\/(?!null\b)|\/proc\/|>\(|>&(?![1-]\b)\d/;
// hash pipes, or a pipe into a client reading the secret from stdin (docker/helm login --password-stdin)
const HASH_PIPE = /\|\s*(?:sha\d*sum|shasum|md5sum|openssl\s+dgst)|\|[^|;&]*--password-stdin\b/;
const SECRET_RE = new RegExp(`\\$\\{?${SECRET_VAR}\\b|\\$\\{\\{[^}]*\\b${SECRET_VAR}\\b`, 'i');
function echoesSecret(line, grouped = false) {
  return line.split(/(?=\b(?:echo|printf)\b)/).filter((x) => /^(?:echo|printf)\b/.test(x)).some((seg) => {
    const m = SECRET_RE.exec(seg);
    if (!m) return false;
    const cut = seg.slice(m.index).search(/;|&&|\|\|/);
    const region = cut < 0 ? seg : seg.slice(0, m.index + cut);
    if (grouped && !LEAKS_BACK.test(region)) return false;
    if (LOG_ALIAS.test(region)) return true;
    return !FILE_REDIRECT.test(region) && !HASH_PIPE.test(region);
  });
}
const rules = [
  ['shell tracing', /(?:^|[;&\s])set\s+-[a-z]*x|\b(?:ba|da|z|k|a)?sh\s+(?:-\S+\s+)*-[a-z]*x|xtrace/],
  // option-only dumps (env -0, printenv --null) still print everything; printenv VAR is case-insensitive
  ['env dump', { test: (l) => /(?:^|[;&|]\s*|^\s*)(?:printenv|export\s+-p|env)(?:\s+-\S+)*\s*(?:$|[|;&])/.test(l)
    || new RegExp(`\\bprintenv\\s+(?:-\\S+\\s+)*\\$?${SECRET_VAR}\\b`, 'i').test(l) }],
  ['cat/print .env', new RegExp(String.raw`\b(?:cat|less|more|bat)\s+(?:-\S+\s+)*(?:0?<\s*)?` + ENV_FILE)],
  // only hashing / redirects to a file are safe; base64 is reversible so it is NOT exempt
  ['echo of secret var', { test: echoesSecret }], // called as test(line, grouped) below
];
// literal value assigned to a *PASS(WORD)* key (workflow env/yaml or .env heredoc) must be a known fixture
const literal = new RegExp(`^\\s*((?:[A-Z_]*_)?PASS(?:WORD|WD)?(?:_[A-Z_]*)?|[A-Z_]*PASSWORD[A-Z_]*)\\s*[:=]\\s*(['"]?)([^\\s'"$]+)\\2\\s*$`);

// Python: yield every parsed `run:` string and every PASS-named scalar, with a YAML path.
const PY = `
import sys, json, re, yaml
out = []
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
            # a matrix can feed a shell expression: inspect every matrix value as a possible shell
            if k == 'matrix': acc['shells'] += [[q, x] for x in leaves(v)]
            if k == 'run' and isinstance(v, str): acc['runs'].append([q, v])
            elif k == 'shell' and isinstance(v, (str, list)): acc['shells'] += [[q, x] for x in leaves(v)]
            elif re.search(r'(?:^|_)PASS(?:WORD|WD)?(?:_|$)|PASSWORD', str(k), re.I) and isinstance(v, (str, int, float)) and not isinstance(v, bool): acc['kvs'].append([q, str(k), str(v)])
            else: walk(v, q, acc)
    elif isinstance(n, list):
        for i, v in enumerate(n): walk(v, p + '[' + str(i) + ']', acc)
for it in json.load(sys.stdin):
    acc = {'name': it['name'], 'runs': [], 'shells': [], 'kvs': []}
    for doc in yaml.safe_load_all(it['text']): walk(doc, '', acc)
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

// Remove balanced $(...) that is the value of a plain assignment (X=$(...)); anything after it on the line stays.
function dropAssignCaptures(line) {
  let out = '', i = 0;
  const re = /(?:^|[;&|(\s])(?:export\s+)?\w+=["']?\$\(/g;
  for (let m; (m = re.exec(line)); ) {
    let d = 0, j = m.index + m[0].length - 1;
    for (; j < line.length; j++) { if (line[j] === '(') d++; else if (line[j] === ')' && --d === 0) break; }
    out += line.slice(i, m.index); i = j + 1; re.lastIndex = i;
  }
  return out + line.slice(i);
}

function scanScript(where, script) {
  const bad = [];
  // join backslash continuations so a split command is matched as one
  const lines = script.replace(/\\\r?\n\s*/g, ' ').split('\n');
  // lines inside a `{ ...; } > file` group (own-line braces) are redirected to a file, not the log
  const grouped = new Set();
  const open = [];
  lines.forEach((l, i) => {
    if (/^\s*\{\s*$/.test(l)) open.push(i);
    else if (/^\s*\}/.test(l) && open.length) {
      const start = open.pop();
      if (FILE_REDIRECT.test(l.replace(/^\s*\}/, ''))) for (let k = start; k <= i; k++) grouped.add(k);
    }
  });
  lines.forEach((raw, i) => {
    const line = raw.replace(/^\s*#.*/, '')
      // ponytail: only the add-mask command is exempt, not the rest of the line
      .replace(/(^\s*(?:-\s*)?(?:run:\s*)?|[;&|]\s*)(?:echo|printf)\s+['"]?::add-mask::[^;&|\n]*/g, '$1');
    // plain assignment from a capture (X=$(cat .env)) is not printed, but a later command on the line still is
    const noAssign = dropAssignCaptures(line);
    for (const [why, re] of rules) {
      const subject = why === 'cat/print .env' ? noAssign : why === 'echo of secret var' ? dropQuietCaptures(line) : line;
      if (re.test(subject, grouped.has(i))) bad.push(`${where} line ${i + 1} ${why}: ${raw.trim()}`);
    }
    const m = line.match(literal);
    if (m && !m[3].includes('{{') && !FIXTURES.has(m[3])) bad.push(`${where} line ${i + 1} literal credential: ${m[1]}=<redacted>`);
  });
  return bad;
}

function scanAll(items) {
  const parsed = JSON.parse(execFileSync('python3', ['-c', PY], { input: JSON.stringify(items), maxBuffer: 1 << 28 }));
  const raw = Object.fromEntries(items.map((i) => [i.name, i.text]));
  return parsed.flatMap(({ name, runs, shells, kvs }) => [
    // raw-text pass (superset of the #3884 scanner): catches tracing / env dumps / literals in env:, with:, defaults, inputs
    // and anywhere else the YAML walker does not treat as a `run:` script
    ...scanScript(`${name}(raw)`, raw[name]),
    ...runs.flatMap(([p, s]) => scanScript(`${name}${p}`, s)),
    // `shell: bash -x {0}` traces every command of its step/job/workflow, including secret-bearing ones
    ...shells.filter(([, v]) => rules[0][1].test(v)).map(([p, v]) => `${name}${p} shell tracing: shell: ${v}`),
    ...kvs.flatMap(([p, k, v]) => (FIXTURES.has(v) || v.includes('{{') ? [] : [`${name}${p} literal credential: ${k}=<redacted>`])),
  ]);
}
export const scan = (name, text) => scanAll([{ name, text }]);

// self-test: each bypass reported on #3884 (Codex review 5484099102) must trip; benign forms must not
const wf = (run, indent = '') => `jobs:\n  j:\n    steps:\n      - run: ${run}`.replace(/\n/g, `\n${indent}`);
export const trip = {
  // parser-differential forms: the scanner must see what GitHub Actions executes
  'yaml anchor/alias run': 'jobs:\n  j:\n    steps:\n      - run: &r |\n          echo "$DB_PASSWORD"\n      - run: *r',
  'yaml block chomp |-': wf('|-\n          echo "$DB_PASSWORD"'),
  'yaml folded >+': wf('>+\n          echo\n          "$DB_PASSWORD"'),
  'yaml flow-style map': 'jobs: {j: {steps: [{run: "echo $DB_PASSWORD"}]}}',
  'yaml multi-document': 'a: 1\n---\n' + wf('|\n          echo "$DB_PASSWORD"'),
  'yaml quoted keys': 'jobs:\n  j:\n    steps:\n      - "shell": bash -x {0}\n        \'run\': mysql -p"$DB_PASSWORD" -e x',
  'shell expression, traced default': 'on:\n  workflow_dispatch:\n    inputs:\n      sh:\n        default: bash -x {0}\njobs:\n  j:\n    steps:\n      - shell: ${{ inputs.sh }}\n        run: echo hi',
  'env SHELLOPTS=xtrace': 'jobs:\n  j:\n    env:\n      SHELLOPTS: xtrace\n    steps:\n      - run: echo hi',
  'with.script set -x': 'jobs:\n  j:\n    steps:\n      - uses: x/y@v1\n        with:\n          script: |\n            set -x',
  'echo of ${{ secrets.*PASSWORD }}': wf('|\n          echo "${{ secrets.DB_PASSWORD }}"'),
  'redirect to process substitution': wf('|\n          echo "$DB_PASSWORD" > >(cat)'),
  'redirect to /proc/self/fd/2': wf('|\n          echo "$DB_PASSWORD" > /proc/self/fd/2'),
  'redirect to /dev/fd/2': wf('|\n          echo "$DB_PASSWORD" >/dev/fd/2'),
  'group to file, inner tee /dev/stderr': wf('|\n          {\n            echo "$DB_PASSWORD" | tee /dev/stderr\n          } > "$F"'),
  'group to file, inner >&2': wf('|\n          {\n            echo "$DB_PASSWORD" >&2\n          } > "$F"'),
  'matrix shell list': 'jobs:\n  j:\n    strategy:\n      matrix:\n        shell: ["bash -x {0}"]\n    steps:\n      - shell: ${{ matrix.shell }}\n        run: echo hi',
  'matrix include shell': 'jobs:\n  j:\n    strategy:\n      matrix:\n        include:\n          - sh: "bash -o xtrace {0}"\n    steps:\n      - shell: ${{ matrix.sh }}\n        run: echo hi',
  'group redirected to stderr': wf('|\n          {\n            echo "$DB_PASSWORD"\n          } >&2'),
  'echo secret >&2': wf('|\n          echo "$DB_PASSWORD" >&2'),
  'echo secret 2>&1': wf('|\n          echo "$DB_PASSWORD" 2>&1'),
  'echo secret > /dev/stderr': wf('|\n          echo "$DB_PASSWORD" > /dev/stderr'),
  'printf %q of secret': wf("|\n          printf 'DB_PASSWORD=%q\\n' \"$DB_PASSWORD\""),
  'secret echo then unrelated redirect': wf('|\n          echo "$DB_PASSWORD"; echo hi > out.txt'),
  'secret inside quoted ;': wf('|\n          echo "a; $DB_PASSWORD"'),
  'shell: bash -x (step)': 'jobs:\n  j:\n    steps:\n      - shell: bash -x {0}\n        run: mysql -p"$DB_PASSWORD" -e "SELECT 1"',
  'shell: bash -o xtrace (job defaults)': 'jobs:\n  j:\n    defaults:\n      run:\n        shell: bash -eo xtrace {0}\n    steps:\n      - run: echo hi',
  'shell: sh -ex (workflow defaults)': 'defaults:\n  run:\n    shell: sh -ex {0}\njobs:\n  j:\n    steps:\n      - run: echo hi',
  'assignment then cat .env': wf('|\n          X="$(date)"; cat backend/.env'),
  'assignment then cat .env.production': wf('|\n          X=$(date) && cat .env.production'),
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
  'tee /dev/stderr before file sink': wf('|\n          echo "$DB_PASSWORD" | tee /dev/stderr > artifact'),
  'tee /proc fd before hash': wf('|\n          echo "$DB_PASSWORD" | tee /proc/self/fd/2 | sha256sum'),
  'env -0': wf('|\n          env -0'),
  'printenv --null': wf('|\n          printenv --null'),
  'printenv lowercase var': wf('|\n          printenv db_password'),
  'cat < .env': wf('|\n          cat < backend/.env'),
  'cat 0< .env': wf('|\n          cat 0< backend/.env'),
  '.env.production.local': wf('|\n          cat backend/.env.production.local'),
  'nested add-mask then secret': wf('|\n          echo "prefix $(echo ::add-mask::$TOKEN) $DB_PASSWORD"'),
  'with.script execSync cat .env': "jobs:\n  j:\n    steps:\n      - uses: actions/github-script@v7\n        with:\n          script: |\n            require('child_process').execSync('cat backend/.env', {stdio: 'inherit'})",
  'DB_PASSWD literal': 'env:\n  DB_PASSWD: hunter2',
  'P2 continued line': wf("|\n          printf '%s\\n' \"$DB_PASSWORD\" \\\n            ''"),
};
for (const [k, y] of Object.entries(trip)) if (!scan('probe', y).length) { console.error('guard missed probe:', k); process.exit(1); }
export const ok = {
  'BYPASS_REASON / COMPASS_MODE keys': 'env:\n  BYPASS_REASON: approved\n  COMPASS_MODE: north',
  'env -i command': wf('|\n          env -i PATH="$PATH" ./run.sh'),
  'tee to file then /dev/null': wf('|\n          echo "$DB_PASSWORD" | tee artifact > /dev/null'),
  'single-line run add-mask': 'jobs:\n  j:\n    steps:\n      - run: echo "::add-mask::$DB_PASSWORD"',
  'echo | docker login --password-stdin': wf('|\n          echo "$REGISTRY_PASSWORD" | docker login -u u --password-stdin ghcr.io'),
  'echo 1> file': wf('|\n          echo "$DB_PASSWORD" 1> artifact'),
  'echo &> file': wf('|\n          echo "$DB_PASSWORD" &> artifact'),
  'echo &>> file': wf('|\n          echo "$DB_PASSWORD" &>> artifact'),
  'echo > /dev/null': wf('|\n          echo "$DB_PASSWORD" > /dev/null'),
  'group to file, inner 2>&1': wf('|\n          {\n            echo "$DB_PASSWORD" 2>&1\n          } > "$F"'),
  'matrix shell without trace': 'jobs:\n  j:\n    strategy:\n      matrix:\n        shell: ["bash -e {0}"]\n    steps:\n      - shell: ${{ matrix.shell }}\n        run: echo hi',
  'group redirected to file': wf('|\n          {\n            echo "$DB_PASSWORD"\n          } > "$F"'),
  'printf %q to state file': wf("|\n          printf 'DB_PASSWORD=%q\\n' \"$DB_PASSWORD\" >> \"$STATE\""),
  'shell: bash -e (no trace)': 'jobs:\n  j:\n    steps:\n      - shell: bash -eo pipefail {0}\n        run: echo hi',
  'add-mask + fixture + capture assign': wf('|\n          echo "::add-mask::$DB_PASS"\n          DB_PASS="$(grep ^DB_PASSWORD= .env | cut -d= -f2-)"\n          X="$(cat .env)"') + '\nenv:\n  DB_PASSWORD: ci-ephemeral',
  'secret as arg inside non-echo capture': wf('|\n          echo "n=$(mysql -p"$db_pass" -e "SELECT COUNT(*) FROM t")"'),
  'hash / redirect / example': wf('|\n          echo "$DB_PASSWORD" | sha256sum\n          echo "$DB_PASSWORD" >> "$F"\n          cat .env.example\n          set +x'),
};
for (const [k, y] of Object.entries(ok)) if (scan('ok', y).length) { console.error('guard false positive:', k, scan('ok', y)); process.exit(1); }

const files = readdirSync(dir).filter((f) => /\.ya?ml$/.test(f));
const failures = scanAll(files.map((f) => ({ name: f, text: readFileSync(join(dir, f), 'utf8') })));
if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
console.log('workflow-secret-echo: ok');
