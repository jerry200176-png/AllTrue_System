> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# GitHub Ruleset Enforcement — Platform Enforcement Binding (ADR-001)

> **Truth model:** Only **verified applied GitHub state** is trusted — not declared config alone.  
> Attestation: `bash scripts/platform/github-enforcement-attestation.sh`

## Live vs declared (2026-06-27 audit)

| Control | Config requires | Live GitHub |
|---------|-----------------|-------------|
| Platform Gate (ACTIVE) | ✅ | ❌ Missing |
| Control Plane Verify (ACTIVE) | ✅ | ❌ Missing |
| PR review ≥1 | ✅ | ✅ |
| Block force push | ✅ | ✅ |
| Linear history | ✅ | ❌ |
| Environments (staging, production-*) | ✅ | ❌ None |
| Ruleset ACTIVE | ✅ | ❌ Disabled |

Apply: `bash scripts/platform/apply-platform-enforcement.sh`

---

## Final enforcement DAG (truth model)

```
PR
 └─▶ Platform Gate (assemble + Ed25519 sign PDP)
      └─▶ Control Plane Verify (schema, v3 binding, root signature)
           └─▶ GitHub Enforcement Attestation (live API vs config)
                └─▶ Merge (GitHub required checks)
                     └─▶ Deploy Staging
                          ├─▶ GitHub Enforcement Attestation (re-attest)
                          ├─▶ PDP Signing (re-sign with staging hash)
                          └─▶ Staging artifact upload
                               └─▶ Deploy Production
                                    ├─▶ GitHub Enforcement Attestation
                                    ├─▶ verify-pdp-signature --require-staging
                                    ├─▶ verify-promotion-integrity
                                    └─▶ SSH Deploy (sole authority)
```

Legacy `deploy.yml` → **FAIL CLOSED** (zero deploy steps).

---

## GitHub Enforcement Attestation

Script: `scripts/platform/github-enforcement-attestation.sh`

- Queries **live** GitHub API (branch protection, reviews, environments, rulesets)
- Compares to `config/github/platform-enforcement.json`
- Writes `ci-artifacts/enforcement/enforcement_state.json`
- Computes `enforcement_attestation_hash` (SHA256 of attestation body)

**Required** for all deployment gates. No deploy without PASS attestation.

---

## PDP Root-of-Trust Signing

| Component | Path |
|-----------|------|
| Public trust anchor | `config/platform/pdp-signing-authority.pub.pem` |
| Private key (production) | GitHub secret `PDP_SIGNING_PRIVATE_KEY` |
| Sign / verify | `scripts/platform/pdp_signing_authority.py` |
| Shell gate | `scripts/platform/verify-pdp-signature.sh` |

Every deployable `ci-artifacts/pdp.json` includes:

```json
"pdp_signature": {
  "signer": "platform-control-plane",
  "public_key_fingerprint": "<sha256 SPKI DER>",
  "signature": "<base64 ed25519>",
  "signed_at": "<ISO8601>",
  "payload_sha256": "<sha256>",
  "algorithm": "ed25519"
}
```

Production deploy requires `--require-staging` (staging hash in signature scope).

⛔ `PDP_SIGNING_ALLOW_TEST_KEY=1` is **rejected** on production deploy paths.

---

## Enforcement invariants

1. GitHub attestation PASS = live state matches config (not merely documented)
2. PDP artifact MUST have valid Ed25519 `pdp_signature` from committed public key
3. Artifact body unchanged since signing (`payload_sha256`)
4. Staging hash bound before production (`--require-staging`)
5. No unsigned / unverifiable artifact may reach SSH deploy
6. No fallback re-assemble of PDP on production auto path

---

## Activation checklist

```bash
# 1. Apply GitHub platform controls
bash scripts/platform/apply-platform-enforcement.sh

# 2. Add PDP signing secret (PEM matching config/platform/pdp-signing-authority.pub.pem)
#    GitHub → Settings → Secrets → PDP_SIGNING_PRIVATE_KEY

# 3. Verify
bash scripts/platform/github-enforcement-attestation.sh
bash scripts/platform/github-ruleset-audit.sh --branch main --enforce
```

See also: `docs/adr/ADR-001-single-production-authority.md`, `config/platform/README.md`
