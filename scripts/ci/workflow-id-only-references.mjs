// Control-plane rule P3 (2026-11-12, #5201 row 26): workflow approval references and `pop-*`
// workflow file names are built from IDs and structural words, never from a person's name.
// The repository is public, so a romanised name in a file name or in `founder-go-<name>-<date>`
// publishes who a repair was about. Two workflows that did this were removed in the same change.
//
// What is checked
//   - every approval reference literal in a workflow: the right-hand side of `APPROVAL_REF=...`,
//     the value of an `approval_reference` JSON key, and any other `founder-go-...` literal
//     (for example the string a poll loop compares against);
//   - the base name of every `.github/workflows/pop-*.yml` / `.yaml` file.
// Shape: lowercase tokens joined by `-`. A token is digits (an id, a date), or letters that are
// in STRUCTURAL_WORDS, or a mix such as `invoice1997`, `td076`, `c15`, `r2` whose letter runs are
// all in STRUCTURAL_WORDS. Shell and Actions expansions (`$CASE_ID`, `${DIGEST:0:12}`,
// `${{ inputs.x }}`) count as an id.
//
// STRUCTURAL_WORDS is a closed vocabulary of operation words. A new operation word is added here
// in a reviewed PR. A person's name is never added: use the student, contract, invoice, issue or
// in-app report id (docs/pop/CONTROL_PLANE_AUTH.md "Naming: IDs, never names").
// Dependency-free on purpose: control-plane-lint runs before `npm ci`.

export const STRUCTURAL_WORDS = Object.freeze([
  // approval reference grammar
  'founder', 'go', 'standing', 'a', 'auto', 'pop', 'issue', 'inapp', 'pr', 'run', 'case',
  // id prefixes: campus, repair round, tech-debt item
  'c', 'r', 'td',
  // operation vocabulary in use on main
  'attended', 'backlog', 'billing', 'cancel', 'catchup', 'charge', 'closures', 'contract',
  'correction', 'dup', 'duplicate', 'extra', 'financial', 'forward', 'header', 'hidden',
  'history', 'invoice', 'item', 'ledger', 'lesson', 'mount', 'net', 'occurrence', 'once',
  'overdeduct', 'package', 'past', 'period', 'pin', 'pool', 'reassign', 'rekey', 'repair',
  'repairs', 'resolve', 'reviewed', 'rollback', 'scheduled', 'secret', 'shared', 'signin',
  'stale', 'taught', 'telegram', 'trim', 'unbilled', 'unpaid', 'values', 'webhook',
]);
const WORDS = new Set(STRUCTURAL_WORDS);

const EXPANSION = /\$\{\{[^}]*\}\}|\$\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*/g;
const POP_FILE = /^pop-.*\.ya?ml$/;

/** Why `value` is not ID-only, or null when it is. `value` is the literal with expansions still in it. */
export function idOnlyProblem(value) {
  const flat = value.replace(EXPANSION, '0');
  if (flat === '') return 'is empty';
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(flat)) return 'is not lowercase letters and digits joined by "-"';
  const unknown = [...new Set(flat.match(/[a-z]+/g) ?? [])].filter((word) => !WORDS.has(word));
  if (unknown.length === 0) return null;
  // The offending word is not echoed: CI logs of a public repository must not repeat a name.
  return `has ${unknown.length} word(s) outside the structural vocabulary`;
}

const unquote = (v) => v.replace(/^\\?(['"])(.*?)\\?\1$/, '$2');

/** Approval reference literals on one line, comments already excluded by the caller. */
function referencesOnLine(line) {
  const found = [];
  // Shell assignment. The value ends at whitespace or `;` (a quoted value has no spaces in this grammar).
  for (const m of line.matchAll(/\bAPPROVAL_REF(?:ERENCE)?=("[^"]*"|'[^']*'|[^\s;"']*)/g)) found.push(unquote(m[1]));
  // JSON key, plain or backslash-escaped inside a shell string.
  for (const m of line.matchAll(/\\?["']approval_reference\\?["']\s*:\s*\\?["']([^"'\\]*)\\?["']/g)) found.push(m[1]);
  // Any other founder-go literal (comparisons, messages).
  for (const m of line.matchAll(/(?<![A-Za-z0-9-])founder-go-(?:[A-Za-z0-9_.-]|\$\{\{[^}]*\}\}|\$\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*)+/g)) {
    found.push(m[0].replace(/[.-]+$/, ''));
  }
  return [...new Set(found)];
}

/** @returns {{ line: number, reason: string }[]} */
export function checkWorkflowText(text) {
  const problems = [];
  text.split('\n').forEach((raw, index) => {
    if (raw.trimStart().startsWith('#')) return;
    for (const value of referencesOnLine(raw)) {
      // A bare expansion (`"$APPROVAL_REF"`) carries no literal to judge.
      if (value.replace(EXPANSION, '') === '') continue;
      const problem = idOnlyProblem(value);
      if (problem) problems.push({ line: index + 1, reason: `approval reference ${problem}` });
    }
  });
  return problems;
}

/** @returns {string | null} */
export function checkWorkflowFileName(basename) {
  if (!POP_FILE.test(basename)) return null;
  const problem = idOnlyProblem(basename.replace(/\.ya?ml$/, ''));
  return problem ? `pop workflow file name ${problem}` : null;
}
