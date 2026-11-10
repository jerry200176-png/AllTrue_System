# Case 5124 private reader — proposed trust, inactive preparation

**R3 / T3. Founder approved this same-scope #5159 reader proposal.** Continue under
the [recorded decision](../../docs/governance/FOUNDER_STANDING_DECISIONS.md#same-scope-approval-continuation),
with required exact-head CI/review and installed controls. Routine same-scope pin
correction does not require the same approval again. No merge, dispatch, recipient
registration or case-data read has occurred merely because these files exist.
No real private key belongs in GitHub, a runner, chat or an Agent handoff.

`reader_profile` defaults to `legacy`. All existing cases retain the existing
`DUMP_ARTIFACT_KEY` encryption, cleanup, artifact and permissions. Opting into
`case5124` permits only the existing `transfer_capacity_student` payload with its
fixed student/campus selector and empty StudentClass selector; caller target,
teacher, subject and recipient overrides are refused before SSH. No new database
query/account/grant, network listener or production write is added.

The following **reviewed candidate files are present but not yet main-trusted**.
The same-scope proposal has Founder approval; delivery still needs required CI,
review and installed controls. Fake test certificates are generated only inside
isolated container tmpfs:

* `operations/closeout/case5124-reader.cert.crt`: proposed self-signed RSA
  3072/4096 public certificate, valid throughout the capture/read window.
* `operations/closeout/case5124-reader.policy.json`: the exact fixed policy;
  the schema below documents its fields. The public certificate uses PEM encoding
  in a conventional `.crt` file; the existing blanket `.pem`/key-file ban remains.

Proposed certificate DER SHA256:
`918f44f461aec013c2fe1fd7df2acf47f62753bb63f616e04303922a66186575`.
Proposed window: **2026-11-10 05:00–10:00 UTC**, at most four first-attempt
dispatches under the same cumulative binding. The verified current-production
backend is `4d186abcf211e9b5109301285af093a57c8470d4`, canonical deploy run
`42752332278` (actual deploy successful; coordinator verified the 1200-second,
37-check watch). An unrelated unpublished UI release is not a prerequisite.
Capture still checks that canonical successful deployment and matching runtime;
no runtime identity check, expiry or capture limit is relaxed.

```json
{
  "schema": 1,
  "binding": "case5124-private-reader-v1",
  "recipient_sha256": "<approved certificate DER SHA256>",
  "not_before": "<UTC YYYY-MM-DDTHH:MM:SSZ>",
  "not_after": "<UTC YYYY-MM-DDTHH:MM:SSZ, at most five hours later>",
  "max_captures": 4,
  "expected_backend_sha": "<approved 40-character backend SHA>",
  "deploy_run_id": 0,
  "source_files_sha256": {
    ".github/workflows/production-case-dump.yml": "<approved file SHA256>",
    "scripts/case5124-private-reader.py": "<approved file SHA256>"
  }
}
```

The external approval packet records the final commit SHA. File pins avoid an
impossible self-referential commit hash in its own committed policy. The policy
is trusted reviewed source, not a caller input or proof of Founder approval by
itself. Routine same-scope source/pin correction follows the recorded continuation
decision; recipient/scope/window/cumulative cap or material protected-boundary
changes need a new concrete decision.
The public deployment manifest has no run ID: the approved policy binds the
canonical successful deployment/run pair; its public metadata and actual deploy
job are checked, and the manifest/backend SHA is checked before and after capture.
The remote probe checks its actual git SHA before and after its existing reads.
These checks do not establish a transactional snapshot during those reads.

Only owner-triggered, first-attempt, main `workflow_dispatch` runs with the fixed
binding run-name can capture. Existing per-case concurrency serializes them.
Public GitHub run metadata is paginated through empty in the fixed window; every
dispatch consumes a slot, including failures/cancellations/reruns. The first at
most four dispatches may capture; reruns cannot capture. The window is at most
five hours, shorter than the existing six-hour PII-run purge threshold. Missing,
rate-limited, malformed or incomplete public metadata means HOLD. This is not a
tamperproof registry: an owner deleting current-window runs could remove visible
history; do not delete/reuse this binding during its window. No new token or token
permission is introduced. Expired windows refuse reads as well as new captures.

The original JSON bytes are sealed with encrypted capture provenance in native
OpenSSL CMS AuthEnvelopedData / AES-256-GCM / RSA-OAEP-SHA256 / MGF1-SHA256.
The original DUMP ciphertext is untouched. The additional public JSON contains
only `status`, certificate fingerprint and ciphertext SHA256. No payload values,
IDs, ordinals, date hashes, dates, names or counts are projected publicly.

Download the authorized artifact using an existing permitted connector/CLI into
the approved private workspace. Do not expose signed download URLs. The reader
requires its actual GitHub artifact ID and ZIP: public artifact metadata must
bind its SHA256 digest to the successful approved source run. Missing GitHub
artifact digest refuses the read. Encryption alone does **not** authenticate the
sender. No arbitrary artifact URL or caller certificate is accepted.

```sh
umask 077
# Run only inside the specifically approved controlled workspace/window.
# The key path names an already-custodied private key, never key bytes in argv.
python3 scripts/case5124-private-reader.py read \
  --run-id APPROVED_RUN --artifact-id APPROVED_ARTIFACT \
  --artifact-zip PRIVATE_DOWNLOADED_ZIP --private-key PRIVATE_KEY_PATH \
  --output PRIVATE_DIRECTORY/production-case.json
```

Private directories must be owned mode 0700, downloaded ZIP/key mode 0600,
regular single-link files, no symlink. Outputs are mode 0600 and published
atomically without replacing an existing file. The bounded ZIP, native algorithm
profile, AEAD result, bounded capsule, exact schema/scope and encrypted run/source
provenance must all pass first. The current read window is checked again immediately
before atomic publication, including when native decryption crosses expiry.
OpenSSL can write plaintext before authentication
fails: it stays in private staging and is shredded on failure. Nothing is printed
except fixed verification/HOLD tokens. Shredding is namespace cleanup, not a
physical erasure guarantee for snapshots/overlay storage; SIGKILL cannot guarantee
cleanup. Mode bits do not isolate other agents/processes with the same Unix UID.
The destination's membership, retention/deletion, revocation and custody must be
approved separately; this candidate does not create those protections.

This remains the **existing non-transactional payload**, including its caps and
lineage/completeness flags. It lacks full financial/learning-record hashes and
cannot authorize reownership, repair, rollback or an assumption that all contracts
have eight lessons. Preserve actual dates, attendance, teaching and money evidence.

Synthetic test: `python3 scripts/tests/test_case5124_private_reader.py`.
It is explicitly invoked in the existing CI job; no trigger/job/permission change.
Native compatibility basis: [OpenSSL 3.0 CMS](https://docs.openssl.org/3.0/man1/openssl-cms/)
and [3.5 CMS](https://docs.openssl.org/3.5/man1/openssl-cms/). No crypto framework.
