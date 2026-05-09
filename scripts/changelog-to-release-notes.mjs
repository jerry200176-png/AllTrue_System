#!/usr/bin/env node
/**
 * Maps docs/CHANGELOG.md → frontend/src/lib/releaseNotes.generated.js
 * Run from repo root or via `npm run sync-release-notes` in frontend/.
 * Filters internal-only headings (ops/chore/ci/docs/td, etc.).
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const mdPath = path.join(root, 'docs', 'CHANGELOG.md');
const outPath = path.join(root, 'frontend', 'src', 'lib', 'releaseNotes.generated.js');

const headingRe = /^## (\d{4}-\d{2}-\d{2}) — (.+)$/;

function shouldSkipHeading(title) {
  return (
    /（ops）|feat\(ops\)|Added（ops）|fix\(ops\)|chore\(github|chore\(ci|docs\(|docs\(ai|ci\(security|ops\(ci|\btd\(|\bbi\(|Golden scenarios|Issue 模板|CODEOWNERS|CONTRIBUTING/i.test(
      title,
    )
  );
}

function humanizeLine(s) {
  let t = String(s)
    .replace(/`([^`]+)`/g, '「$1」')
    .replace(/\s+/g, ' ')
    .trim();
  if (t.length > 240) {
    return `${t.slice(0, 237)}…`;
  }
  return t;
}

function parseChangelog(md) {
  const lines = md.split('\n');
  /** @type {Array<{ version: string; title: string; audience: string[]; items: string[] }>} */
  const notes = [];

  for (let i = 0; i < lines.length; i++) {
    const hm = lines[i].match(headingRe);
    if (!hm) {
      continue;
    }
    const version = hm[1];
    const rawTitle = hm[2].trim();
    if (shouldSkipHeading(rawTitle)) {
      continue;
    }

    const items = [];
    for (let j = i + 1; j < lines.length; j++) {
      if (headingRe.test(lines[j])) {
        break;
      }
      const bm = lines[j].match(/^\s*-\s+(.+)$/);
      if (bm) {
        items.push(humanizeLine(bm[1]));
      }
    }

    const title = humanizeLine(rawTitle);
    const clippedTitle = title.length > 96 ? `${title.slice(0, 93)}…` : title;

    notes.push({
      version,
      title: clippedTitle || version,
      audience: ['teacher', 'director'],
      items:
        items.length > 0
          ? items.slice(0, 5)
          : [humanizeLine(rawTitle.replace(/^[^:]+:\s*/, '') || rawTitle)],
    });

    if (notes.length >= 18) {
      break;
    }
  }

  if (notes.length === 0) {
    notes.push({
      version: new Date().toISOString().slice(0, 10),
      title: '系統更新紀錄',
      audience: ['teacher', 'director'],
      items: ['可查閱技術紀錄 CHANGELOG'],
    });
  }

  return notes;
}

const md = fs.readFileSync(mdPath, 'utf8');
const data = parseChangelog(md);

const banner = `/**
 * AUTO-GENERATED — source: docs/CHANGELOG.md
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */\n`;

fs.writeFileSync(outPath, `${banner}export const changelogReleaseNotes = ${JSON.stringify(data, null, 2)};\n`, 'utf8');
console.error(`changelog-to-release-notes: wrote ${data.length} cards → ${outPath}`);
