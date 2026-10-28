## 2026-10-29 — Cloud session bootstrap hook

<!-- release-notes: silent_ship=cloud-session-bootstrap -->
<!-- silent-reason: Engineering-only startup hook and canonical skill links; no product UI change. -->

Cloud-only SessionStart hook (`CLAUDE_CODE_REMOTE=true`) installs dependencies without third-party lifecycle scripts: `composer install --no-scripts --no-plugins` with `source-fallback=false`, `npm ci --ignore-scripts`, an environment allowlist, a throwaway Composer home, and a fixed Unix-socket test DB client. Runtime proxy/CA trust is preserved. Unsupported setup fails without claiming dependency or DB readiness. Canonical skill symlinks replace copied skills; the offline security regressions run through the existing skill-entry check. No Production operation or credential is added. Founder approved the hook on 2026-10-29.
