/**
 * Guard: director-facing Vue templates must not interpolate internal IDs
 * as the primary label (課程 #{{ id }}, SC #{{ id }}, 學生 #{{ id }}, …).
 *
 * Systemic prevention for BulkLeave / tuition / renew UX debt recurrence.
 * Allowed: techId via formatter (computed), :key bindings, API params.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const srcRoot = path.resolve(__dirname, '..');

const FORBIDDEN = [
  { name: '課程 #{{', re: /課程\s*#\s*\{\{/ },
  { name: 'SC #{{', re: /SC\s*#\s*\{\{/i },
  { name: '學生 #{{', re: /學生\s*#\s*\{\{/ },
  { name: '帳單 #{{', re: /帳單\s*#\s*\{\{/ },
  { name: 'COURSE-${', re: /COURSE-\$\{/ },
  { name: 'Payment #{{', re: /Payment\s*#\s*\{\{/i },
];

function walkVueFiles(dir, out = []) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    if (ent.name === 'node_modules' || ent.name === 'dist') continue;
    const full = path.join(dir, ent.name);
    if (ent.isDirectory()) walkVueFiles(full, out);
    else if (ent.isFile() && ent.name.endsWith('.vue')) out.push(full);
  }
  return out;
}

const files = walkVueFiles(srcRoot);
assert.ok(files.length > 20, 'expected to scan Vue sources');

const leaks = [];
for (const file of files) {
  const text = fs.readFileSync(file, 'utf8');
  for (const rule of FORBIDDEN) {
    if (rule.re.test(text)) {
      leaks.push(`${path.relative(srcRoot, file)}: ${rule.name}`);
    }
  }
}

assert.deepEqual(
  leaks,
  [],
  `Director-facing ID interpolations found:\n${leaks.join('\n')}\n`
    + 'Use studentClassDisplay / scheduleDisplay / billing formatters instead.',
);

console.log(`directorFacingIdLeak.test.js: scanned ${files.length} vue files, no ID leaks`);
