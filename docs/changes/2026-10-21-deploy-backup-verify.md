## 2026-10-21 — ci(deploy): verify the pre-migration DB backup before migrating
<!-- release-notes: silent_ship=silent-2026-10-21-deploy-backup-verify -->
<!-- silent-reason: ops workflow change; no product UI change -->

The `deploy.yml` executor in `scripts/production-deploy/pi-remote-deploy.sh` runs `scripts/verify-db-backup.sh` right after the full-database pre-migration `mysqldump`: non-empty archive, `gunzip -t`, at least one `CREATE TABLE`, and a dated mysqldump completion footer as the final nonblank line. The producer explicitly enables `--comments --dump-date` so that footer is required. The streaming check consumes all decompressed input and preserves decompressor failures, including errors after output has been emitted. Any failure aborts through `abort_deploy` before migrations run; no SQL is logged or written to a plaintext temporary file.

Integration after #4384 retains the extracted runner, renderer and environment mapping. The complete symbolic executor payload equals the previously reviewed inline payload after replacing GitHub expressions with the extraction placeholders; backup wiring assertions now inspect the actual template.

Focused synthetic archive/stub regressions run in required Control Plane Contract Lint as well as `test:gov`. These checks establish gzip/format/completion evidence only: they are not a restore drill, schema coverage proof, offsite verification or a new recovery-point guarantee. Merge remains a T3 control-plane decision; no live backup, restore, migration or production action was performed during preparation. Rollback is a normal revert of the workflow/helper/test wiring.
