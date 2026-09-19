# Staging environment (issue #868)

## What this is

A second copy of the app on a **dedicated staging host** so `main` can be
exercised before real users see it.

**Dell staging path (Founder direction):** native **production-parity** stack,
not Ubuntu-for-staging and not container-only staging. The Dell may later
become a Production Candidate, so match the current Pi production runtime.

Deliberately **not** the production Pi: `CONTROL_PLANE_CONTRACT.md` I1 says
only `deploy.yml` (or POP Executor) may execute changes on the production
box. An earlier second SSH deploy path on the same Pi was archived
(`docs/archive/control-plane-shadow-v1/`). A separate host sidesteps that
boundary instead of reopening it.

| Stage | Meaning | Required for usable staging? |
|---|---|---|
| **A — Host provisioning** | OS packages, Apache vhost, MariaDB DB/user, checkout dir, staging `.env` | Yes |
| **B — Manual exact-SHA deployment** | `git reset --hard <sha>`, composer, migrate, frontend build | Yes |
| **C — Staging smoke / TrueFit acceptance** | Health + identity (+ TrueFit checks when enabled) | Yes (acceptance) |
| **D — Not approved/implemented** | Requires separate Founder contract | **No** — do not block A–C |
| **E — Optional future production gate** | Gate `deploy.yml` / promotion on staging | **No** — Founder `[contract-change]` |

---

### A2. Run the provisioning script

```bash
# The script creates /home/staging/.config/alltrue/staging-db-password on
# first run with mode 0600, or validates that external file on rerun.
bash scripts/infra/setup-staging-env.sh <git-remote-url>
```

The script also writes the Apache vhost (`proxy_fcgi` →
`unix:/run/php/php8.2-fpm.sock`) and clones `/home/staging/AllTrue_System` if
needed. It does **not** deploy an exact SHA, does **not** require GitHub
secrets, and does **not** touch production.

---

---

When TrueFit flags are enabled on staging, add the acceptance checks from
`docs/truefit/` (hash route / shell) on this host only. Do not point
production UptimeRobot or production smoke credentials at staging.

---

## Stage D — Not approved / not implemented

Stage D is optional, not required for A–C, and is not implemented. Any future
automation or environment contract requires a separate Founder approval; do
not upload a staging database password or production secret to GitHub. See
this guide’s Stage D boundary only; no live-secret procedure is provided here.

---

## Stage E — Optional future production gate

Wiring `deploy.yml` to require staging health, or adding
`staging-deploy.yml`, needs its own `[contract-change]` against I1–I5.
Do that only after A–C have been exercised on the Dell — not in the same
change that stands staging docs/scripts up.

---

## Deliberately out of scope (this provisioning path)

- Editing `deploy.yml` / production runtime / production DB
- Production secrets, DNS cutover, Dell production migration
- Requiring Stage D before A–C
- Authenticated production smoke credentials on staging
- Automatic staging deploy without a control-plane carve-out
