# POP control-plane authentication

Human director/super_admin users retain the existing Bearer-authenticated
routes. The Pi machine uses an ApiClient with Purpose=pop_control_plane and
only pop:draft/pop:dry-run scopes through dedicated routes. It is never mapped
to a human user or approval role; actor and campus are audited.

Attendance-device clients remain unable to authenticate POP. Revoke the
ApiClient to disable it. There is no HTTP execute route; dual approval and the
existing Pi-local scheduler remain the execution boundary.

One-time bootstrap, after deployment, is host-local:

    php artisan pop:bootstrap-machine --campus-id=<approved-id> --confirm=POP_BOOTSTRAP_MACHINE

The command refuses overwrite, stores only a SHA-256 hash in ApiClient, and
keeps the generated credential in restricted storage/app/private/pop-machine.key;
it is never printed or committed. Current GitHub/Vercel/Supabase topology has
no channel for this Pi-local authenticated submit, so this is the minimal
human bootstrap.
