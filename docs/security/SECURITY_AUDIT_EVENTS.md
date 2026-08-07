# Security audit events (#1420)

## Contract

`security_audit_events` is an append-only evidence table for authorization and LINE delivery investigations. It stores hashed references, not names, phone numbers, LINE user IDs, access tokens, message bodies, or raw provider payloads.

The first implementation covers:

- `parent.auth`: LINE profile rejection, unbound identity, and successful session creation.
- `parent.sibling_switch`: allowed and forbidden sibling-switch attempts.
- `line.binding.created`, `line.binding.validated`, and `line.binding.revoked`.
- `notification.delivery`: learning-feedback and tuition-reminder delivery outcomes per verified binding.

Each event includes an event type, outcome, correlation UUID, optional campus, hashed actor/subject/binding references, allow-listed metadata, and a `retention_until` timestamp. The default retention window is 180 days and can only be changed through reviewed configuration/migration work.

## Privacy and access

- `SecurityAuditEvent::ref()` uses an application-key HMAC; raw identifiers never enter the table.
- Metadata is allow-listed. Unknown keys, names, phones, LINE IDs, tokens, and message content are discarded.
- The event writer is best-effort: a missing/unavailable evidence table cannot turn an authentication or notification path into a 500.
- Query access remains restricted to authorized operational/security roles; no public API exposes this table.

## Verification and rollout

- Unit tests prove reference hashing and metadata PII exclusion.
- Parent LINE login feature tests prove success/failure events are emitted without raw identifiers.
- The migration is prepared in the Draft PR but must be run in production only through the Founder-gated migration process.
- Rollback is a code revert plus the normal migration rollback procedure; no production rollback was run by the agent.
