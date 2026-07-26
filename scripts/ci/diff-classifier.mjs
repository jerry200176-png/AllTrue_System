/**
 * Base-aware risk-based reviewability classifier.
 * Used by Presubmit CHECK 2 + preflight (G2).
 */
import { execFileSync } from 'node:child_process';

export const BUCKETS = Object.freeze([
  'production_code', 'tests', 'e2e_fixtures', 'docs', 'workflow_governance',
  'generated', 'binary_evidence', 'lock_vendor', 'pure_deletions', 'other',
]);

const HIGH_RISK = [
  /^backend\/app\/Http\/Controllers\/Auth/i,
  /^backend\/app\/Http\/Middleware\//i,
  /^backend\/app\/Services\/.*(Billing|Payment|SessionDeduction|Tuition|Invoice)/i,
  /^backend\/database\/migrations\//i,
  /^backend\/routes\/api\.php$/i,
  /^frontend\/src\/.*[Pp]arent/,
  /^frontend\/src\/supabase\.js$/,
  /^\.github\/workflows\/(deploy|.*password|.*credential|.*repair|backup)/i,
  /^scripts\/.*(password|credential|repair|rotate)/i,
];

export function isHighRiskPath(p) {
  const x = p.replace(/\\/g, '/');
  return HIGH_RISK.some((re) => re.test(x));
}

export function classifyPath(filePath) {
  const p = filePath.replace(/\\/g, '/');
  if (/\.(png|jpe?g|webp|gif|pdf|zip|gz|woff2?)$/i.test(p) || /\/evidence\//.test(p)) return 'binary_evidence';
  if (/\.generated\.(js|ts|css)$/.test(p)) return 'generated';
  if (/(^|\/)(package-lock\.json|composer\.lock|.*\.lock)$/.test(p) || /vendor-modules\//.test(p)) return 'lock_vendor';
  if (/^(\.github\/|scripts\/ci\/|scripts\/.*preflight|scripts\/ci-|scripts\/control-plane|scripts\/agent-|scripts\/sync-generated)/.test(p)
    || /docs\/governance\//.test(p)) return 'workflow_governance';
  if (/^docs\//.test(p) || /\.md$/i.test(p) || /\.mdc$/i.test(p)) return 'docs';
  if (/\/(tests?|__tests__)\//.test(p) || /\.(test|spec)\.[jt]sx?$/.test(p) || /Test\.php$/.test(p) || /^backend\/tests\//.test(p)) return 'tests';
  if (/\/e2e\//.test(p) || /\/fixtures\//.test(p)) return 'e2e_fixtures';
  if (/^(backend\/app\/|backend\/routes\/|backend\/database\/|frontend\/src\/)/.test(p)) return 'production_code';
  return 'other';
}

/** Thresholds from 30d AllTrue data (vertical UI slices ~1500 raw; high-risk must stay tight). */
export const REVIEWABILITY_THRESHOLDS = Object.freeze({
  warnScore: 400,
  hardScore: 900,
  highRiskHardLines: 250,
  productionCodeHard: 700,
  binaryFilesHard: 20,
  docsOnlyHardScore: 1600,
  testsOnlyHardScore: 1400,
});

export function classifyNumstat(numstat) {
  const counts = Object.fromEntries(BUCKETS.map((b) => [b, 0]));
  const files = [];
  let highRiskLines = 0;
  let highRiskFiles = 0;
  for (const line of numstat.split('\n')) {
    if (!line.trim()) continue;
    const parts = line.split('\t');
    if (parts.length < 3) continue;
    let [addS, delS, file] = parts;
    if (file.includes('=>')) {
      const m = file.match(/\{?(.*?) => (.*?)\}?/);
      if (m) file = (m[2] || file).replace(/[{}]/g, '');
    }
    const add = addS === '-' ? 0 : Number(addS) || 0;
    const del = delS === '-' ? 0 : Number(delS) || 0;
    const binary = addS === '-' && delS === '-';
    let bucket = classifyPath(file);
    if (binary && bucket === 'other') bucket = 'binary_evidence';
    const total = add + del;
    if (add === 0 && del > 0 && bucket !== 'binary_evidence' && bucket !== 'generated') counts.pure_deletions += del;
    counts[bucket] += total;
    const hr = isHighRiskPath(file);
    if (hr) { highRiskLines += total; highRiskFiles += 1; }
    files.push({ file, add, del, bucket, highRisk: hr, binary });
  }
  return { counts, files, highRiskLines, highRiskFiles };
}

export function reviewBurdenScore(counts) {
  return Math.round(
    (counts.production_code || 0)
    + (counts.other || 0)
    + (counts.tests || 0) * 0.35
    + (counts.e2e_fixtures || 0) * 0.35
    + (counts.docs || 0) * 0.2
    + (counts.workflow_governance || 0) * 0.5
    + (counts.pure_deletions || 0) * 0.1,
  );
}

export function evaluateReviewability(classified, thresholds = REVIEWABILITY_THRESHOLDS) {
  const { counts, highRiskLines, highRiskFiles, files } = classified;
  const score = reviewBurdenScore(counts);
  const prod = counts.production_code || 0;
  const onlyDocs = prod === 0 && (counts.tests || 0) === 0
    && (counts.docs || 0) + (counts.workflow_governance || 0) > 0;
  const onlyTests = prod === 0 && (counts.tests || 0) + (counts.e2e_fixtures || 0) > 0;
  const binaryFiles = files.filter((f) => f.bucket === 'binary_evidence').length;
  const hard = onlyDocs ? thresholds.docsOnlyHardScore
    : onlyTests ? thresholds.testsOnlyHardScore : thresholds.hardScore;
  const blockers = [];
  const warnings = [];
  if (highRiskLines > thresholds.highRiskHardLines) {
    blockers.push({ reason: 'high_risk_lines', actual: highRiskLines, limit: thresholds.highRiskHardLines, files: highRiskFiles });
  }
  if (prod > thresholds.productionCodeHard) {
    blockers.push({ reason: 'production_code_lines', actual: prod, limit: thresholds.productionCodeHard });
  }
  if (score > hard) blockers.push({ reason: 'review_burden_score', actual: score, limit: hard });
  else if (score > thresholds.warnScore) warnings.push({ reason: 'review_burden_score', actual: score, limit: thresholds.warnScore });
  if (binaryFiles > thresholds.binaryFilesHard) {
    blockers.push({ reason: 'binary_files', actual: binaryFiles, limit: thresholds.binaryFilesHard });
  }
  return {
    score, productionCode: prod, highRiskLines, onlyDocs, onlyTests, blockers, warnings,
    ok: blockers.length === 0,
    mode: onlyDocs ? 'docs_only' : onlyTests ? 'tests_only' : 'standard',
  };
}

export function resolveDiffBase({ env = process.env, defaultBase = 'origin/main' } = {}) {
  if (env.PR_BASE_SHA) return env.PR_BASE_SHA;
  if (env.GITHUB_BASE_SHA) return env.GITHUB_BASE_SHA;
  if (env.GITHUB_BASE_REF) return `origin/${env.GITHUB_BASE_REF.replace(/^refs\/heads\//, '')}`;
  return defaultBase;
}

export function gitNumstat(baseRef, headRef = 'HEAD', cwd = process.cwd()) {
  return execFileSync('git', ['diff', '--numstat', '--find-renames', `${baseRef}...${headRef}`], {
    cwd, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024,
  });
}

/** Founder exception: process control bound to PR#+exact SHA — not Agent self-approval. */
export function matchFounderException(exception, { prNumber, headSha, checkId }) {
  if (!exception || typeof exception !== 'object') return { ok: false, reason: 'missing' };
  if (String(exception.pr_number) !== String(prNumber)) return { ok: false, reason: 'pr_mismatch' };
  if (exception.head_sha !== headSha) return { ok: false, reason: 'sha_mismatch' };
  if (exception.waived_check !== checkId) return { ok: false, reason: 'check_mismatch' };
  if (exception.expires_at && Date.parse(exception.expires_at) < Date.now()) return { ok: false, reason: 'expired' };
  if (!exception.why_unsplittable || !exception.independent_review_evidence || !exception.rollback) {
    return { ok: false, reason: 'incomplete_ledger' };
  }
  // Honest: this is process control, not cryptographic Founder identity.
  return { ok: true, reason: 'process_control_match', note: 'Not cryptographic non-repudiation' };
}
