---
silent_ship: true
---

# ci: POP mount binds serving FPM worker; refuse world-readable mode

Mount workflow chowns `reviewed-financial-cases.json` to the live PHP-FPM pool worker (pool config user + matching `/proc` Uid/Gid), sets mode with `sudo chmod` (640/600), refuses other-read fallbacks, and checks path search via `namei` / `runuser` without printing financial payload. Authorized app `createDraft` remains the remount acceptance step for #4310.
