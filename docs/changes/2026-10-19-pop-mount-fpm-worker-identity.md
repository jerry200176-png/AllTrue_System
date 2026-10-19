# 2026-10-19 — ci: POP mount uses serving PHP-FPM worker identity

silent_ship: true

Mount workflow now chowns `reviewed-financial-cases.json` to the live PHP-FPM **pool worker** uid/gid from `/proc`, sets mode 640 (600 if worker is admin), refuses world-readable modes, and checks path search via `namei` / `runuser test -r` without printing financial payload. Closes the #4310 Permission denied gap without the #4312 `chmod 644` fallback. Authorized app `createDraft` remains the final remount acceptance step.
