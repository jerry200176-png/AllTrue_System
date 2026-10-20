## 2026-10-21 — ci(pop): POP mount grants the serving worker read with an owner ACL, not sudo (#4333)
<!-- release-notes: silent_ship=silent-2026-10-21-pop-mount-owner-acl -->
<!-- silent-reason: ops workflow change; no product UI change -->

Production `sudo -n chown` requires a password (run 39427575892), so the
reviewed-financial mount always failed closed. `validate_mount_file` now runs
without sudo and in this order: acl tools present → content valid → every
unmanaged ancestor already traversable (no changes yet) → the deploy account
(file owner) replaces the file ACL with `setfacl --set
u::rw-,g::---,o::---,u:<worker uid>:r--,m::r--` and verifies it exactly (no
extra principals, mask `r--`) → `u:<uid>:--x` only on `storage/app/private`
and `pop` when missing, backed up with `getfacl` and restored with
`setfacl --restore` on any later failure → read check for the bound worker
uid and its `/proc` groups. A `mode=mount` run also refuses to start while
deploy still runs a recursive chmod over `storage` (#4332), because the next
deploy would widen the ACL mask and other bits again.

Side effects that remain on failure: none on LIVE (contents, owner, mode, ACL)
and none on the managed directories; the discarded STAGE is removed. Access is
decided statically from getfacl; LSMs, mount options and capabilities on the
host are not modelled, so the authorized app read stays the acceptance step.
