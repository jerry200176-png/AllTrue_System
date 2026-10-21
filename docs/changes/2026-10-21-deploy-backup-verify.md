## 2026-10-21 — ci(deploy): verify the pre-migration DB backup before migrating
<!-- release-notes: silent_ship=silent-2026-10-21-deploy-backup-verify -->
<!-- silent-reason: ops workflow change; no product UI change -->

`deploy.yml` now runs `scripts/verify-db-backup.sh` right after the pre-migration `mysqldump`: file non-empty, `gunzip -t`, and at least one `CREATE TABLE`. Any failure aborts the deploy through `abort_deploy` before migrations run.
