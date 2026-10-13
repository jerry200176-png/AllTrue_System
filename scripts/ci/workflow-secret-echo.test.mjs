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
  ['echo of secret var', new RegExp(`\\b(?:echo|printf)\\b(?![^\\n]*(?:>>?|\\|\\s*(?:sha|base64|openssl)))[^\\n]*\\$\\{?${SECRET_VAR}\\b`, 'i')],
];
// literal value assigned to a *PASSWORD* key (workflow env/yaml or .env heredoc) must be a known fixture
const literal = new RegExp(`^\\s*(${'[A-Z_]*PASSWORD[A-Z_]*'})\\s*[:=]\\s*(['"]?)([^\\s'"$]+)\\2\\s*$`);

export function scan(name, text) {
  const bad = [];
  text.split('\n').forEach((raw, i) => {
    // drop comments and $(...) captures: a secret used as a command arg inside capture is not printed
    const line = raw.replace(/^\s*#.*/, '').replace(/\$\(.*\)/g, "");
    if (/::add-mask::/.test(line)) return;
    // ponytail: `\` continuation (piped) and `=%q` (state-file group redirect) are not stdout echoes
    if (/\\$|=%q/.test(line)) return;
    for (const [why, re] of rules) if (re.test(line)) bad.push(`${name}:${i + 1} ${why}: ${raw.trim()}`);
    const m = line.match(literal);
    if (m && !m[3].includes('{{') && !FIXTURES.has(m[3])) bad.push(`${name}:${i + 1} literal credential: ${m[1]}=<redacted>`);
  });
  return bad;
}

// self-test: the guard itself must trip
const probes = ['set -x', 'cat backend/.env', '  printenv', 'echo "pw=$DB_PASSWORD"', 'DB_PASSWORD: hunter2x'];
for (const p of probes) if (!scan('probe', p).length) { console.error('guard missed probe:', p); process.exit(1); }
if (scan('ok', 'echo "::add-mask::$DB_PASS"\nDB_PASSWORD: ci-ephemeral\nDB_PASS="$(grep ^DB_PASSWORD= .env | cut -d= -f2-)"').length) {
  console.error('guard false positive'); process.exit(1);
}

const failures = readdirSync(dir).filter((f) => /\.ya?ml$/.test(f)).flatMap((f) => scan(f, readFileSync(join(dir, f), 'utf8')));
if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
console.log('workflow-secret-echo: ok');
