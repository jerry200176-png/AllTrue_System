#!/usr/bin/env node
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const strictMode = process.argv.includes('--strict');

const requiredFiles = [
  'docs/INDEX.md',
  'README.md',
  'AGENTS.md',
  'docs/CHANGELOG.md',
  'docs/AI_REGRESSION_LESSONS.md',
  'docs/DOCS_GOVERNANCE_SOP.md',
];

/** @type {string[]} */
const errors = [];
/** @type {string[]} */
const warnings = [];

function readText(relPath) {
  return fs.readFileSync(path.join(root, relPath), 'utf8');
}

function exists(relPath) {
  return fs.existsSync(path.join(root, relPath));
}

function toGitHubAnchor(text) {
  return String(text || '')
    .trim()
    .toLowerCase()
    .replace(/[^\p{Letter}\p{Number}\s-]/gu, '')
    .replace(/\s+/g, '-');
}

function extractHeadings(md) {
  const anchors = new Set();
  for (const line of md.split('\n')) {
    const match = line.match(/^#{1,6}\s+(.+?)\s*$/);
    if (!match) continue;
    anchors.add(toGitHubAnchor(match[1]));
  }
  return anchors;
}

function normalizeRelPath(fromFile, target) {
  const fromDir = path.dirname(fromFile);
  return path.normalize(path.join(fromDir, target)).replace(/\\/g, '/');
}

function validateMarkdownLinks(relPath) {
  const raw = readText(relPath);
  const anchorsHere = extractHeadings(raw);
  const re = /\[[^\]]+]\(([^)]+)\)/g;
  let match;

  while ((match = re.exec(raw)) !== null) {
    const href = String(match[1] || '').trim();
    if (!href || href.startsWith('http://') || href.startsWith('https://') || href.startsWith('mailto:')) {
      continue;
    }

    if (href.startsWith('#')) {
      const anchor = toGitHubAnchor(href.slice(1));
      if (!anchorsHere.has(anchor)) {
        errors.push(`${relPath}: missing local anchor "${href}"`);
      }
      continue;
    }

    const [filePart, anchorPart] = href.split('#');
    const targetPath = normalizeRelPath(relPath, filePart);
    if (!exists(targetPath)) {
      errors.push(`${relPath}: broken link target "${href}"`);
      continue;
    }

    if (anchorPart) {
      const targetText = readText(targetPath);
      const targetAnchors = extractHeadings(targetText);
      const normalized = toGitHubAnchor(anchorPart);
      if (!targetAnchors.has(normalized)) {
        errors.push(`${relPath}: missing anchor "${anchorPart}" in "${targetPath}"`);
      }
    }
  }
}

for (const rel of requiredFiles) {
  if (!exists(rel)) {
    errors.push(`missing required file: ${rel}`);
  }
}

if (exists('README.md') && !readText('README.md').includes('docs/INDEX.md')) {
  warnings.push('README.md does not explicitly reference docs/INDEX.md');
}

if (exists('AGENTS.md') && !readText('AGENTS.md').includes('docs/INDEX.md')) {
  warnings.push('AGENTS.md does not explicitly reference docs/INDEX.md');
}

if (exists('docs/INDEX.md')) {
  const indexText = readText('docs/INDEX.md');
  // Phase B: governance content merged into INDEX — check for key sections
  if (!indexText.includes('治理節奏') && !indexText.includes('DOCS_GOVERNANCE_SOP')) {
    warnings.push('docs/INDEX.md is missing governance rhythm section (§治理節奏)');
  }
  if (!indexText.includes('速讀卡') && !indexText.includes('AI_DOC_LITERACY')) {
    warnings.push('docs/INDEX.md is missing quick-read cards section (§速讀卡)');
  }
}

// Phase C: naming prefix convention — warn if any NEW docs/ file lacks an approved prefix
// (grandfathered legacy names are exempt; this warns on clearly non-conforming new files)
const APPROVED_PREFIXES = ['RULE_', 'RUNBOOK_', 'REF_', 'MODULE_', 'GUIDE_', 'POLICY_'];
const LEGACY_EXEMPT = new Set([
  'INDEX.md', 'CHANGELOG.md', 'CHANGELOG_ARCHIVE_2026-04.md',
  'AI_REGRESSION_LESSONS.md', 'AI_REGRESSION_LESSONS_ARCHIVE.md', 'AI_DOC_LITERACY.md',
  'DOCS_GOVERNANCE_SOP.md', 'TECH_DEBT.md', 'SYSTEM_TECH_GUIDE.md', 'SECURITY.md',
  'DEPLOYMENT.md', 'DB_PERF.md', 'OPERATIONS_RUNBOOK.md', 'DANGEROUS_OPERATIONS.md',
  'DAILY_CHECKLIST.md', 'CONTRIBUTING.md', 'SRE_POLICY.md', 'PRODUCT_OPS.md',
  'DIRECTOR_PAYMENT_ALERT_RULES.md', 'DIRECTOR_SCALING_FAQ.md', 'PRICING_CONTRACT.md',
  'ROLE_PLAYBOOK.md', 'FAQ.md', 'CHAT_BUG_SYSTEM.md', 'SUBSTITUTE_UX.md',
  'SCHEDULE_DISCREPANCY_REVIEW.md', 'MANUAL_SCHEDULE_DATE_SEMANTICS.md',
  'LINE_LIFF_CHECKLIST.md', 'PORSCHE_VISUAL_SYSTEM.md', 'WSL2_DEV_SETUP.md',
  'ENTERPRISE_WORKFLOW_ALIGNMENT.md', 'ENGINEERING_MATURITY_GAPS.md',
  'TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md', 'QA_GOLDEN_SCENARIOS.md',
  'PROFESSIONAL_PERCEPTION_SURVEY.md', 'PRD_PARTTIME_PAYROLL_PER_TEACHER_OVERRIDES.md',
  'PRD_PARTTIME_TEACHER_PAYROLL.md', 'PRD_SINGLE_SESSION_UX_CLARITY.md',
  'CTO_SPEC_BRANCH_MONTHLY_TUITION_REPORT.md',
  '更新網站前端.md', '使用說明_主任與超級管理員.md',
  // Pre-existing files that don't follow Phase C naming prefix (grandfathered)
  'ADOPTION_QUALITY_METRICS.md', 'AMBIENT_AUDIO_LICENSES.md',
  'SMOKE_TEST_RUNBOOK.md', 'SUPER_ADMIN_AND_MIGRATIONS.md', 'api-swipe-rfid.md',
]);
const docsDir = path.join(root, 'docs');
if (fs.existsSync(docsDir)) {
  for (const f of fs.readdirSync(docsDir)) {
    if (!f.endsWith('.md')) continue;
    if (LEGACY_EXEMPT.has(f)) continue;
    if (f.startsWith('archive/')) continue;
    const hasPrefix = APPROVED_PREFIXES.some(p => f.startsWith(p));
    if (!hasPrefix) {
      warnings.push(`docs/${f}: new doc does not follow naming prefix convention (RULE_/RUNBOOK_/REF_/MODULE_/GUIDE_/POLICY_)`);
    }
  }
}

const markdownFilesToCheck = [
  'README.md',
  'AGENTS.md',
  'docs/INDEX.md',
  'docs/DOCS_GOVERNANCE_SOP.md',
  'docs/AI_DOC_LITERACY.md',
];

for (const rel of markdownFilesToCheck) {
  if (exists(rel)) {
    validateMarkdownLinks(rel);
  }
}

if (process.env.GITHUB_STEP_SUMMARY) {
  const lines = [
    '### Docs Integrity Report',
    '',
    `- Required files checked: ${requiredFiles.length}`,
    `- Markdown files linted: ${markdownFilesToCheck.length}`,
    `- Errors: ${errors.length}`,
    `- Warnings: ${warnings.length}`,
    '',
  ];
  fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${lines.join('\n')}\n`, 'utf8');
}

if (warnings.length) {
  for (const warning of warnings) {
    console.error(`WARN: ${warning}`);
  }
}

if (errors.length) {
  for (const err of errors) {
    console.error(`ERROR: ${err}`);
  }
  process.exit(1);
}

if (strictMode && warnings.length) {
  console.error(`ERROR: strict mode failed due to ${warnings.length} warning(s).`);
  process.exit(1);
}

console.log(`docs-integrity-check: OK (${requiredFiles.length} required files, ${warnings.length} warning(s))`);
