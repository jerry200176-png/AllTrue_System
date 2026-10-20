---
silent_ship: true
---

# ci: POP mount binds serving FPM worker; refuse world-readable mode

Mount workflow resolves the **app-serving** PHP-FPM identity (vhost for
`/home/admin/backend` / `daan.lifenet.com.tw` → listen upstream → matching pool
`user=` + `[pool]` name → live `php-fpm: pool <name>` worker `/proc` Uid/Gid),
chowns the mount file, sets mode with `sudo -n chmod` (640/600), refuses
world-readable modes, and proves readability via `namei` / `runuser` without
printing financial payload. `mode=probe-identity` reports the binding without
writing. Guard script + `--self-test` ban first-pool and `chmod 644/777`.
Authorized app `createDraft` remains the remount acceptance step for #4310.
