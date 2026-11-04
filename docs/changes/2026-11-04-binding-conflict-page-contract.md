## 2026-11-04 — fix(ui): binding conflict review page loads and resolves conflicts (#3936)
<!-- release-notes: user-visible -->
- The conflict review page read the wrong response key, so it always showed an empty list; it now reads `conflicts`.
- Resolve now posts `{action, binding_id}` as the API expects: super admins keep one binding (`keep`), directors remove one binding of their own campus (`delete`).
- The campus and bound-date columns come from each binding. The list reloads after resolving.
