#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const workflowDir = path.join(root, '.github/workflows');
const inventoryPath = path.join(root, 'docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json');
const selfTest = process.argv.includes('--self-test');

const categories = new Set([
  'deploy',
  'pop-controlled',
  'read-only-probe',
  'in-app-lifecycle-writeback',
  'legacy-case-specific-mutation',
  'non-production',
  'archive-deprecated',
]);
const markerPattern = /ssh\s+-i|ENDSSH|\/home\/admin|\bPI_(?:SSH_)?(?:HOST|USER|KEY)\b|\bphp\s+artisan\b|\brsync\b|\bscp\b/i;
const directProductionWritePattern = /php\s+(?:\/[^\s]+\/)?artisan\s+[^\n]*\s(?:--execute|--apply)\b|ALTER\s+USER\b|rm\s+-rf\s+\/home\/admin|\brsync\b[^\n]*:\/home\/admin|::(?:addComment|changeStatus)\s*\(/i;

function readInventory() {
  return JSON.parse(fs.readFileSync(inventoryPath, 'utf8'));
}

function workflowNames() {
  return fs.readdirSync(workflowDir)
    .filter((name) => name.endsWith('.yml') || name.endsWith('.yaml'))
    .sort();
}

function markerWorkflows(files = workflowNames(), read = (name) => fs.readFileSync(path.join(workflowDir, name), 'utf8')) {
  return files.filter((name) => markerPattern.test(read(name)));
}

function validateInventory(inventory, markedFiles) {
  const errors = [];
  const entries = inventory.workflows ?? {};
  const marked = new Set(markedFiles);
  const listed = new Set(Object.keys(entries));

  for (const name of marked) {
    if (!listed.has(name)) errors.push(`${name}: marker-bearing workflow is missing from the inventory`);
  }
  for (const name of listed) {
    if (!marked.has(name)) errors.push(`${name}: inventory entry is not an active marker-bearing workflow`);
  }

  for (const [name, entry] of Object.entries(entries)) {
    if (!categories.has(entry.classification)) {
      errors.push(`${name}: invalid classification ${entry.classification}`);
    }
    if (!['confirmed', 'marker-only', 'not-production'].includes(entry.production_mutation)) {
      errors.push(`${name}: production_mutation must be confirmed, marker-only, or not-production`);
    }
    if (entry.production_mutation === 'confirmed') {
      for (const field of ['trigger', 'production_command_or_path', 'authorization', 'concurrency', 'rollback', 'future_state']) {
        if (typeof entry[field] !== 'string' || !entry[field].trim()) {
          errors.push(`${name}: confirmed production mutation needs ${field}`);
        }
      }
      if (typeof entry.canonical !== 'boolean') errors.push(`${name}: confirmed production mutation needs boolean canonical`);
      if (typeof entry.required !== 'string' || !entry.required.trim()) errors.push(`${name}: confirmed production mutation needs required`);
    } else if (typeof entry.evidence !== 'string' || !entry.evidence.trim()) {
      errors.push(`${name}: marker-only/not-production entry needs source-review evidence`);
    }
  }
  return errors;
}

function changedWorkflowAdditions() {
  try {
    const diff = execFileSync('git', ['diff', '--unified=0', 'origin/main...HEAD', '--', '.github/workflows'], {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    });
    const additions = [];
    let current = null;
    for (const line of diff.split('\n')) {
      if (line.startsWith('+++ b/.github/workflows/')) current = line.slice('+++ b/'.length);
      else if (current && line.startsWith('+') && !line.startsWith('+++')) additions.push({ file: current, line: line.slice(1) });
    }
    return additions;
  } catch {
    return [];
  }
}

function validateContainment(inventory, additions, newWorkflowNames = []) {
  const errors = [];
  const entries = inventory.workflows ?? {};
  const seenNew = new Set(newWorkflowNames);

  for (const file of seenNew) {
    const text = fs.readFileSync(path.join(root, file), 'utf8');
    if (!/governance-capability\s*:/i.test(text)) {
      errors.push(`${file}: newly added workflow must declare governance-capability`);
    }
  }

  for (const { file, line } of additions) {
    if (!directProductionWritePattern.test(line)) continue;
    const entry = entries[path.basename(file)];
    if (!entry || entry.production_mutation !== 'confirmed') {
      errors.push(`${file}: added direct production-write marker requires a confirmed inventory entry`);
    }
  }
  return errors;
}

function run(inventory = readInventory(), markedFiles = markerWorkflows(), additions = changedWorkflowAdditions(), newWorkflows = []) {
  const errors = [
    ...validateInventory(inventory, markedFiles),
    ...validateContainment(inventory, additions, newWorkflows),
  ];
  if (errors.length) {
    for (const error of errors) console.error(`ERROR: ${error}`);
    return false;
  }
  console.log(`production workflow inventory: OK (${markedFiles.length} marker-bearing workflows classified)`);
  return true;
}

if (selfTest) {
  const fixture = { workflows: { 'new.yml': { classification: 'read-only-probe', production_mutation: 'marker-only', evidence: 'fixture' } } };
  const missing = validateInventory(fixture, ['new.yml', 'unlisted.yml']);
  const caughtMissing = missing.some((error) => error.includes('unlisted.yml'));
  const caughtWrite = validateContainment(fixture, [{ file: '.github/workflows/new.yml', line: 'php artisan example --execute' }]);
  if (!caughtMissing || !caughtWrite.length) {
    console.error('production workflow inventory self-test: failed to detect missing classification/write containment');
    process.exit(1);
  }
  console.log('production workflow inventory self-test: OK');
  process.exit(0);
}

const newWorkflows = (() => {
  try {
    return execFileSync('git', ['diff', '--diff-filter=A', '--name-only', 'origin/main...HEAD', '--', '.github/workflows'], {
      cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'],
    }).split('\n').filter(Boolean);
  } catch {
    return [];
  }
})();

process.exit(run() ? 0 : 1);
