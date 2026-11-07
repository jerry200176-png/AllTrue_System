# Daan staging V1 (single source with `infra/daan-staging/README.md`)

This is **staging**, not production. Staging health is never production verification.
Pi production stays on `deploy.yml` → Environment `production-activation`; staging never connects to it.

Pattern: dev → staging → prod, GitOps **pull** model (Argo CD / Flux style). Issues #868, #875.

| Item | Decision |
|---|---|
| Host | idle Daan machine (`daan-ai`, 3.4 GB shared with Dify, Hermes, host MySQL, Apache) |
| Docker | **rootless**, per-user daemon (`systemctl --user`); no docker group, no passwordless sudo |
| Data | synthetic only (`infra/daan-staging/seed-demo.php`); never a production dump or backup mirror |
| Memory | mysql 512m, app 512m, nginx 64m; `preflight.sh` refuses to start below 900 MB available |
| Deploy | `systemd --user` timer every 15 min deploys the newest `main` commit whose push CI is green; rolls back on smoke failure |
| Access | `127.0.0.1:18080` only. Public URL via Cloudflare Tunnel (outbound only) + Cloudflare Access (Founder email + one service token for the PR preview proxy) |
| Reset | nightly `migrate:fresh` + seed (03:30), so shared preview clicks cannot accumulate |
| Secrets | generated on the host (`openssl rand`), kept in the git-ignored `.env`; nothing in GitHub |

Lifecycle states (never say "production verified"):
`CODE_WRITTEN` → `TESTED` → `STAGING_DEPLOYED` → `STAGING_RUNTIME_VERIFIED`

Operations (commands, rootless setup, timer, tunnel, demo logins): [`infra/daan-staging/README.md`](../../infra/daan-staging/README.md).
Out of scope: Pi production, production DNS, production DB sync, TrueFit ON, Temporal, self-hosted runners, any Dify/Hermes change.
