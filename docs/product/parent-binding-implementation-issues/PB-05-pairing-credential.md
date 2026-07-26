# PB-05 — Pairing credential lifecycle

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T3 |
| Dependencies | PB-04 |
| Blocks | PB-07, PB-08 |

## Scope

- `pairing_credentials` table：token_hash only； expiry； max_uses； attempt_count； revoke； created_by； campus/student scope.
- Staff APIs：issue、regenerate、revoke、copy metadata（raw once）.
- Parent APIs：safe status inspect、atomic consume → create relationship + LINE projection.
- LINE command `綁定碼 …` and/or LIFF deep link.
- QR payload = deep link； audit events.
- Rate limits + brute-force lockout on credential.

## Non-scope

- SMS OTP； email delivery provider； rewriting entire ParentPortal chrome.

## Acceptance criteria

1. Raw token never stored； never in application logs.
2. Consume is atomic； concurrent double-consume cannot create two relationships for different parents beyond max_uses.
3. Expired/revoked/consumed return correct codes； anonymous inspect leaks no student PII.
4. Staff cannot issue for other campus students.
5. UX copy matches UX Spec for code states.

## Tests

- Unit：hash、expiry、state transitions.
- Feature：issue/consume/revoke/regenerate； brute force； IDOR； cross-campus； replay.
- Security：log PII scan on pairing paths.

## Rollback

- Flag `parent_binding_pairing=off`； legacy bind still available if flag on.
