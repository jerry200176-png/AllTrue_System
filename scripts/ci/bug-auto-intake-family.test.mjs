import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const wf = readFileSync(new URL('../../.github/workflows/bug-auto-intake.yml', import.meta.url), 'utf8');
assert.match(wf, /family: \(\(\.family \/\/ ""\)\|tostring\|ascii_downcase\|gsub\("\[\^a-z-\]";""\)\)/, 'family is sanitised before it leaves the candidates job');
assert.match(wf, /\[\[ "\$fam" =~ \^\[a-z\]\+\(-\[a-z\]\+\)\*\$ \]\]/, 'family must be a label-safe slug');
assert.match(wf, /\$DRY_RUN" != "true" \] && \[\[ "\$fam"/, 'dry-run writes nothing');
assert.match(wf, /cluster "\$id" "\$fam" "\$url" \|\| echo "::warning::/, 'clustering is advisory: failure never blocks intake');
const fn = wf.slice(wf.indexOf('cluster() {'), wf.indexOf('pairs=\'[]\''));
assert.match(fn, /family-link:\$\{num\}/, 'comment is idempotent via a marker');
assert.match(fn, /for who in "\$\{GITHUB_REPOSITORY_OWNER\}" app\/github-actions/, 'only trusted authors are linked');
assert.match(fn, /select\(\. != \$\{num\}\)/, 'never links an issue to itself');
assert.doesNotMatch(fn, /issue close|gh issue reopen|--state closed/, 'advisory only: never closes duplicates');
console.log('bug-auto-intake family contract: PASS');
