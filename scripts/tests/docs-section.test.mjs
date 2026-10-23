import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync, spawn } from 'node:child_process';
import { headings, section, readSection } from '../ci/docs-section.mjs';

const root = path.resolve(import.meta.dirname, '../..');
test('whole applicable section includes nested exceptions and references, not a summary', () => {
  const source = '# Core\nlimit\n## Exception\nallowed only by source X\n# Other\nunrelated';
  assert.equal(section(source, 'Core').text, '# Core\nlimit\n## Exception\nallowed only by source X');
  assert.throws(() => section(source, 'Missing'), /exactly once/);
  assert.throws(() => section('# Same\na\n# Same\nb', 'Same'), /exactly once/);
});
test('code fences cannot terminate a required section', () => {
  const source = '# Rule\n````md\n# Fake\n```\n````\n~~~\n# Fake2\n~~~\n## Exception\nstill required\n# Next';
  assert.deepEqual(headings(source).map(h => h.title), ['Rule', 'Exception', 'Next']);
  assert.match(section(source, 'Rule').text, /still required/);
});
test('commented headings cannot hide a required exception', () => {
  const source = '# Rule\n<!--\n# Fake\n-->\n## Exception\nrequired\n# Next';
  assert.deepEqual(headings(source).map(h => h.title), ['Rule', 'Exception', 'Next']);
  assert.match(section(source, 'Rule').text, /required/);
});
test('CLI output through a slow pipe retains long source sections in full', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-docs-long-'));
  try {
    fs.mkdirSync(path.join(dir, 'docs'));
    fs.writeFileSync(path.join(dir, 'docs/long.md'), '# Rule\n' + 'retained\n'.repeat(200000));
    execFileSync('git', ['init', '-q', dir]);
    execFileSync('git', ['add', 'docs/long.md'], { cwd: dir });
    execFileSync('git', ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture'], { cwd: dir });
    const script = `import { writeSection } from ${JSON.stringify(new URL('../ci/docs-section.mjs', import.meta.url).href)}; await writeSection(process.argv[1], ['--section', 'docs/long.md', 'Rule']); process.exit(0);`;
    const child = spawn('node', ['--input-type=module', '-e', script, dir]);
    let actual = '', stderr = '';
    child.stdout.pause();
    child.stdout.on('data', data => { actual += data; });
    child.stderr.on('data', data => { stderr += data; });
    const done = new Promise((resolve, reject) => {
      child.once('error', reject);
      child.once('close', code => code === 0 ? resolve() : reject(new Error(stderr)));
    });
    // Fill the nonblocking pipe before consuming, rather than relying on timing.
    await new Promise(resolve => setTimeout(resolve, 100));
    child.stdout.resume();
    await done;
    assert.equal(actual, readSection(dir, ['--section', 'docs/long.md', 'Rule']));
    assert.ok(Buffer.byteLength(actual) > 65536, 'exercise a source larger than a pipe buffer');
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});
test('current source wins over a stale reference; provenance is explicit', () => {
  const output = readSection(root, ['--section', 'docs/INDEX.md', '前端開發', 'old-hash']);
  assert.match(output, /^STALE source reference:/);
  assert.match(output, /HEAD=[a-f0-9]{40} source-sha256=[a-f0-9]{64} tree=/);
  assert.match(output, /lines=\d+-\d+/);
  assert.match(output, /RULE_DESIGN_SYSTEM.md/);
  assert.doesNotMatch(output, /## 📁 docs\/archive/);
});
test('hidden canonical rules work; secrets, untracked files and symlinks do not', () => {
  assert.match(readSection(root, ['--outline', '.cursor/rules/p0-gate.mdc']), /p0-gate/);
  for (const file of ['../AGENTS.md', '.env', 'docs/.env.private.md', '.cursor/.local/test-credentials.md', 'docs/../.env', 'docs/untracked-missing.md']) {
    assert.throws(() => readSection(root, ['--outline', file]));
  }
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-docs-section-'));
  try {
    fs.mkdirSync(path.join(dir, 'docs'));
    fs.writeFileSync(path.join(dir, 'private'), 'must not appear');
    fs.symlinkSync('../private', path.join(dir, 'docs/escape.md'));
    execFileSync('git', ['init', '-q', dir]);
    execFileSync('git', ['add', 'docs/escape.md'], { cwd: dir });
    assert.throws(() => readSection(dir, ['--outline', 'docs/escape.md']), /canonical source/);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});

// Expected source sets come from baseline AGENTS conditional branches and INDEX
// task rows (21426577), not a new classifier. Decisions are evaluated separately
// by the existing harness/deploy tests and the report's policy cases.
const cases = [
  ['UI', [['docs/INDEX.md', '前端開發']], ['RULE_DESIGN_SYSTEM.md', 'module-frontend.mdc']],
  ['schedule', [['docs/INDEX.md', '後端開發'], ['docs/INDEX.md', '前端開發'], ['AGENTS.md', '條件分支（依任務類型挑對應章節，不用全讀）']], ['RFC_SCHEDULE_OCCURRENCE_IDENTITY.md', 'R116']],
  ['billing', [['docs/INDEX.md', '後端開發'], ['AGENTS.md', '條件分支（依任務類型挑對應章節，不用全讀）']], ['DIRECTOR_PAYMENT_ALERT_RULES.md', 'RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md']],
  ['permission', [['docs/INDEX.md', '資安審查']], ['module-security.mdc', 'PARENT_BINDING_THREAT_MODEL.md']],
  ['in-app', [['AGENTS.md', '條件分支（依任務類型挑對應章節，不用全讀）']], ['alltrue-inapp-product-loop/SKILL.md', 'R51', 'R53']],
  ['release', [['docs/INDEX.md', '部署 / 維運']], ['deploy.yml', 'RUNBOOK_ROLLBACK.md']],
  ['UI with money and permission effects', [['docs/INDEX.md', '前端開發'], ['docs/INDEX.md', '後端開發'], ['docs/INDEX.md', '資安審查']], ['RULE_DESIGN_SYSTEM.md', 'DIRECTOR_PAYMENT_ALERT_RULES.md', 'module-security.mdc']],
  ['restart continuity', [['docs/governance/RISK_BASED_MERGE_POLICY.md', 'Approval continuity within the authorized task']], ['session restart', 'continue independent authorized work']],
  ['superseded merge limit', [['docs/governance/FOUNDER_STANDING_DECISIONS.md', '2. 合併與 R3 上線分批']], ['**(a) 合併**', '取代', '不取代']],
  ['old safety still applicable', [['docs/INDEX.md', '🚨 開工前必讀（每次都要）'], ['.cursor/rules/p0-gate.mdc', '⛔ 5 條紅線（違反 = P0 故障，零容忍）']], ['token-discipline.md', 'phpunit']],
];
for (const [name, reads, expected] of cases) test(`policy source coverage: ${name}`, () => {
  const text = reads.map(([file, title]) => section(fs.readFileSync(path.join(root, file), 'utf8'), title).text).join('\n');
  for (const ref of expected) assert.ok(text.includes(ref), `${name}: missing ${ref}`);
});

// Opt-in measurement, no budget assertion or extra PR workflow. Same non-INDEX
// rules are held constant; this measures navigation payload, not model tokens.
if (process.env.CONTEXT_BASE) test('navigation byte and latency sample', () => {
  const before = execFileSync('git', ['show', `${process.env.CONTEXT_BASE}:docs/INDEX.md`], { cwd: root, encoding: 'utf8' });
  const current = fs.readFileSync(path.join(root, 'docs/INDEX.md'), 'utf8');
  const start = section(current, 'Start here (task navigation)').text + '\n' + section(current, '🚨 開工前必讀（每次都要）').text;
  const rows = cases.map(([name, reads]) => {
    const relevant = [...new Set(reads.filter(([f]) => f === 'docs/INDEX.md').map(([, h]) => h))];
    const text = start + '\n' + relevant.map(h => section(current, h).text).join('\n');
    return { name, before_bytes: Buffer.byteLength(before), after_bytes: Buffer.byteLength(text) };
  });
  const samples = [];
  for (let i = 0; i < 6; i++) {
    const t = performance.now();
    const out = readSection(root, ['--section', 'docs/INDEX.md', 'Start here (task navigation)']);
    samples.push({ ms: +(performance.now() - t).toFixed(2), output_bytes: Buffer.byteLength(out) });
  }
  console.log(JSON.stringify({ navigation: rows, first: samples[0], subsequent: samples.slice(1) }));
});
