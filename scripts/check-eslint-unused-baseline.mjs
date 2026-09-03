#!/usr/bin/env node
/**
 * Ratchet the existing no-unused-vars debt without requiring a big-bang cleanup.
 * The committed per-file baseline blocks new debt while allowing files to improve.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FRONTEND = path.join(ROOT, 'frontend');
const BASELINE_PATH = path.join(FRONTEND, 'eslint-unused-baseline.json');

export function summarizeUnusedMessages(report, frontendRoot = FRONTEND) {
  const files = {};
  for (const file of Array.isArray(report) ? report : []) {
    const count = (file.messages || []).filter((message) => message.ruleId === 'no-unused-vars').length;
    if (!count) continue;
    const relative = path.relative(frontendRoot, file.filePath).split(path.sep).join('/');
    files[relative] = count;
  }
  return {
    total: Object.values(files).reduce((sum, count) => sum + count, 0),
    files,
  };
}

export function compareUnusedBaseline(current, baseline) {
  const violations = [];
  for (const [file, count] of Object.entries(current?.files || {})) {
    const allowed = Number(baseline?.files?.[file] || 0);
    if (count > allowed) violations.push(`${file}: ${count} > baseline ${allowed}`);
  }
  return violations;
}

function runEslint() {
  const eslintEntry = path.join(FRONTEND, 'node_modules/eslint/bin/eslint.js');
  try {
    return JSON.parse(execFileSync(
      process.execPath,
      [eslintEntry, 'src', '--no-warn-ignored', '--rule', 'no-unused-vars:error', '--format', 'json'],
      { cwd: FRONTEND, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 },
    ));
  } catch (error) {
    if (!error.stdout) throw error;
    return JSON.parse(error.stdout);
  }
}

export function main() {
  const baseline = JSON.parse(fs.readFileSync(BASELINE_PATH, 'utf8'));
  const current = summarizeUnusedMessages(runEslint());
  const violations = compareUnusedBaseline(current, baseline);
  console.log(`eslint no-unused-vars baseline: ${current.total} current / ${baseline.total} recorded`);
  if (violations.length) {
    console.error('❌ New no-unused-vars debt detected:');
    violations.forEach((violation) => console.error(`  - ${violation}`));
    console.error('Fix the new issue, or update the baseline only when the existing debt is intentionally reviewed.');
    process.exitCode = 1;
    return;
  }
  console.log(`✅ No file exceeds the committed baseline (${Object.keys(current.files).length} files tracked).`);
}

if (import.meta.url === `file://${process.argv[1]}`) main();
