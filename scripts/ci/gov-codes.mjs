/** CI governance error codes + failure taxonomy (shared by preflight / reports). */

export const TAXONOMY = Object.freeze([
  'PRODUCT_CODE_DEFECT', 'TEST_DEFECT', 'FLAKY_TEST', 'GENERATED_ARTIFACT_DRIFT',
  'PROVENANCE_STALE', 'PROVENANCE_INVALID', 'MERGE_CONFLICT_MARKERS', 'BRANCH_NAMING_POLICY',
  'PR_SIZE_POLICY', 'CHANGELOG_POLICY', 'WORKFLOW_SYNTAX', 'PATH_FILTER_ERROR',
  'MISSING_FILE_OR_FIXTURE', 'DEPENDENCY_INSTALL', 'NETWORK_OR_REGISTRY', 'RUNNER_ENVIRONMENT',
  'CACHE_CORRUPTION', 'SECRET_OR_PERMISSION', 'PRODUCTION_CONNECTIVITY', 'STALE_RUN_CANCELLED',
  'EXPECTED_SKIP', 'UNKNOWN',
]);

export const GOV_CODES = Object.freeze({
  BRANCH: 'GOV-BRANCH-001', PROV_PARSE: 'GOV-PROV-001', PROV_MARKERS: 'GOV-PROV-002',
  PROV_CONSISTENCY: 'GOV-PROV-003', GENERATED: 'GOV-GENERATED-001', SIZE: 'GOV-SIZE-001',
  CHANGELOG: 'GOV-CHANGELOG-001', WORKFLOW_YAML: 'GOV-WORKFLOW-001', BASE: 'GOV-BASE-001',
  FIXTURE: 'GOV-FIXTURE-001', SECRET: 'GOV-SECRET-001', DOCS_LINK: 'GOV-DOCS-001',
});

const RULES = [
  [/cancel-in-progress|superseded/, 'STALE_RUN_CANCELLED'],
  [/branch naming|invalid branch name|gov-branch-001/, 'BRANCH_NAMING_POLICY'],
  [/pr size|too large|hard limit|gov-size-001|reviewability/, 'PR_SIZE_POLICY'],
  [/conflict marker|<<<<<<<|>>>>>>>/, 'MERGE_CONFLICT_MARKERS'],
  [/generated in-app updates|sync-release-notes|staffUpdates\.generated|parentUpdates\.generated|changelogDraft\.generated/, 'GENERATED_ARTIFACT_DRIFT'],
  [/changelog.*(?:missing|not updated)/, 'CHANGELOG_POLICY'],
  [/yaml|workflow syntax|unable to parse|invalid workflow/, 'WORKFLOW_SYNTAX'],
  [/econnreset|etimedout|socket hang up|registry.*503|502/, 'NETWORK_OR_REGISTRY'],
  [/npm ci|composer install/, 'DEPENDENCY_INSTALL'],
  [/(?:secret|gitleaks|credential).*(?:scan|auth)|(?:scan|auth).*(?:secret|gitleaks)/, 'SECRET_OR_PERMISSION'],
  [/ssh|pi\.lifenet|daan\.lifenet|host key/, 'PRODUCTION_CONNECTIVITY'],
  [/playwright.*timeout|\bflake\b|intermittent/, 'FLAKY_TEST'],
  [/(?:test\.php|tests\/).*(?:undefined method|syntax error)/, 'TEST_DEFECT'],
  [/phpunit|vitest|failed asserting|expected /, 'PRODUCT_CODE_DEFECT'],
  [/fixture|no such file|missing file/, 'MISSING_FILE_OR_FIXTURE'],
  [/cache.*(corrupt|ENOENT)|hash mismatch/, 'CACHE_CORRUPTION'],
  [/\bskipped\b|draft pr/, 'EXPECTED_SKIP'],
];

export function classifyStepFailure({ workflow = '', job = '', step = '', logSnippet = '' } = {}) {
  const hay = `${workflow}\n${job}\n${step}\n${logSnippet}`.toLowerCase();
  const prov = /provenance|manifest\.json|human-authored/.test(hay);
  if (prov && /stale|ancestor|base_sha/.test(hay)) return { primary: 'PROVENANCE_STALE', secondary: null };
  if (prov) return { primary: 'PROVENANCE_INVALID', secondary: null };
  for (const [re, primary] of RULES) {
    if (re.test(hay)) {
      const secondary = primary === 'FLAKY_TEST' ? 'TEST_DEFECT'
        : primary === 'PRODUCT_CODE_DEFECT' ? 'TEST_DEFECT'
          : primary === 'NETWORK_OR_REGISTRY' ? 'DEPENDENCY_INSTALL' : null;
      return { primary, secondary };
    }
  }
  return { primary: 'UNKNOWN', secondary: null };
}

export function classifySameShaRerun({ earlierConclusion, laterConclusion, headShaSame }) {
  if (!headShaSame) return { kind: 'code_changed' };
  if (earlierConclusion === 'failure' && laterConclusion === 'success') return { kind: 'flake', primary: 'FLAKY_TEST' };
  if (earlierConclusion === 'failure' && laterConclusion === 'failure') return { kind: 'deterministic' };
  return { kind: 'other' };
}

export function formatGovError({ code, message, actual, policy, fix, blocking = true }) {
  return { code, message, actual: actual ?? null, policy: policy ?? null, fix: fix ?? null, blocking: Boolean(blocking) };
}
