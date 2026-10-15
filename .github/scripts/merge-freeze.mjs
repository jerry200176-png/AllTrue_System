// merge-freeze: one parser for operations/merge-freeze.yaml, shared by auto-merge-safe.yml and land-queue.
// Fail closed: the file is "not frozen" only when it has exactly one top-level `frozen: false` line.
// Anything else (missing, unreadable, malformed, duplicate keys, other values) is frozen.
import { execFileSync } from 'node:child_process';

export const FREEZE_PATH = 'operations/merge-freeze.yaml';

const scalar = (v) => v.replace(/\s+#.*$/, '').trim().replace(/^(["'])(.*)\1$/, '$2');

export function parseFreeze(text) {
  if (typeof text !== 'string' || !text.trim()) return { frozen: true, reason: 'merge-freeze file missing or empty; fail closed', since: '' };
  const keys = {};
  for (const line of text.split(/\r?\n/)) {
    const m = /^(frozen|reason|since):(.*)$/.exec(line);
    if (!m) continue;
    if (m[1] in keys) return { frozen: true, reason: `duplicate ${m[1]} key; fail closed`, since: '' };
    keys[m[1]] = scalar(m[2]);
  }
  const since = keys.since || '';
  if (keys.frozen === 'false') return { frozen: false, reason: keys.reason || '', since };
  if (keys.frozen === 'true') return { frozen: true, reason: keys.reason || 'merge freeze', since };
  return { frozen: true, reason: 'merge-freeze file malformed (need frozen: true|false); fail closed', since };
}

// Reads the file from the base branch through the API (never the PR head). Any error -> frozen.
export function readFreeze(repo, ref = 'main', run = (args) => execFileSync('gh', args, { encoding: 'utf8' })) {
  try {
    return parseFreeze(run(['api', '-H', 'Accept: application/vnd.github.raw', `/repos/${repo}/contents/${FREEZE_PATH}?ref=${ref}`]));
  } catch (e) {
    return { frozen: true, reason: `merge-freeze file unreadable (${String(e.message).split('\n')[0]}); fail closed`, since: '' };
  }
}

// CLI: node merge-freeze.mjs <repo> [ref] -> prints frozen=<bool> and reason=<text> (GITHUB_OUTPUT format).
if (import.meta.url === `file://${process.argv[1]}`) {
  const f = readFreeze(process.argv[2], process.argv[3] || 'main');
  console.log(`frozen=${f.frozen}\nreason=${f.reason.replace(/[\r\n]+/g, ' ')}`);
}
