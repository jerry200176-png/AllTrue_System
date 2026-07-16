#!/usr/bin/env node
/**
 * POP Architecture Fitness Functions — Phase 1 skeleton
 * ADR-POP-014 | FIT-001..003 enabled; FIT-004..010 stubbed
 *
 * Usage: node scripts/pop-fitness-check.mjs [--strict]
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'fs';
import { join, relative } from 'path';

const ROOT = join(import.meta.dirname, '..');
const STRICT = process.argv.includes('--strict');

const ENGINE_CORE = [
  'backend/app/Operations/Engine',
  'backend/app/Operations/OperationEngine.php',
].map((p) => join(ROOT, p));

const failures = [];
const warnings = [];

function fileExists(p) {
  try {
    return statSync(p).isFile();
  } catch {
    return false;
  }
}

function dirExists(p) {
  try {
    return statSync(p).isDirectory();
  } catch {
    return false;
  }
}

// FIT-001: catalog exists and validates minimally
function fit001() {
  const catalogPath = join(ROOT, 'operations/catalog.yaml');
  if (!fileExists(catalogPath)) {
    failures.push('FIT-001: operations/catalog.yaml missing');
    return;
  }
  const text = readFileSync(catalogPath, 'utf8');
  const requiredKeys = [
    'lifecycle',
    'domain',
    'business_owner',
    'technical_owner',
    'approver_roles',
    'runbook',
    'alert_group',
    'escalation_policy',
  ];
  for (const key of requiredKeys) {
    if (!text.includes(`${key}:`)) {
      failures.push(`FIT-001: catalog missing required field pattern: ${key}`);
    }
  }
}

// FIT-002: ADR set complete
function fit002() {
  const adrDir = join(ROOT, 'docs/pop/adr');
  for (let i = 1; i <= 14; i++) {
    const num = String(i).padStart(3, '0');
    const matches = readdirSync(adrDir).filter((f) => f.startsWith(`ADR-POP-${num}`));
    if (matches.length === 0) {
      failures.push(`FIT-002: missing ADR-POP-${num}`);
    }
  }
  if (!fileExists(join(adrDir, 'ADR-POP-010-contract-i1.md'))) {
    failures.push('FIT-002: ADR-POP-010 missing');
  }
}

// FIT-003: contract version 2 + I1 mentions POP
function fit003() {
  const contract = readFileSync(join(ROOT, 'docs/CONTROL_PLANE_CONTRACT.md'), 'utf8');
  if (!contract.includes('contract-version:** 2')) {
    failures.push('FIT-003: CONTROL_PLANE_CONTRACT not at version 2');
  }
  if (!contract.includes('POP Executor')) {
    failures.push('FIT-003: I1 must reference POP Executor');
  }
}

// FIT-004..010 stubs (Phase 2+)
function fitStub(id, msg) {
  if (STRICT) {
    warnings.push(`${id}: ${msg} (not enforced until Phase 2)`);
  }
}

fit001();
fit002();
fit003();
fitStub('FIT-004', 'Strategy add must not modify Engine core');
fitStub('FIT-005', 'State transitions table');
fitStub('FIT-006', 'Strategy binds invariant pack');
fitStub('FIT-007', 'execution-record schema validation');
fitStub('FIT-008', 'Replay timeline test');
fitStub('FIT-009', 'DLQ on retry exhaustion');
fitStub('FIT-010', 'Cell isolation integration');

if (warnings.length) {
  console.log('Warnings:');
  warnings.forEach((w) => console.log('  ⚠', w));
}

if (failures.length) {
  console.error('POP fitness FAILED:');
  failures.forEach((f) => console.error('  ✗', f));
  process.exit(1);
}

console.log('POP fitness OK (Phase 1: FIT-001..003)');
