/**
 * User-facing copy gate for staff/parent product updates.
 * Detects engineering jargon — does NOT rewrite. Fail closed on hits.
 */

const FORBIDDEN_PATTERNS = [
  { id: 'pr_issue', re: /\b(?:PR|pr)\s*#?\d+\b|#\d{2,}\b|\bin-app\s*#\d+/i, hint: 'PR/issue 編號' },
  { id: 'td_adr', re: /\bTD-\d+\b|\bADR-?\d+\b|\bFR-\d+\b|\bR\d{2,}\b/i, hint: 'TD/ADR/FR/Rnn 內部編號' },
  { id: 'phase', re: /\bPhase\s*\d+[A-Z]?\b|\bTrack\s*[A-Z]\b/i, hint: 'Phase/Track 工程階段' },
  { id: 'sha', re: /\b[0-9a-f]{7,40}\b/i, hint: '疑似 commit SHA' },
  { id: 'class_service', re: /\b[A-Z][A-Za-z0-9]+(?:Controller|Service|Observer|Command|Factory|Middleware|Handler)\b/, hint: 'class/service 名' },
  { id: 'pascal_ident', re: /\b(?:IsContractException|RemainingSessions|StudentClass|ClassSession|VoidReason|StudentSingIn)\b/, hint: '內部欄位/識別名' },
  { id: 'api_path', re: /\b(?:GET|POST|PUT|PATCH|DELETE)\s+\/[A-Za-z0-9_/?&=.-]+|\/api\/v\d+\//i, hint: 'API path' },
  { id: 'file_ext', re: /\b[\w./-]+\.(?:vue|js|mjs|php|md|yml|json|cjs)\b/i, hint: '原始碼檔名' },
  { id: 'internal_tooling', re: /\b(?:SELECT|SQL|Laravel|Vue|PHPUnit|PHP|CI|CD|migration)\b|deploy\.yml|production\s+smoke|smoke\s+(?:test|check)|\bHTTP\s+\d{3}\b/i, hint: 'SQL/框架/工具/HTTP 內部術語' },
  { id: 'impl_en', re: /\b(?:refactor|fallback|payload|middleware|DTO|enum|handler|pool coverage|design system tokens?|DS tokens?|read-only|default-off|dry-run)\b/i, hint: '英文實作黑話' },
  { id: 'mvp_phase_zh', re: /群組\s*MVP|continuity\s*policy|planner|observability/i, hint: '工程縮寫/規劃語' },
  { id: 'truncated_leading', re: /\b\d{1,3}\s*復活|(?:^|[\s：:，,])e?view(?:\.\.\.|…)/i, hint: '疑似被截斷的殘詞（如「55 復活」「eview…」）' },
  { id: 'empty_praise', re: /^(?:優化系統(?:使用)?體驗|改善使用體驗|修正若干問題|提升穩定性|內部調整|效能改善)[。．]?$/, hint: '無資訊空話' },
];

/**
 * @param {string} text
 * @returns {{ id: string, hint: string, match: string }[]}
 */
export function findUserFacingCopyIssues(text) {
  const t = String(text || '').trim();
  if (!t) return [{ id: 'empty', hint: '空白文案', match: '' }];
  const hits = [];
  for (const p of FORBIDDEN_PATTERNS) {
    const m = t.match(p.re);
    if (m) hits.push({ id: p.id, hint: p.hint, match: m[0] });
  }
  return hits;
}

/**
 * @param {string[]} texts
 * @param {string} label
 */
export function assertUserFacingCopy(texts, label = 'copy') {
  const issues = [];
  for (const text of texts) {
    for (const hit of findUserFacingCopyIssues(text)) {
      issues.push(`${label}: ${hit.hint} ← «${hit.match || text}»`);
    }
  }
  if (issues.length) {
    const err = new Error(`user-facing copy gate failed:\n- ${issues.join('\n- ')}`);
    err.issues = issues;
    throw err;
  }
}

/** Soft length helpers (Chinese-oriented; counts UTF-16 code units ≈ chars for CJK). */
export function countChars(s) {
  return Array.from(String(s || '')).length;
}
