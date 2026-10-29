## 2026-10-29 — chore(deps): remove the unused @supabase/supabase-js from the frontend
<!-- release-notes: silent_ship=silent-2026-10-29-drop-supabase-js -->
<!-- silent-reason: Unused dependency removed; nothing imports it (src/supabase.js is a hand-written client over /api/v1), so the screens are unchanged. -->
- Repo-wide grep finds no `@supabase` import in `frontend/src`, scripts, tests or Vite config; `npm uninstall` regenerated the lockfile (-108 lines). Vite build and all 1776 vitest tests pass.
