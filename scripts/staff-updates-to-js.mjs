/**
 * Maps docs/STAFF_UPDATES.yml → frontend/src/lib/staffUpdates.generated.js
 * Explicit staff copy only — never auto-published from CHANGELOG.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { assertUserFacingCopy, countChars } from './lib/userFacingCopyGate.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const ymlPath = path.join(root, 'docs', 'STAFF_UPDATES.yml');
const outPath = path.join(root, 'frontend', 'src', 'lib', 'staffUpdates.generated.js');

const ALLOWED_AUDIENCES = new Set(['director', 'teacher']);
const ALLOWED_IMPORTANCE = new Set(['digest', 'major', 'action_required']);
const ALLOWED_CATEGORY = new Set(['added', 'fixed', 'improved', 'action_required']);
const IMPORTANCE_RANK = { action_required: 3, major: 2, digest: 1 };

const TITLE_MAX = 18;
const SUMMARY_MAX = 45;
const ITEM_MAX = 60;
const ITEMS_MAX = 3;

function unquote(s) {
  const t = String(s || '').trim();
  if ((t.startsWith('"') && t.endsWith('"')) || (t.startsWith("'") && t.endsWith("'"))) {
    return t.slice(1, -1);
  }
  return t;
}

/**
 * Minimal YAML subset for STAFF_UPDATES schema (maps + string lists + item objects).
 */
function parseStaffUpdatesYml(text) {
  const lines = String(text || '').split(/\r?\n/);
  const updates = [];
  let current = null;
  let inUpdates = false;
  /** @type {null | 'audiences' | 'items' | 'source_refs'} */
  let listMode = null;
  let currentItem = null;

  const flushItem = () => {
    if (currentItem && current) {
      if (!current.items) current.items = [];
      current.items.push(currentItem);
      currentItem = null;
    }
  };

  const flush = () => {
    flushItem();
    if (current) {
      updates.push(current);
      current = null;
    }
    listMode = null;
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

    const indent = line.match(/^( *)/)?.[1].length ?? 0;

    const itemStart = line.match(/^\s*-\s+id:\s*(.+?)\s*$/);
    if (itemStart && indent <= 2) {
      flush();
      current = { id: unquote(itemStart[1]) };
      listMode = null;
      continue;
    }

    if (!current) {
      throw new Error(`staff-updates-to-js: unexpected line before item: ${trimmed}`);
    }

    // Nested list under audiences / source_refs: `- value`
    if (listMode === 'audiences' || listMode === 'source_refs') {
      const bullet = line.match(/^\s+-\s+(.+?)\s*$/);
      if (bullet && indent >= 4) {
        const val = unquote(bullet[1]);
        if (!current[listMode]) current[listMode] = [];
        current[listMode].push(val);
        continue;
      }
      // fall through if a new key starts
      listMode = null;
    }

    // items: list of { category, text }
    if (listMode === 'items') {
      const catStart = line.match(/^\s+-\s+category:\s*(.+?)\s*$/);
      if (catStart && indent >= 4) {
        flushItem();
        currentItem = { category: unquote(catStart[1]) };
        continue;
      }
      if (currentItem) {
        const textKv = line.match(/^\s+text:\s*(.+?)\s*$/);
        if (textKv && indent >= 6) {
          currentItem.text = unquote(textKv[1]);
          continue;
        }
      }
      // new top-level key ends items
      if (indent <= 4 && /^[a-z_]+:/.test(trimmed) && !trimmed.startsWith('-')) {
        flushItem();
        listMode = null;
      } else if (indent >= 4 && trimmed.startsWith('-')) {
        throw new Error(`staff-updates-to-js: ${current.id} items must use "- category:" then "text:"`);
      }
    }

    if (/^\s+audiences:\s*$/.test(line)) {
      flushItem();
      listMode = 'audiences';
      current.audiences = [];
      continue;
    }
    if (/^\s+items:\s*$/.test(line)) {
      flushItem();
      listMode = 'items';
      current.items = [];
      continue;
    }
    if (/^\s+source_refs:\s*$/.test(line)) {
      flushItem();
      listMode = 'source_refs';
      current.source_refs = [];
      continue;
    }

    const kv = line.match(/^\s{2,}([a-z_]+):\s*(.+?)\s*$/);
    if (!kv) {
      throw new Error(`staff-updates-to-js: cannot parse line: ${trimmed}`);
    }
    flushItem();
    listMode = null;
    const key = kv[1];
    const value = unquote(kv[2]);
    if (key === 'id') {
      throw new Error('staff-updates-to-js: id must start the list item (`- id: ...`)');
    }
    current[key] = value;
  }
  flush();
  return updates;
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
    const effectiveAt = String(raw.effective_at || '').trim();
    const importance = String(raw.importance || '').trim();
    const title = String(raw.title || '').trim();
    const summary = String(raw.summary || '').trim();
    const audiences = Array.isArray(raw.audiences) ? raw.audiences.map((a) => String(a).trim()) : [];
    const items = Array.isArray(raw.items) ? raw.items : [];
    const sourceRefs = Array.isArray(raw.source_refs)
      ? raw.source_refs.map((s) => String(s).trim()).filter(Boolean)
      : [];

    if (!id) throw new Error('staff-updates-to-js: each update needs id');
    if (seen.has(id)) throw new Error(`staff-updates-to-js: duplicate id ${id}`);
    seen.add(id);

    if (!/^\d{4}-\d{2}-\d{2}$/.test(publishedAt)) {
      throw new Error(`staff-updates-to-js: ${id} published_at must be YYYY-MM-DD`);
    }
    if (effectiveAt && !/^\d{4}-\d{2}-\d{2}$/.test(effectiveAt)) {
      throw new Error(`staff-updates-to-js: ${id} effective_at must be YYYY-MM-DD`);
    }
    if (!ALLOWED_IMPORTANCE.has(importance)) {
      throw new Error(
        `staff-updates-to-js: ${id} importance must be one of ${[...ALLOWED_IMPORTANCE].join('|')}`,
      );
    }
    if (!audiences.length) {
      throw new Error(`staff-updates-to-js: ${id} needs audiences`);
    }
    for (const a of audiences) {
      if (a === 'parent') {
        throw new Error(`staff-updates-to-js: ${id} must not use audience parent (use PARENT_UPDATES.yml)`);
      }
      if (!ALLOWED_AUDIENCES.has(a)) {
        throw new Error(`staff-updates-to-js: ${id} invalid audience ${a}`);
      }
    }
    if (!title || !summary) {
      throw new Error(`staff-updates-to-js: ${id} needs title and summary`);
    }
    if (countChars(title) > TITLE_MAX) {
      throw new Error(`staff-updates-to-js: ${id} title > ${TITLE_MAX} chars (${countChars(title)})`);
    }
    if (countChars(summary) > SUMMARY_MAX) {
      throw new Error(`staff-updates-to-js: ${id} summary > ${SUMMARY_MAX} chars (${countChars(summary)})`);
    }
    if (!items.length || items.length > ITEMS_MAX) {
      throw new Error(`staff-updates-to-js: ${id} needs 1–${ITEMS_MAX} items`);
    }

    const normalizedItems = [];
    for (const it of items) {
      const category = String(it.category || '').trim();
      const text = String(it.text || '').trim();
      if (!ALLOWED_CATEGORY.has(category)) {
        throw new Error(`staff-updates-to-js: ${id} item category invalid: ${category}`);
      }
      if (!text) throw new Error(`staff-updates-to-js: ${id} item text required`);
      if (countChars(text) > ITEM_MAX) {
        throw new Error(`staff-updates-to-js: ${id} item text > ${ITEM_MAX} chars`);
      }
      normalizedItems.push({ category, text });
    }

    assertUserFacingCopy(
      [title, summary, ...normalizedItems.map((i) => i.text)],
      id,
    );

    // Group items into UI sections with human labels
    const sectionTitle = {
      added: '你現在可以',
      fixed: '我們修好了',
      improved: '操作更順手',
      action_required: '需要你注意',
    };
    const sectionMap = new Map();
    for (const it of normalizedItems) {
      const titleZh = sectionTitle[it.category];
      if (!sectionMap.has(titleZh)) sectionMap.set(titleZh, []);
      sectionMap.get(titleZh).push(it.text);
    }
    const sections = [...sectionMap.entries()].map(([secTitle, secItems]) => ({
      title: secTitle,
      items: secItems,
    }));

    out.push({
      id,
      publishedAt,
      effectiveAt: effectiveAt || null,
      audiences,
      audience: audiences, // compat with roleMatchesNoteAudience
      importance,
      title,
      summary,
      items: normalizedItems.map((i) => i.text),
      sections,
      sourceRefs,
      date: publishedAt,
      version: versionFromIsoDate(publishedAt),
    });
  }

  out.sort((a, b) => {
    const d = String(b.publishedAt).localeCompare(String(a.publishedAt));
    if (d) return d;
    const ia = IMPORTANCE_RANK[a.importance] || 0;
    const ib = IMPORTANCE_RANK[b.importance] || 0;
    if (ib !== ia) return ib - ia;
    return String(b.id).localeCompare(String(a.id));
  });

  return out;
}

const md = fs.readFileSync(ymlPath, 'utf8');
const data = validateAndNormalize(parseStaffUpdatesYml(md));

const banner = `/**
 * AUTO-GENERATED — source: docs/STAFF_UPDATES.yml
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */\n`;

fs.writeFileSync(outPath, `${banner}export const staffUpdates = ${JSON.stringify(data, null, 2)};\n`, 'utf8');
console.error(`staff-updates-to-js: wrote ${data.length} updates → ${outPath}`);
