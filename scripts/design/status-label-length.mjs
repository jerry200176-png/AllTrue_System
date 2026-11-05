#!/usr/bin/env node
// Advisory (never fails CI): payment-area status labels must be 6 characters or fewer (UI/UX plan #4659, Q6).
// Longer explanation belongs behind a 「?」 / title. Usage:
//   node scripts/design/status-label-length.mjs          report new vs baseline, exit 0
//   node scripts/design/status-label-length.mjs update   rewrite status-label-baseline.json
// Heuristic scan (line based), no dependencies. Baseline is a sorted list of "file|label".
import fs from 'node:fs';
import path from 'node:path';

export const MAX_LABEL_LENGTH = 6;
const SRC = 'frontend/src/';
export const SCOPE = [
  'lib/courseMoneyState.js', 'lib/paymentStatus.js', 'lib/tuitionPayStatus.js', 'lib/studentBillingRows.js',
  'pages/TuitionCollectionPage.vue', 'components/PaymentEntryModal.vue', 'components/AccountingLedgerModal.vue',
  'components/tuition/StudentBillingList.vue',
];
// A line that starts a label source: STATUS label maps / *_LABEL consts / label+tone objects / badge-chip markup.
const OPENER = /(?:STATUS|Status)\w*(?:LABEL|Label|CONFIG)|[A-Z]\w*_LABELS?\s*=|label:[^\n]*tone:|<AtBadge|class="[^"]*(?:chip|status-tag|badge)/;
const count = (s, re) => (s.match(re) || []).length;

/** Status-label strings (CJK text, no interpolation) longer than `max` chars in one file's text. */
export function findLongLabels(text, max = MAX_LABEL_LENGTH) {
  const found = new Set();
  let depth = 0; // inside a multi-line label object
  for (const line of text.split('\n')) {
    if (depth === 0 && !(OPENER.test(line) && !/REASON|showToast/.test(line))) continue;
    const strings = [...line.replace(/:?title="[^"]*"/g, '').matchAll(/(['"`])((?:(?!\1).)*?)\1/g)].map((m) => m[2])
      .concat([...line.matchAll(/>([^<>{}]+)<\//g)].map((m) => m[1].trim()));
    for (const s of strings) if (/[一-鿿]/.test(s) && !s.includes('${') && [...s].length > max) found.add(s);
    depth = Math.max(0, depth + count(line, /[{[(]/g) - count(line, /[}\])]/g));
  }
  return [...found];
}

export function scan(root = process.cwd()) {
  const out = [];
  for (const f of SCOPE) {
    const file = path.join(root, SRC, f);
    if (fs.existsSync(file)) for (const l of findLongLabels(fs.readFileSync(file, 'utf8'))) out.push(`${f}|${l}`);
  }
  return out.sort();
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const baselinePath = path.join(import.meta.dirname, 'status-label-baseline.json');
  const now = scan();
  if (process.argv[2] === 'update') {
    fs.writeFileSync(baselinePath, JSON.stringify(now, null, 2) + '\n');
    console.log(`status-label baseline written: ${now.length} labels over ${MAX_LABEL_LENGTH} chars`);
  } else {
    const base = new Set(JSON.parse(fs.readFileSync(baselinePath, 'utf8')));
    const fresh = now.filter((k) => !base.has(k));
    for (const k of fresh) {
      const [file, label] = k.split('|');
      console.log(`::warning file=${SRC}${file}::status label over ${MAX_LABEL_LENGTH} chars (${[...label].length}): ${label}`);
    }
    console.log(`status labels over ${MAX_LABEL_LENGTH} chars: ${now.length} total, ${fresh.length} new vs baseline (advisory)`);
  }
}
