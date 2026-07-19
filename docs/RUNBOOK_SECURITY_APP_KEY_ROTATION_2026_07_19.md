---
owner: platform-security
status: active
incident: 1007
---

# APP_KEY containment package — 2026-07-19

## Decision

The production fingerprint audit found that `APP_KEY` still matched a value in
publicly retrievable Git history. Telegram and active bearer credentials did
not match. Raw values and fingerprints are intentionally excluded.

Repository inspection found no application or database encryption calls or
encrypted model casts. Cookie sessions are encrypted, so all users will be
signed out. The legacy payment-report HMAC also derives from `APP_KEY`, but its
public routes and tests are deprecated. The compromised key is therefore not
retained as a compatibility key.

## Execution and safety

The one-time operation is executed only by the canonical `deploy.yml` control
plane. The production script:

1. requires exactly one existing `APP_KEY` entry;
2. generates 32 random bytes locally on production and never prints either key;
3. writes a permission-restricted temporary file and validates its shape;
4. atomically replaces only the environment file;
5. writes an out-of-repository idempotency marker;
6. lets the normal deploy rebuild Laravel caches and run health plus
   authenticated smoke verification.

No database mutation is involved. The old compromised key is not copied into
GitHub, logs, artifacts, or a compatibility setting. If preconditions fail,
the environment file is unchanged and deployment stops. After atomic
replacement, rollback restores code only and must not restore the compromised
key; any valid Laravel `APP_KEY` is compatible with stored unencrypted data.

## Verification and closure

- Deployment completes with health and authenticated smoke checks passing.
- Credential Fingerprint Audit reports `APP_KEY DIFFERENT` without exposing a
  value or fingerprint.
- Issue #1007 receives links to the deployment and audit evidence.
- This one-time script may remain for auditability; its marker makes later
  deployments no-ops.
