/** Canonical branch prefix policy — Presubmit CHECK 1 + ci-preflight. */

export const BRANCH_PREFIXES = Object.freeze({
  feat: { status: 'accepted', riskHint: 'R1+' },
  fix: { status: 'accepted', riskHint: 'R1+' },
  hotfix: { status: 'accepted', riskHint: 'R2+' },
  refactor: { status: 'accepted', riskHint: 'R1+' },
  test: { status: 'accepted', riskHint: 'R0-R1' },
  docs: { status: 'accepted', riskHint: 'R0' },
  chore: { status: 'accepted', riskHint: 'R0-R1' },
  ci: { status: 'accepted', riskHint: 'R1-R2' },
  build: { status: 'accepted', riskHint: 'R1' },
  perf: { status: 'accepted', riskHint: 'R1+' },
  exp: { status: 'accepted', riskHint: 'R1' },
  sec: { status: 'accepted', riskHint: 'R2-R3' },
  security: { status: 'alias', mapsTo: 'sec', riskHint: 'R2-R3' },
  audit: { status: 'accepted', riskHint: 'R1-R2' },
  revert: { status: 'accepted', riskHint: 'R1+' },
  release: { status: 'accepted', riskHint: 'R2' },
  design: { status: 'accepted', riskHint: 'R1' },
  cursor: { status: 'accepted', riskHint: 'R0+' },
  claude: { status: 'accepted', riskHint: 'R0+' },
  'td-batch': { status: 'accepted', riskHint: 'R1' },
  dependabot: { status: 'accepted', riskHint: 'R1' },
  'cubelv-cli': { status: 'accepted', riskHint: 'R1' },
  agent: { status: 'rejected', note: 'use feat|fix|chore|cursor|sec' },
  ops: { status: 'rejected', note: 'use chore|ci|fix' },
  tmp: { status: 'rejected' },
  wip: { status: 'rejected', note: 'use draft PR' },
});

export function parseBranch(branch) {
  if (!branch || branch === 'HEAD' || branch === 'main' || branch === 'master') {
    return { ok: false, prefix: null, rest: null };
  }
  if (/^td-batch\d+-.+/.test(branch)) return { ok: true, prefix: 'td-batch', rest: branch };
  if (/^dependabot\/.+/.test(branch)) return { ok: true, prefix: 'dependabot', rest: branch };
  if (/^cubelv-cli-.+/.test(branch)) return { ok: true, prefix: 'cubelv-cli', rest: branch };
  const m = branch.match(/^(?<prefix>[a-z][a-z0-9-]*)\/(?<rest>.+)$/);
  if (!m) return { ok: false, prefix: null, rest: null };
  return { ok: true, prefix: m.groups.prefix, rest: m.groups.rest };
}

export function validateBranchName(branch) {
  const parsed = parseBranch(branch);
  if (!parsed.ok) {
    return {
      ok: false, canonicalPrefix: null,
      message: `Branch '${branch}' must use type/slug (feat/foo, sec/issue, cursor/task).`,
      fix: 'git branch -m feat/<slug>',
    };
  }
  const meta = BRANCH_PREFIXES[parsed.prefix];
  if (!meta) {
    return {
      ok: false, canonicalPrefix: null,
      message: `Prefix '${parsed.prefix}/' not allowlisted.`,
      fix: `git branch -m feat/${parsed.rest}`,
    };
  }
  if (meta.status === 'rejected' || meta.status === 'deprecated') {
    return {
      ok: false, canonicalPrefix: meta.mapsTo || null,
      message: `Prefix '${parsed.prefix}/' ${meta.status}${meta.note ? ` (${meta.note})` : ''}.`,
      fix: `git branch -m ${meta.mapsTo || 'feat'}/${parsed.rest}`,
    };
  }
  const canonical = meta.status === 'alias' ? meta.mapsTo : parsed.prefix;
  return {
    ok: true, canonicalPrefix: canonical,
    alias: meta.status === 'alias' ? parsed.prefix : null,
    riskHint: meta.riskHint || null,
    message: meta.status === 'alias'
      ? `Prefix '${parsed.prefix}/' alias of '${canonical}/'.`
      : `Branch prefix '${parsed.prefix}/' OK.`,
  };
}

export function slashPrefixRegexSource() {
  const keys = Object.entries(BRANCH_PREFIXES)
    .filter(([k, v]) => (v.status === 'accepted' || v.status === 'alias')
      && !['td-batch', 'dependabot', 'cubelv-cli'].includes(k))
    .map(([k]) => k);
  return `^(${keys.join('|')})/.+`;
}
