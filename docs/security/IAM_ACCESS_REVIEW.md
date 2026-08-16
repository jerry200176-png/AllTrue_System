---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-08-08
---

# IAM / Access Review

> Addresses [#888](https://github.com/jerry200176-png/AllTrue_System/issues/888). Reference model: SOC2 access review, least-privilege, joiner/mover/leaver process. Findings below are from a live, read-only pull on 2026-08-08 (`gh api`, Pi `getent`/`authorized_keys`) — not a written policy alone.

## 1. GitHub repository access (`jerry200176-png/AllTrue_System`)

| Login | Role | Notes |
|---|---|---|
| `jerry200176-png` | Admin (owner) | |
| `MonkeyJeng` | Write | Colleague who co-maintains AllTrue (confirmed this session — also runs the `hermes-agent` service on the production Pi, tracked separately in [#1676](https://github.com/jerry200176-png/AllTrue_System/issues/1676)) |

Two collaborators total. **Owner action needed**: confirm `MonkeyJeng`'s Write access is still current and intentional (last-confirmed date below), and record it — this table's "last reviewed" date IS the confirmation record going forward.

## 2. GitHub Actions repository secrets

| Secret | Set | Note |
|---|---|---|
| `CI_DB_PASSWORD` | 2026-04-24 | |
| `PI_HOST` | 2026-04-26 | ⚠️ overlaps with `PI_SSH_HOST` below — possible leftover from a naming migration |
| `PI_HOST_KEY` | 2026-05-30 | |
| `PI_SSH_HOST` | 2026-04-24 | Canonical name per `CLAUDE.md` G-006 |
| `PI_SSH_KEY` | 2026-04-26 | |
| `PI_SSH_USER` | 2026-04-24 | Canonical name per `CLAUDE.md` G-006 |
| `PI_USER` | 2026-04-26 | ⚠️ overlaps with `PI_SSH_USER` |
| `SENTRY_DSN` | 2026-04-26 | |
| `SMOKE_BASE_URL`, `SMOKE_TEACHER_LOGIN`, `SMOKE_TEACHER_PASS`, `SMOKE_TEACHER_PASSWORD`, `SMOKE_TEACHER_USER` | 2026-05-31 | ⚠️ both `SMOKE_TEACHER_PASS` and `SMOKE_TEACHER_PASSWORD` exist — likely one is dead/unused |
| `UPTIMEROBOT_API_KEY` | 2026-04-25 | |

**Owner action needed**: confirm whether `PI_HOST`/`PI_USER` and `SMOKE_TEACHER_PASS` are still referenced by any workflow (a quick `grep -rn "PI_HOST\b\|PI_USER\b\|SMOKE_TEACHER_PASS\b" .github/workflows/` would settle it) — if unused, remove rather than leave dangling credentials with no consumer.

## 3. Production Pi (`pi.lifenet.com.tw`) — system accounts

| User | UID | Note |
|---|---|---|
| `jeng` | 1000 | Likely `MonkeyJeng`'s own account (per naming) |
| `huihui` | 1001 | **Owner action needed**: identify and confirm still-needed |
| `admin` | 1002 | The deploy/service account this session's SSH access used |

### 3.1 Third-party production agent

The colleague-owned Hermes gateway running under `admin` is tracked as a
temporary, Founder-approved exception in
[`PRODUCTION_AGENT_EXCEPTION_HERMES_2026-08.md`](./PRODUCTION_AGENT_EXCEPTION_HERMES_2026-08.md)
and [Issue #1676](https://github.com/jerry200176-png/AllTrue_System/issues/1676).
Its capability inventory, service owner identity, and least-privilege plan are
still open owner/colleague actions; do not infer them from the service being
owner-readable.

## 4. Production Pi — SSH keys authorized on `admin`

4 keys in `~/.ssh/authorized_keys`, identified by their trailing comment/label:

| Label | Assessment |
|---|---|
| `rsa-key-20230629` | Dated label suggests ~3 years old (2023). **Owner action needed**: confirm still in use or rotate/remove — stale keys are the most common real-world IAM finding in this style of review. |
| `github-actions-deploy` | Expected — matches `deploy.yml`'s automated deploy path. |
| *(unlabeled ed25519 key)* | No identifying comment. **Owner action needed**: identify whose key this is and label it — an unlabeled key is a gap this review exists to catch, not something to leave unresolved. |
| `jerry200176@gmail.com` | Jerry's own key. |

## 5. Third-party platform access (not independently verifiable from this session)

LINE, Sentry (`alltrue-n5.sentry.io`), UptimeRobot member/API-key lists were **not** pulled — this session has no credentials to query those consoles directly. Listed here so the gap is visible rather than silently skipped:

- [ ] LINE Developers console member list
- [ ] Sentry organization member list + API key scopes
- [ ] UptimeRobot account access

**Owner action needed**: pull these three lists directly (they require logging into each console) and append to this table on the next quarterly review.

## 6. Acceptance against #888

- [x] Access inventory pulled from live sources (not asserted from memory) — GitHub done fully, Pi done fully, third-party platforms explicitly flagged as not-yet-pulled rather than guessed.
- [x] High-privilege accounts have an owner column above.
- [ ] Stale entries removed or an issue opened — **not done by this pass**: 3 concrete candidates identified (`rsa-key-20230629`, unlabeled Pi key, duplicate `PI_HOST`/`PI_USER`/`SMOKE_TEACHER_PASS` secrets) but removal requires owner confirmation first (removing an SSH key or secret that's silently still in use would be a self-inflicted outage, exactly the class of mistake this repo's incident log already warns about) — flagging, not acting.
- [ ] Quarterly cadence — this doc's `review_cycle: quarterly` frontmatter is the mechanism; next review due 2026-11-08.
