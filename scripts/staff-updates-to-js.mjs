/** docs/STAFF_UPDATES.yml → frontend/src/lib/staffUpdates.generated.js (never auto from CHANGELOG) */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { assertUserFacingCopy, countChars } from './lib/userFacingCopyGate.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
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
const SECTION_TITLE = {
  added: '你現在可以',
  fixed: '我們修好了',
  improved: '操作更順手',
  action_required: '需要你注意',
};

function unquote(s) {
  const t = String(s || '').trim();
  if ((t.startsWith('"') && t.endsWith('"')) || (t.startsWith("'") && t.endsWith("'"))) return t.slice(1, -1);
  return t;
}

function parseStaffUpdatesYml(text) {
  const updates = [];
  let current = null;
  let inUpdates = false;
  let listMode = null;
  let currentItem = null;

  const flushItem = () => {
    if (currentItem && current) {
      (current.items ||= []).push(currentItem);
      currentItem = null;
    }
  };
  const flush = () => {
    flushItem();
    if (current) updates.push(current);
    current = null;
    listMode = null;
  };

  for (const raw of String(text || '').split(/\r?\n/)) {
    const line = raw.replace(/\t/g, '  ');
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    if (/^updates:\s*$/.test(trimmed)) { inUpdates = true; continue; }
    if (!inUpdates) continue;

    const indent = line.match(/^( *)/)?.[1].length ?? 0;
    const itemStart = line.match(/^\s*-\s+id:\s*(.+?)\s*$/);
    if (itemStart && indent <= 2) {
      flush();
      current = { id: unquote(itemStart[1]) };
      continue;
    }
    if (!current) throw new Error(`staff-updates-to-js: unexpected: ${trimmed}`);

    if (listMode === 'audiences' || listMode === 'source_refs') {
      const bullet = line.match(/^\s+-\s+(.+?)\s*$/);
      if (bullet && indent >= 4) {
        (current[listMode] ||= []).push(unquote(bullet[1]));
        continue;
      }
      listMode = null;
    }

    if (listMode === 'items') {
      const catStart = line.match(/^\s+-\s+category:\s*(.+?)\s*$/);
      if (catStart && indent >= 4) {
        flushItem();
        currentItem = { category: unquote(catStart[1]) };
        continue;
      }
      if (currentItem) {
        const textKv = line.match(/^\s+text:\s*(.+?)\s*$/);
        if (textKv && indent >= 6) { currentItem.text = unquote(textKv[1]); continue; }
      }
      if (indent <= 4 && /^[a-z_]+:/.test(trimmed) && !trimmed.startsWith('-')) {
        flushItem();
        listMode = null;
      } else if (indent >= 4 && trimmed.startsWith('-')) {
        throw new Error(`staff-updates-to-js: ${current.id} items need "- category:" then "text:"`);
      }
    }

    if (/^\s+audiences:\s*$/.test(line)) { flushItem(); listMode = 'audiences'; current.audiences = []; continue; }
    if (/^\s+items:\s*$/.test(line)) { flushItem(); listMode = 'items'; current.items = []; continue; }
    if (/^\s+source_refs:\s*$/.test(line)) { flushItem(); listMode = 'source_refs'; current.source_refs = []; continue; }

    const kv = line.match(/^\s{2,}([a-z_]+):\s*(.+?)\s*$/);
    if (!kv) throw new Error(`staff-updates-to-js: cannot parse: ${trimmed}`);
    flushItem();
    listMode = null;
    if (kv[1] === 'id') throw new Error('staff-updates-to-js: id must start list item');
    current[kv[1]] = unquote(kv[2]);
  }
  flush();
  return updates;
}

function versionFromIsoDate(dateStr) {
  const m = String(dateStr || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[1]}.${m[2]}.${m[3]}` : '1.0.0';
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
    const audiences = (Array.isArray(raw.audiences) ? raw.audiences : []).map((a) => String(a).trim());
    const items = Array.isArray(raw.items) ? raw.items : [];
    const sourceRefs = (Array.isArray(raw.source_refs) ? raw.source_refs : []).map((s) => String(s).trim()).filter(Boolean);

    if (!id) throw new Error('staff-updates-to-js: id required');
    if (seen.has(id)) throw new Error(`staff-updates-to-js: duplicate id ${id}`);
    seen.add(id);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(publishedAt)) throw new Error(`staff-updates-to-js: ${id} bad published_at`);
    if (effectiveAt && !/^\d{4}-\d{2}-\d{2}$/.test(effectiveAt)) throw new Error(`staff-updates-to-js: ${id} bad effective_at`);
    if (!ALLOWED_IMPORTANCE.has(importance)) throw new Error(`staff-updates-to-js: ${id} bad importance`);
    if (!audiences.length) throw new Error(`staff-updates-to-js: ${id} needs audiences`);
    for (const a of audiences) {
      if (a === 'parent') throw new Error(`staff-updates-to-js: ${id} forbid parent (use PARENT_UPDATES.yml)`);
      if (!ALLOWED_AUDIENCES.has(a)) throw new Error(`staff-updates-to-js: ${id} bad audience ${a}`);
    }
    if (!title || !summary) throw new Error(`staff-updates-to-js: ${id} needs title/summary`);
    if (countChars(title) > TITLE_MAX) throw new Error(`staff-updates-to-js: ${id} title too long`);
    if (countChars(summary) > SUMMARY_MAX) throw new Error(`staff-updates-to-js: ${id} summary too long`);
    if (!items.length || items.length > ITEMS_MAX) throw new Error(`staff-updates-to-js: ${id} needs 1–${ITEMS_MAX} items`);

    const normalizedItems = items.map((it) => {
      const category = String(it.category || '').trim();
      const text = String(it.text || '').trim();
      if (!ALLOWED_CATEGORY.has(category)) throw new Error(`staff-updates-to-js: ${id} bad category`);
      if (!text) throw new Error(`staff-updates-to-js: ${id} empty item`);
      if (countChars(text) > ITEM_MAX) throw new Error(`staff-updates-to-js: ${id} item too long`);
      return { category, text };
    });

    assertUserFacingCopy([title, summary, ...normalizedItems.map((i) => i.text)], id);

    const sectionMap = new Map();
    for (const it of normalizedItems) {
      const sec = SECTION_TITLE[it.category];
      if (!sectionMap.has(sec)) sectionMap.set(sec, []);
      sectionMap.get(sec).push(it.text);
    }

    out.push({
      id,
      publishedAt,
      effectiveAt: effectiveAt || null,
      audiences,
      audience: audiences,
      importance,
      title,
      summary,
      items: normalizedItems.map((i) => i.text),
      sections: [...sectionMap.entries()].map(([secTitle, secItems]) => ({ title: secTitle, items: secItems })),
      sourceRefs,
      date: publishedAt,
      version: versionFromIsoDate(publishedAt),
    });
  }

  out.sort((a, b) => {
    const d = String(b.publishedAt).localeCompare(String(a.publishedAt));
    if (d) return d;
    const r = (IMPORTANCE_RANK[b.importance] || 0) - (IMPORTANCE_RANK[a.importance] || 0);
    return r || String(b.id).localeCompare(String(a.id));
  });
  return out;
}

const data = validateAndNormalize(parseStaffUpdatesYml(fs.readFileSync(ymlPath, 'utf8')));
const banner = `/**
 * AUTO-GENERATED — source: docs/STAFF_UPDATES.yml
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */\n`;
fs.writeFileSync(outPath, `${banner}export const staffUpdates = ${JSON.stringify(data, null, 2)};\n`, 'utf8');
console.error(`staff-updates-to-js: wrote ${data.length} updates → ${outPath}`);
