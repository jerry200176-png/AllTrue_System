# Cloud session bootstrap preparation

silent_ship: Engineering-only startup hook and canonical skill links; no product UI change.

The proposed cloud hook preserves runtime proxy/CA trust, disables dependency scripts/plugins and source fallback, and fixes local test-DB clients to an explicit Unix socket. Unsupported setup fails without claiming dependencies or DB readiness. Offline security regressions run through the existing skill-entry check. No Production operation or credentials are added; activation remains subject to the PR's security review and approval boundary.
