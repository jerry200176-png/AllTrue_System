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
  if (!indexText.includes('docs/DOCS_GOVERNANCE_SOP.md')) {
    warnings.push('docs/INDEX.md is missing docs governance SOP entry');
  }
}

const markdownFilesToCheck = [
  'README.md',
  'AGENTS.md',
  'docs/INDEX.md',
  'docs/DOCS_GOVERNANCE_SOP.md',
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
