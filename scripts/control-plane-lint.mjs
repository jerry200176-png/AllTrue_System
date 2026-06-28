#!/usr/bin/env node
/**
 * Control plane contract linter — machine-enforces CONTROL_PLANE_CONTRACT.md I1–I5.
 * Exit 0 = PASS, 1 = FAIL.
 */
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

/** @typedef {{ rule: string, file: string, line: number, message: string }} Violation */
/** @type {Violation[]} */
const violations = [];

const selfTest = process.argv.includes('--self-test');
const contractChangeOk =
  process.env.CONTRACT_CHANGE === '1' ||
  process.env.CONTRACT_CHANGE === 'true' ||
  (process.env.PR_TITLE || '').includes('[contract-change]');

const REQUIRED = [
  'docs/CONTROL_PLANE_CONTRACT.md',
  'docs/CONTRADICTION_REGISTRY.md',
  'docs/CONTROL_PLANE_AUDIT.md',
  'docs/CONTROL_PLANE_ENFORCER.md',
  'docs/INCIDENT_START_HERE.md',
  'docs/INCIDENT_INFERENCE_ENGINE.md',
  'docs/INCIDENT_STATE_MACHINE.md',
  'docs/INCIDENT_POLICY_ENGINE.md',
  'docs/INCIDENT_RUNTIME_LOOP.md',
  '.github/workflows/deploy.yml',
];

const INCIDENT_STACK = REQUIRED.filter((f) => f.startsWith('docs/INCIDENT_'));
const DECISION_PATH = ['docs/CONTROL_PLANE_CONTRACT.md', ...INCIDENT_STACK];

const DEMOTED = [
  { file: 'docs/SEVERITY_MATRIX.md', slug: 'SEVERITY_MATRIX' },
  { file: 'docs/RUNBOOK_ROLLBACK.md', slug: 'RUNBOOK_ROLLBACK' },
  { file: 'docs/SMOKE_TEST_RUNBOOK.md', slug: 'SMOKE_TEST' },
  { file: 'docs/OPERATIONAL_CONSTRAINTS.md', slug: 'OPERATIONAL_CONSTRAINTS' },
];

const MEMPALACE_STATEMENT =
  'MemPalace is a non-production, best-effort local system. It has no incident authority, no SLO, and no execution impact on production.';

const FORBIDDEN_DECISION_PHRASES = [
  /operator intuition/i,
  /feeling-based/i,
  /interpret freely/i,
  /manual override except emergency(?!\s*\(undefined\))/i,
];

const INDEX_FORBIDDEN = [/Operational SSOT/i, /INDEX is the.*authority/i];

const INDEX_DEPLOY_BEHAVIOR = [
  /\brolls back\b/i,
  /\bSSH deploy\b/i,
  /\bauto-rollback\b/i,
];

const INDEX_GOVERNANCE_LOGIC = [
  /\bdecision logic\b/i,
  /\bpolicy engine decides\b/i,
  /\boverride policy\b/i,
  /\bincident resolution logic\b/i,
  /\bsystem decides\b/i,
  /\bmust deploy\b/i,
];

/** Tracked workflows allowed to SSH to Pi for non-deploy purposes */
const SSH_WORKFLOW_ALLOWLIST = new Set([
  'deploy.yml',
  'teacher-signin-recovery.yml',
  'teacher-signin-diagnose.yml',
  'pi-health.yml',
]);

/** Patterns indicating production deploy (git reset origin/main on Pi) */
const PRODUCTION_DEPLOY_MARKERS = [
  /git reset --hard origin\/main/i,
  /git fetch origin main/i,
];

function read(rel) {
  return fs.readFileSync(path.join(root, rel), 'utf8');
}

function exists(rel) {
  return fs.existsSync(path.join(root, rel));
}

function gitTracked(rel) {
  try {
    execSync(`git ls-files --error-unmatch "${rel}"`, { cwd: root, stdio: 'pipe' });
    return true;
  } catch {
    return false;
  }
}

function add(rule, file, line, message) {
  violations.push({ rule, file, line, message });
}

function linesWithMatches(text, patterns) {
  const hits = [];
  text.split('\n').forEach((line, i) => {
    for (const p of patterns) {
      if (p.test(line)) hits.push({ line: i + 1, text: line.trim(), pattern: p });
    }
  });
  return hits;
}

function checkRequiredFiles() {
  for (const f of REQUIRED) {
    if (!exists(f)) add('E0', f, 0, 'missing required control plane file');
  }
}

function checkContractInvariants() {
  const contract = read('docs/CONTROL_PLANE_CONTRACT.md');
  for (const id of ['I1', 'I2', 'I3', 'I4', 'I5']) {
    if (!contract.includes(`**${id}**`)) {
      add('E-CONTRACT', 'docs/CONTROL_PLANE_CONTRACT.md', 0, `missing invariant ${id}`);
    }
  }
  if (!contract.includes('POLICY > STATE > SIGNAL')) {
    add('E4', 'docs/CONTROL_PLANE_CONTRACT.md', 0, 'missing POLICY > STATE > SIGNAL precedence');
  }
  if (!contract.includes(MEMPALACE_STATEMENT)) {
    add('E7', 'docs/CONTROL_PLANE_CONTRACT.md', 0, 'missing MemPalace frozen statement');
  }
}

function checkContradictionRegistry() {
  const reg = read('docs/CONTRADICTION_REGISTRY.md');
  for (let i = 1; i <= 10; i++) {
    if (!reg.includes(`**K${i}**`) && !reg.includes(`| **K${i}**`)) {
      add('E6', 'docs/CONTRADICTION_REGISTRY.md', 0, `missing conflict entry K${i}`);
    }
  }
}

function checkDemotedBanners() {
  for (const { file } of DEMOTED) {
    if (!exists(file)) continue;
    const head = read(file).split('\n').slice(0, 15).join('\n');
    if (!head.includes('REFERENCE ONLY')) {
      add('E5', file, 1, 'demoted module missing REFERENCE ONLY banner');
    }
  }
}

function checkIndexI2() {
  const index = read('docs/INDEX.md');
  for (const hit of linesWithMatches(index, INDEX_FORBIDDEN)) {
    add('I2', 'docs/INDEX.md', hit.line, `forbidden INDEX authority phrase: ${hit.text}`);
  }
  index.split('\n').forEach((line, i) => {
    if (line.includes('see deploy.yml') || line.includes('→ see deploy')) return;
    for (const p of INDEX_DEPLOY_BEHAVIOR) {
      if (p.test(line) && !line.includes('REFERENCE') && !line.includes('registry')) {
        add('I2', 'docs/INDEX.md', i + 1, `INDEX deploy behavior prose: ${line.trim()}`);
      }
    }
  });
  if (index.includes('deploy-production.yml') && gitTracked('.github/workflows/deploy-production.yml')) {
    add('I1', 'docs/INDEX.md', 0, 'INDEX references deploy-production.yml as tracked — dual execution authority');
  }
}

function checkDecisionPathE3E5() {
  for (const file of DECISION_PATH) {
    if (!exists(file)) continue;
    const text = read(file);
    text.split('\n').forEach((line, i) => {
      for (const p of FORBIDDEN_DECISION_PHRASES) {
        if (p.test(line) && !line.includes('Forbidden') && !line.includes('invalid') && !/^\| \"/.test(line.trim())) {
          add('I5', file, i + 1, `forbidden decision phrase: ${line.trim()}`);
        }
      }
      for (const { slug } of DEMOTED) {
        if (
          line.includes('Forbidden') ||
          line.includes('MUST NOT') ||
          line.includes('reference only') ||
          /not a decision authority/i.test(line) ||
          /\|\s*Decision authority/i.test(line)
        )
          continue;
        if (
          new RegExp(`${slug}.*decision authority|decision authority.*${slug}`, 'i').test(line)
        ) {
          add('I3', file, i + 1, `demoted module ${slug} cited as decision authority`);
        }
      }
    });
  }
}

function checkPolicyI4() {
  const policy = read('docs/INCIDENT_POLICY_ENGINE.md');
  if (!policy.includes('I4') && !policy.includes('execution layer')) {
    add('I4', 'docs/INCIDENT_POLICY_ENGINE.md', 0, 'policy engine missing I4 execution layer binding');
  }
}

function checkMemPalaceE7() {
  const files = [
    'docs/INDEX.md',
    'README.md',
    'docs/MEMPALACE_OPERATIONS_HANDBOOK.md',
    'docs/MEMPALACE_ARCHITECTURE_HEALTH.md',
  ];
  for (const f of files) {
    if (!exists(f)) {
      add('E7', f, 0, 'missing MemPalace canonical file');
      continue;
    }
    if (!read(f).includes(MEMPALACE_STATEMENT)) {
      add('E7', f, 1, 'missing MemPalace frozen statement');
    }
  }
}

function checkExecutionI1() {
  if (!gitTracked('.github/workflows/deploy.yml')) {
    add('I1', '.github/workflows/deploy.yml', 0, 'deploy.yml not tracked in git');
  }
  const altDeploy = ['.github/workflows/deploy-production.yml', '.github/workflows/deploy-staging.yml'];
  for (const wf of altDeploy) {
    if (!gitTracked(wf)) continue;
    for (const file of ['docs/INDEX.md', ...DECISION_PATH]) {
      if (!exists(file)) continue;
      const text = read(file);
      if (text.includes(wf.replace('.github/workflows/', '')) && !text.includes('historical') && !text.includes('ignore')) {
        add('I1', file, 0, `active reference to alternate deploy workflow ${wf}`);
      }
    }
  }
  checkTrackedProductionDeployWorkflows();
}

function checkTrackedProductionDeployWorkflows() {
  let tracked = [];
  try {
    tracked = execSync('git ls-files .github/workflows/*.yml', { cwd: root, encoding: 'utf8' })
      .split('\n')
      .filter(Boolean);
  } catch {
    return;
  }
  for (const rel of tracked) {
    const base = path.basename(rel);
    if (SSH_WORKFLOW_ALLOWLIST.has(base)) continue;
    const text = read(rel);
    const hasProdDeploy = PRODUCTION_DEPLOY_MARKERS.every((p) => p.test(text));
    const hasSsh = /ssh\s+-i|ENDSSH|\/home\/admin/.test(text);
    if (hasProdDeploy && hasSsh) {
      add(
        'I1',
        rel,
        0,
        'tracked workflow performs production deploy — only deploy.yml may execute production changes',
      );
    }
    if (/deploy-production|deploy-staging|ADR-001 production deploy/i.test(text) && !/\/archive\//.test(rel)) {
      add('I1', rel, 0, 'shadow deploy workflow must not be tracked under .github/workflows/');
    }
  }
}

function checkIndexGovernanceLogic() {
  if (!exists('docs/INDEX.md')) return;
  const index = read('docs/INDEX.md');
  for (const hit of linesWithMatches(index, INDEX_GOVERNANCE_LOGIC)) {
    add('I2', 'docs/INDEX.md', hit.line, `INDEX governance logic prose: ${hit.text}`);
  }
}

function checkIncidentExecutionBinding() {
  const policy = exists('docs/INCIDENT_POLICY_ENGINE.md') ? read('docs/INCIDENT_POLICY_ENGINE.md') : '';
  const start = exists('docs/INCIDENT_START_HERE.md') ? read('docs/INCIDENT_START_HERE.md') : '';
  if (policy && !/deploy\.yml only|deploy\.yml\` only|I1/i.test(policy)) {
    add('I4', 'docs/INCIDENT_POLICY_ENGINE.md', 0, 'missing FINAL_ACTION → deploy.yml-only execution binding');
  }
  if (start && !start.includes('deploy.yml')) {
    add('I3', 'docs/INCIDENT_START_HERE.md', 0, 'missing deploy.yml as execution endpoint reference');
  }
  const forbiddenExecPaths = [
    /deploy-production\.yml/i,
    /deploy-staging\.yml/i,
    /release-exec\.sh.*production/i,
  ];
  for (const file of INCIDENT_STACK) {
    if (!exists(file)) continue;
    const text = read(file);
    for (const p of forbiddenExecPaths) {
      if (p.test(text) && !/must not|forbidden|archive|historical/i.test(text)) {
        add('I1', file, 0, 'INCIDENT stack references shadow execution path');
      }
    }
  }
}

function checkCodeImportBarrier() {
  const codeGlobs = ['frontend/src', 'backend/app'];
  const demotedPatterns = DEMOTED.map((d) => d.slug);
  let tracked = [];
  try {
    tracked = execSync('git ls-files', { cwd: root, encoding: 'utf8' })
      .split('\n')
      .filter(Boolean);
  } catch {
    return;
  }
  const trackedSet = new Set(tracked);
  for (const dir of codeGlobs) {
    const full = path.join(root, dir);
    if (!fs.existsSync(full)) continue;
    walk(full, (filePath) => {
      const rel = path.relative(root, filePath).replace(/\\/g, '/');
      if (!trackedSet.has(rel)) return;
      if (!/\.(js|ts|mjs|cjs|py)$/.test(rel)) return;
      const text = fs.readFileSync(filePath, 'utf8');
      for (const slug of demotedPatterns) {
        if (text.includes(slug) && /import|require|from ['"].*docs\//.test(text)) {
          add('I3', rel, 0, `code imports/references demoted module ${slug} in decision context`);
        }
      }
    });
  }
}

function walk(dir, fn) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name);
    if (ent.name === 'node_modules' || ent.name === 'vendor') continue;
    if (ent.isDirectory()) walk(p, fn);
    else fn(p);
  }
}

function checkContractChangeGate() {
  if (contractChangeOk) return;
  try {
    const base = execSync('git merge-base HEAD origin/main 2>/dev/null || git rev-parse HEAD~1 2>/dev/null', {
      cwd: root,
      encoding: 'utf8',
    }).trim();
    const diff = execSync(`git diff --name-only ${base} HEAD 2>/dev/null; git diff --cached --name-only`, {
      cwd: root,
      encoding: 'utf8',
    });
    if (diff.includes('docs/CONTROL_PLANE_CONTRACT.md')) {
      const prev = execSync(`git show ${base}:docs/CONTROL_PLANE_CONTRACT.md 2>/dev/null || true`, {
        cwd: root,
        encoding: 'utf8',
      });
      if (prev.trim()) {
        add('E8', 'docs/CONTROL_PLANE_CONTRACT.md', 0, 'contract changed without CONTRACT_CHANGE=1 or [contract-change] tag');
      }
    }
  } catch {
    /* skip */
  }
}

function runSelfTest() {
  violations.push({
    rule: 'SELF-TEST',
    file: 'synthetic',
    line: 1,
    message: 'injected violation for self-test',
  });
}

function main() {
  if (selfTest) {
    runSelfTest();
    printReport();
    process.exit(1);
  }

  checkRequiredFiles();
  if (exists('docs/CONTROL_PLANE_CONTRACT.md')) {
    checkContractInvariants();
    checkContradictionRegistry();
    checkDemotedBanners();
    checkIndexI2();
    checkIndexGovernanceLogic();
    checkDecisionPathE3E5();
    checkIncidentExecutionBinding();
    checkPolicyI4();
    checkMemPalaceE7();
    checkExecutionI1();
    checkCodeImportBarrier();
    checkContractChangeGate();
  }

  printReport();
  process.exit(violations.length ? 1 : 0);
}

function printReport() {
  const status = violations.length ? 'FAIL' : 'PASS';
  console.log(`CONTROL PLANE LINT: ${status}`);
  console.log(`violations: ${violations.length}`);
  for (const v of violations) {
    console.log(`  [${v.rule}] ${v.file}:${v.line} — ${v.message}`);
  }
}

main();
