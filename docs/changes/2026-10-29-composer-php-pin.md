## 2026-10-29 — chore(deps): composer platform pin 8.2.30 → 8.2.35 to match production (#4441)
<!-- release-notes: silent_ship=silent-2026-10-29-composer-php-pin -->
<!-- silent-reason: Dependency resolution pin only; production already runs PHP 8.2.35 and no code changes. -->
- `backend/composer.json` `config.platform.php` is now 8.2.35 (lock regenerated with `composer update --lock`, content-hash only). The Dockerfiles still use php:8.1-fpm and are tracked separately.
