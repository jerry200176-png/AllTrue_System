# PDP Signing Authority — Root of Trust

The **public key** in this directory is the trust anchor for all deployable `ci-artifacts/pdp.json` artifacts.

| File | Purpose |
|------|---------|
| `pdp-signing-authority.pub.pem` | **Production trust anchor** (committed) |
| `pdp-signing-authority.test.pem` | Local/test private key only — never used in production deploy |

## Production setup

1. Generate production keypair (once):

```bash
openssl genpkey -algorithm ED25519 -out pdp-signing-authority.prod.pem
openssl pkey -in pdp-signing-authority.prod.pem -pubout -out pdp-signing-authority.pub.pem
```

2. Commit `pdp-signing-authority.pub.pem` to the repo.
3. Add private key to GitHub secret `PDP_SIGNING_PRIVATE_KEY` (full PEM contents).
4. Remove or rotate test private key from CI secrets scope.

## Signature block

Every deployable PDP artifact includes:

```json
"pdp_signature": {
  "signer": "platform-control-plane",
  "public_key_fingerprint": "<sha256 of SPKI DER>",
  "signature": "<base64 ed25519>",
  "signed_at": "<ISO8601>",
  "payload_sha256": "<sha256 of artifact body>",
  "algorithm": "ed25519"
}
```

Verification: `bash scripts/platform/verify-pdp-signature.sh ci-artifacts/pdp.json --require-staging`
