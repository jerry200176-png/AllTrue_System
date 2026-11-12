#!/usr/bin/env node
// Apply one rootfam:* label per GitHub issue from a JSON map {"<issue number>": "<family slug>"}.
// Dry-run by default (prints issue numbers and slugs only); --apply writes. Idempotent: an issue that
// already has a rootfam:* label is skipped and never changed. The map holds ids and slugs, never report text.
// Usage: node scripts/ci/rootfam-backfill.mjs --map map.json [--apply]   (apply needs GH_TOKEN, GITHUB_REPOSITORY)
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const LABELS_YML = new URL('../../.github/labels.yml', import.meta.url);

export function knownFamilies(text = readFileSync(LABELS_YML, 'utf8')) {
  return new Set([...text.matchAll(/name: "rootfam:([a-z0-9-]+)"/g)].map((m) => m[1]));
}

export function validateMap(map, families = knownFamilies()) {
  if (!map || typeof map !== 'object' || Array.isArray(map)) throw new Error('map must be an object');
  return Object.entries(map).map(([num, slug]) => {
    if (!/^[1-9]\d*$/.test(num)) throw new Error(`bad issue number: ${num}`);
    if (!families.has(slug)) throw new Error(`unknown family for #${num}`);
    return { number: Number(num), label: `rootfam:${slug}` };
  });
}

// api(method, path, body) -> parsed JSON. Returns the plan lines; writes only when apply is true.
export async function run(map, { apply = false, api, families } = {}) {
  const lines = [];
  for (const { number, label } of validateMap(map, families)) {
    const issue = await api('GET', `/issues/${number}`);
    const have = (issue.labels || []).map((l) => l.name).filter((n) => n.startsWith('rootfam:'));
    if (have.length) {
      lines.push(`skip #${number}: already ${have.length > 1 ? 'has multiple rootfam labels' : have[0]}`);
      continue;
    }
    if (apply) await api('POST', `/issues/${number}/labels`, { labels: [label] });
    lines.push(`${apply ? 'label' : 'would label'} #${number}: ${label}`);
  }
  return lines;
}

async function main() {
  const argv = process.argv.slice(2);
  const i = argv.indexOf('--map');
  if (i < 0 || !argv[i + 1]) throw new Error('usage: --map map.json [--apply]');
  const apply = argv.includes('--apply');
  const map = JSON.parse(readFileSync(argv[i + 1], 'utf8'));
  const repo = process.env.GITHUB_REPOSITORY;
  const token = process.env.GH_TOKEN;
  const api = async (method, path, body) => {
    if (!repo || !token) throw new Error('GITHUB_REPOSITORY and GH_TOKEN are required');
    const res = await fetch(`https://api.github.com/repos/${repo}${path}`, {
      method,
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/vnd.github+json', 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) throw new Error(`${method} ${path}: HTTP ${res.status}`);
    return res.json();
  };
  const out = await run(map, { apply, api });
  console.log(out.join('\n') + `\n${apply ? 'applied' : 'dry-run (nothing written)'}: ${out.length} issues`);
}

if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  main().catch((e) => { console.error(e.message); process.exit(1); });
}
