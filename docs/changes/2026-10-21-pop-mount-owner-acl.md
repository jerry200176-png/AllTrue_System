---
silent_ship: true
---

# ci: POP mount grants the serving worker read with an owner ACL, not sudo

Production `sudo -n chown` requires a password (run 39427575892), so the
reviewed-financial mount always failed closed. `validate_mount_file` now runs
without sudo: the deploy account (file owner) sets
`u::rw-,g::---,o::---,u:<worker uid>:r--`, adds `u:<uid>:--x` only on
`storage/app/private` and its `pop` directory when needed, and checks read and
every ancestor traversal with a getfacl-based decision for the bound worker
uid and its `/proc` groups. Missing `acl` tools, a failed setfacl, or any
denial fails closed with LIVE preserved. The atomic-stage test now extracts the
workflow's own functions and uses real ACLs, including a kernel cross-check
when run as root; guards ban sudo in the remote and pin the exact ACL.
