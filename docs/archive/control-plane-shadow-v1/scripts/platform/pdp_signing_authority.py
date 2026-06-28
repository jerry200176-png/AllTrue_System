#!/usr/bin/env python3
"""PDP root-of-trust signing authority — Ed25519 artifact signatures."""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey

REPO = Path(__file__).resolve().parents[2]
PUBLIC_KEY = REPO / "config/platform/pdp-signing-authority.pub.pem"
SIGNER_ID = "platform-control-plane"
SIGNATURE_BLOCK = "pdp_signature"


def canonical_json(obj: Any) -> str:
    return json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def load_public_key(path: Path | None = None) -> bytes:
    key_path = path or PUBLIC_KEY
    if not key_path.exists():
        raise FileNotFoundError(f"missing public key: {key_path}")
    return key_path.read_bytes()


def public_key_fingerprint(pub_pem: bytes) -> str:
    pub = serialization.load_pem_public_key(pub_pem)
    if not isinstance(pub, Ed25519PublicKey):
        raise ValueError("public key must be Ed25519")
    der = pub.public_bytes(
        encoding=serialization.Encoding.DER,
        format=serialization.PublicFormat.SubjectPublicKeyInfo,
    )
    return hashlib.sha256(der).hexdigest()


def load_private_key() -> Ed25519PrivateKey:
    if os.environ.get("PDP_SIGNING_PRIVATE_KEY"):
        pem = os.environ["PDP_SIGNING_PRIVATE_KEY"].encode("utf-8")
    elif os.environ.get("PDP_SIGNING_PRIVATE_KEY_B64"):
        pem = base64.b64decode(os.environ["PDP_SIGNING_PRIVATE_KEY_B64"])
    elif os.environ.get("PDP_SIGNING_ALLOW_TEST_KEY") == "1":
        test_key = REPO / "config/platform/pdp-signing-authority.test.pem"
        if not test_key.exists():
            raise RuntimeError("test signing key missing — see config/platform/README.md")
        pem = test_key.read_bytes()
    else:
        raise RuntimeError(
            "PDP signing private key missing — set PDP_SIGNING_PRIVATE_KEY (production) "
            "or PDP_SIGNING_ALLOW_TEST_KEY=1 for local/test only"
        )
    key = serialization.load_pem_private_key(pem, password=None)
    if not isinstance(key, Ed25519PrivateKey):
        raise ValueError("private key must be Ed25519")
    return key


def signing_payload(artifact: dict[str, Any]) -> bytes:
    body = {k: v for k, v in artifact.items() if k != SIGNATURE_BLOCK}
    return canonical_json(body).encode("utf-8")


def sign_bytes(message: bytes, private_key: Ed25519PrivateKey) -> bytes:
    return private_key.sign(message)


def verify_bytes(message: bytes, signature: bytes, public_key: Ed25519PublicKey) -> bool:
    try:
        public_key.verify(signature, message)
        return True
    except InvalidSignature:
        return False


def sign_artifact(artifact: dict[str, Any], *, private_key: Ed25519PrivateKey | None = None) -> dict[str, Any]:
    key = private_key or load_private_key()
    pub_pem = load_public_key()
    payload = signing_payload(artifact)
    signature = sign_bytes(payload, key)
    signed = dict(artifact)
    signed[SIGNATURE_BLOCK] = {
        "signer": SIGNER_ID,
        "public_key_fingerprint": public_key_fingerprint(pub_pem),
        "signature": base64.b64encode(signature).decode("ascii"),
        "signed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "payload_sha256": hashlib.sha256(payload).hexdigest(),
        "algorithm": "ed25519",
    }
    return signed


def verify_artifact(
    artifact: dict[str, Any],
    *,
    require_staging_hash: bool = False,
    public_key_path: Path | None = None,
) -> tuple[bool, str]:
    block = artifact.get(SIGNATURE_BLOCK)
    if not isinstance(block, dict):
        return False, "missing pdp_signature block (root-of-trust)"

    for key in ("signer", "public_key_fingerprint", "signature", "signed_at", "payload_sha256"):
        if not block.get(key):
            return False, f"pdp_signature missing {key}"

    if block.get("signer") != SIGNER_ID:
        return False, f"unexpected signer: {block.get('signer')}"

    pub_pem = load_public_key(public_key_path)
    pub = serialization.load_pem_public_key(pub_pem)
    if not isinstance(pub, Ed25519PublicKey):
        return False, "public key must be Ed25519"
    expected_fp = public_key_fingerprint(pub_pem)
    if block.get("public_key_fingerprint") != expected_fp:
        return False, "public_key_fingerprint mismatch (untrusted signing key)"

    integrity = artifact.get("promotion_integrity") or {}
    if require_staging_hash and not integrity.get("staging_deployment_hash"):
        return False, "staging_deployment_hash required in signature scope for production"

    payload = signing_payload(artifact)
    if hashlib.sha256(payload).hexdigest() != block.get("payload_sha256"):
        return False, "artifact changed since signing (payload_sha256 mismatch)"

    try:
        sig = base64.b64decode(block["signature"])
    except (ValueError, TypeError):
        return False, "invalid base64 signature"

    if not verify_bytes(payload, sig, pub):
        return False, "Ed25519 signature verification failed"

    return True, "ok"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=("sign", "verify"))
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", default="")
    parser.add_argument("--in-place", action="store_true")
    parser.add_argument("--require-staging", action="store_true")
    args = parser.parse_args()

    path = Path(args.input)
    artifact = json.loads(path.read_text(encoding="utf-8"))

    if args.command == "sign":
        signed = sign_artifact(artifact)
        text = json.dumps(signed, indent=2, ensure_ascii=False) + "\n"
        if args.in_place or not args.output:
            path.write_text(text, encoding="utf-8")
        else:
            Path(args.output).write_text(text, encoding="utf-8")
        print(json.dumps({"signed": True, "fingerprint": signed[SIGNATURE_BLOCK]["public_key_fingerprint"]}))
        sys.exit(0)

    ok, msg = verify_artifact(artifact, require_staging_hash=args.require_staging)
    print(json.dumps({"valid": ok, "reason": msg}))
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
