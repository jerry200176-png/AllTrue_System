#!/usr/bin/env node
/**
 * Maps docs/PARENT_UPDATES.yml → frontend/src/lib/parentUpdates.generated.js
 * Explicit parent copy only — never keyword-derived from CHANGELOG.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const ymlPath = path.join(root, 'docs', 'PARENT_UPDATES.yml');
const outPath = path.join(root, 'frontend', 'src', 'lib', 'parentUpdates.generated.js');

const ALLOWED_KINDS = new Set(['improvement', 'policy', 'resolved_issue']);

/** Minimal YAML subset parser for this file's schema (list of string-valued maps). */
function parseParentUpdatesYml(text) {
  const lines = String(text || '').split(/\r?\n/);
  const updates = [];
  let current = null;
  let inUpdates = false;

  const flush = () => {
    if (current) {
      updates.push(current);
      current = null;
    }
  };

  for (const raw of lines) {
    const line = raw.replace(/\t/g, '  ');
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;

    if (/^updates:\s*$/.test(trimmed)) {
      inUpdates = true;
      continue;
    }
    if (!inUpdates) continue;

    const itemStart = line.match(/^\s*-\s+id:\s*(.+?)\s*$/);
    if (itemStart) {
      flush();
      current = { id: unquote(itemStart[1]) };
      continue;
    }

    if (!current) {
      throw new Error(`parent-updates-to-js: unexpected line before item: ${trimmed}`);
    }

    const kv = line.match(/^\s{2,}([a-z_]+):\s*(.+?)\s*$/);
    if (!kv) {
      throw new Error(`parent-updates-to-js: cannot parse line: ${trimmed}`);
    }
    const key = kv[1];
    const value = unquote(kv[2]);
    if (key === 'id') {
      throw new Error('parent-updates-to-js: id must start the list item (`- id: ...`)');
    }
    current[key] = value;
  }
  flush();
  return updates;
}

function unquote(s) {
  const t = String(s || '').trim();
  if ((t.startsWith('"') && t.endsWith('"')) || (t.startsWith("'") && t.endsWith("'"))) {
    return t.slice(1, -1);
  }
  return t;
}

function addDaysIso(isoDate, days) {
  const m = String(isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return '';
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

function versionFromIsoDate(dateStr) {
  const m = String(dateStr || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return '1.0.0';
  return `${m[1]}.${m[2]}.${m[3]}`;
}

function validateAndNormalize(rawList) {
  const seen = new Set();
  const out = [];

  for (const raw of rawList) {
    const id = String(raw.id || '').trim();
    const publishedAt = String(raw.published_at || '').trim();
    const kind = String(raw.kind || '').trim();
    const title = String(raw.title || '').trim();
    const summary = String(raw.summary || '').trim();
    const details = String(raw.details || '').trim();
    let expiresAt = String(raw.expires_at || '').trim();

    if (!id) throw new Error('parent-updates-to-js: each update needs id');
    if (seen.has(id)) throw new Error(`parent-updates-to-js: duplicate id ${id}`);
    seen.add(id);

    if (!/^\d{4}-\d{2}-\d{2}$/.test(publishedAt)) {
      throw new Error(`parent-updates-to-js: ${id} published_at must be YYYY-MM-DD`);
    }
    if (!expiresAt) {
      expiresAt = addDaysIso(publishedAt, 30);
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(expiresAt)) {
      throw new Error(`parent-updates-to-js: ${id} expires_at must be YYYY-MM-DD`);
    }
    if (expiresAt < publishedAt) {
      throw new Error(`parent-updates-to-js: ${id} expires_at before published_at`);
    }
    if (!ALLOWED_KINDS.has(kind)) {
      throw new Error(`parent-updates-to-js: ${id} kind must be one of ${[...ALLOWED_KINDS].join('|')}`);
    }
    if (!title || !summary || !details) {
      throw new Error(`parent-updates-to-js: ${id} needs title, summary, and details`);
    }

    out.push({
      id,
      kind,
      title,
      summary,
      details,
      publishedAt,
      expiresAt,
      date: publishedAt,
      version: versionFromIsoDate(publishedAt),
    });
  }

  out.sort((a, b) => String(b.publishedAt).localeCompare(String(a.publishedAt)) || String(b.id).localeCompare(String(a.id)));
  return out;
}

const md = fs.readFileSync(ymlPath, 'utf8');
const data = validateAndNormalize(parseParentUpdatesYml(md));

const banner = `/**
 * AUTO-GENERATED — source: docs/PARENT_UPDATES.yml
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */\n`;

fs.writeFileSync(outPath, `${banner}export const parentUpdates = ${JSON.stringify(data, null, 2)};\n`, 'utf8');
console.error(`parent-updates-to-js: wrote ${data.length} updates → ${outPath}`);
