#!/usr/bin/env node
// Overlay census (#4989): inventories every overlay under frontend/src by type, page, system, trigger, destructive.
// Usage: node scripts/design/overlay-census.mjs [--json | --md]   (default --md)
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const SRC = path.join(ROOT, 'frontend/src');
const DESTRUCTIVE = /刪除|移除|作廢|停用|封存|退休|拒絕|鎖帳|清除|撤銷|終止|取消(?:報名|訂單|課程)|delete|remove|destructive|danger/i;

// [type, system, regex matched per line]
const RULES = [
  ['dialog', 'AtDialog', /<AtDialog\b/],
  ['dialog', 'shadcn', /<(?:Dialog|DialogContent)\b/],
  ['sheet', 'shadcn', /<Sheet(?:Content)?\b/],
  ['confirm', 'shadcn', /<AlertDialog(?:Content)?\b/],
  ['menu', 'shadcn', /<DropdownMenu(?:Content)?\b/],
  ['menu', 'ActionMenu', /<ActionMenu\b/],
  ['menu', 'AtRowMenu', /<AtRowMenu\b/],
  ['toast', 'AtToast', /<AtToast\b/],
  ['toast', 'shadcn', /<(?:Toaster|Sonner)\b/],
  ['dialog', 'hand-rolled', /class="(?:[^"]*\s)?(?:[\w]+-)?modal-(?:backdrop|overlay|mask)(?:\s[^"]*)?"|class="modal"/],
  ['teleport', 'raw-teleport', /<Teleport\b/],
  ['confirm', 'window.confirm', /(?<![\w.$])(?<!function )(?:window\.)?confirm\(/],
  ['prompt', 'prompt', /(?<![\w.$])(?:window\.)?prompt\(/],
];

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (e.name !== '__tests__' && e.name !== 'ui') walk(p, out);
    } else if (/\.(vue|js)$/.test(e.name) && !/\.(test|spec|generated)\./.test(e.name)) out.push(p);
  }
  return out;
}

function trigger(line, next) {
  const s = `${line} ${next || ''}`;
  const m = s.match(/(?:v-if|v-show|:open|:model-value|v-model(?::open)?)="([^"]+)"/) || s.match(/@click(?:\.\w+)*="([^"]+)"/);
  return m ? m[1].slice(0, 60) : '-';
}

export function census() {
  const rows = [];
  for (const file of walk(SRC)) {
    const rel = path.relative(path.join(ROOT, 'frontend'), file).split(path.sep).join('/');
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    lines.forEach((line, i) => {
      if (/^\s*(?:\/\/|\*|<!--)/.test(line)) return;
      for (const [type, system, re] of RULES) {
        if (!re.test(line)) continue;
        if (system === 'hand-rolled' && /class="modal"/.test(line) && lines.slice(Math.max(0, i - 3), i).some((l) => /modal-(?:backdrop|overlay|mask)/.test(l))) continue; // card inside its backdrop
        // destructive: look at this tag plus the next 8 lines (title, buttons)
        const ctx = lines.slice(i, i + 9).join(' ');
        rows.push({ file: rel, line: i + 1, type, system, trigger: trigger(line, lines[i + 1]), destructive: DESTRUCTIVE.test(ctx) });
        break;
      }
    });
  }
  return rows;
}

export function toMarkdown(rows) {
  const by = (k) => rows.reduce((m, r) => ((m[r[k]] = (m[r[k]] || 0) + 1), m), {});
  const files = {};
  for (const r of rows) (files[r.file] ||= []).push(r);
  const out = ['| system | count |', '|---|---|', ...Object.entries(by('system')).map(([k, v]) => `| ${k} | ${v} |`), '',
    '| page / component | systems (count) | destructive |', '|---|---|---|'];
  for (const [f, rs] of Object.entries(files).sort()) {
    const sys = Object.entries(rs.reduce((m, r) => ((m[r.system] = (m[r.system] || 0) + 1), m), {})).map(([k, v]) => `${k}(${v})`).join(' ');
    out.push(`| \`${f.replace(/^src\//, '')}\` | ${sys} | ${rs.filter((r) => r.destructive).length} |`);
  }
  return out.join('\n');
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const rows = census();
  console.log(process.argv.includes('--json') ? JSON.stringify(rows, null, 1) : toMarkdown(rows));
}
