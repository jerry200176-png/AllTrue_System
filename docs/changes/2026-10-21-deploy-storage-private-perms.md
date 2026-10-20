---
silent_ship: true
---

# ci(deploy): stop the storage chmod from widening storage/app/private

Deploy step [2b/7] ran `chmod -R 775 storage bootstrap/cache`, which on every
deploy made `storage/app/private/pop-machine.key` and the reviewed-financial
manifest world-readable (and broke the `pop-bootstrap` mode-600 check on
reuse). The step now applies 775 to everything except `storage/app/private`,
removes leftover world bits from files and world-write from directories there, and restores the machine key to
600. Group bits are left alone so a named-user ACL mask is not widened.
If a private file stays world-accessible, a private directory stays
world-writable, the subtree cannot be fully scanned, or the key cannot be set
to 600, the script exits 3 and the deploy aborts through
`abort_deploy` (this step runs before migrations, so the automatic code
rollback applies) instead of reporting storage as hardened.
`scripts/tests/deploy-storage-perms.test.sh` runs the extracted step on a
synthetic tree (idempotent, ACL-preserving) in CI.
