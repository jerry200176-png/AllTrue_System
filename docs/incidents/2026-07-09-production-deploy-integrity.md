# Incident: Production deploy integrity failure (R62)

> **Status:** Root cause closed — fix PR [#1103](https://github.com/jerry200176-png/AllTrue_System/pull/1103) merged 2026-07-08.  
> **Follow-up controls:** partially shipped — actual per-control status in §6 (⚠️ an earlier draft of this document overstated "controls merged"; unshipped pieces are archived on the salvage branch, tracked by [#1122](https://github.com/jerry200176-png/AllTrue_System/issues/1122)).  
> **GitHub:** [#1102](https://github.com/jerry200176-png/AllTrue_System/issues/1102) · fix PR [#1103](https://github.com/jerry200176-png/AllTrue_System/pull/1103)  
> **Regression rules:** `docs/AI_REGRESSION_LESSONS.md` §R62 · §R67

---

## 1. Summary

Deploy to Pi **reported success** while shipping a **stale** commit (`1a68c00a`) instead of the CI-verified head (`fa906f9c`). Health and smoke passed because old code was still healthy — **silent stale deploy**.

After manual recovery and #1103 merge, production origin reached `3b2f9307` (2026-07-09). Secondary factor: **Cloudflare edge cache** (`max-age=14400` on `/assets/*`) can extend stale UI for users with long-lived tabs after a frontend deploy.

---

## 2. Timeline (UTC)

| Time | Event |
|------|-------|
| 2026-06-29 ~03:07 | Last successful `git fetch` on Pi (stale `origin/main` tracking ref frozen) |
| 2026-07-08 10:35 | Deploy run `28936180885` — CI head `fa906f9c`, Pi HEAD landed `1a68c00a`, run **green** |
| 2026-07-08 12:05 | PR #1103 merged — fail-closed fetch + `TARGET_SHA` + HEAD assertion |
| 2026-07-08 15:30 | Frontend rebuild deploy `4ca27f7f` — current asset bundle (`index-D8q1Tg6g.js`) |
| 2026-07-09 06:55 | Backend deploy `3b2f9307` — origin aligned with `main` |

---

## 3. Root cause (three layers)

1. **Poisoned Pi git config:** `http.sslbackend=schannel` (Windows-only) → `git fetch` fatal on Linux.
2. **Swallowed error:** deploy SSH block lacked `set -e`; fetch fatal did not abort.
3. **Stale symbolic ref:** `git reset --hard origin/main` reset to tracking ref last updated ~Jun 29, not CI `head_sha`.

---

## 4. Impact

| Area | Effect |
|------|--------|
| **Users** | 16+ merges not live on frontend/backend between stale ref and #1103 |
| **War room** | Fixes marked resolved in-app while production still on old bundle |
| **Ops trust** | Green deploy runs ≠ correct SHA shipped |
| **Cloudflare** | Secondary: 4h asset TTL prolongs old JS for users who don't hard-reload |

Missed fixes included #1087 (projection API), #1079 (LR backfill), #1105 (same-slot student merge), #1109 (leave-requested consistency).

---

## 5. Failure chain of the stale deploy

```
CI merge (head_sha = X)
  → deploy SSH: git fetch FATAL (schannel) — not aborted
  → git reset --hard origin/main → stale ref Y (Y ≠ X)
  → optional frontend rebuild FROM Y
  → version.json t/hash both from Y → smoke green
  → GitHub Actions: success
```

---

## 6. Controls — actual status (2026-07-09)

| Control | Status |
|---------|--------|
| Fail-closed fetch + `TARGET_SHA` reset + HEAD assertion (`deploy.yml`) | ✅ merged — PR #1103 |
| Migration failure marks run red (R67) | ✅ PR #1120 (this incident's sibling: `migrate --force` failure was also swallowed) |
| Deploy contract CI lint (`scripts/lib/deploy-integrity-contract.mjs`) | ⏳ salvage branch — #1122 |
| Nightly Pi HEAD vs last green deploy SHA (`deploy-drift-check`) | ⏳ salvage branch — #1122 |
| External asset smoke (Layer 2b) | ⏳ salvage branch — #1122 |
| `version.json` v2 schema (`build_sha`/`deploy_sha`) + update-checker hash compare | ⏳ salvage branch — #1122（production 仍為 v1 `{t, hash}`） |

---

## 7. `version.json` v2 schema (design — not yet shipped)

```json
{
  "t": "…", "hash": "…",
  "build_sha": "<vite build commit>", "built_at": "…",
  "deploy_sha": "<last CI-verified deploy>", "deployed_at": "…"
}
```

| Field | Owner | Meaning |
|-------|-------|---------|
| `build_sha` / `built_at` / `hash` / `t` | Vite build | Frontend bundle identity (L1) |
| `deploy_sha` / `deployed_at` | deploy-time patcher on Pi | Last CI-verified deploy that reached origin (L2) |

**Backend-only deploy:** `build_sha` unchanged; `deploy_sha` advances — expected, not drift.

---

## 8. Verification commands (read-only)

```bash
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
curl -sk https://daan.lifenet.com.tw/version.json | python3 -m json.tool
curl -sI https://daan.lifenet.com.tw/assets/index-D8q1Tg6g.js | grep -iE 'HTTP/|content-type|cf-cache'
```

---

## 9. Open follow-ups

- [ ] Investigate who wrote `schannel` into `/home/admin/.git/config` (#1102)
- [ ] Cloudflare: consider purge `/assets/*` on frontend deploy or `Cache-Control: immutable` on hashed assets
- [ ] Ship high-value salvage controls via reviewed PRs (#1122): drift-check nightly, version.json v2, external asset smoke
- [x] Migration-failure visibility in deploy runs — PR #1120 (R67)

---

## 10. Related

- `docs/SMOKE_TEST_RUNBOOK.md`
- `docs/RUNBOOK_ROLLBACK.md`
- `docs/archive/AI_REGRESSION_LESSONS_ARCHIVE.md` §R62
