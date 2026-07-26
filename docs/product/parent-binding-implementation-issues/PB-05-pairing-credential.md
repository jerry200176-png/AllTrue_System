# PB-05 — Pairing credential lifecycle

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T3 |
| Dependencies | PB-04 |
| Blocks | PB-07, PB-08 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| Status | backlog / blocked on PB-04 |

## Scope

- `pairing_credentials`：token_hash only；expiry；**max_uses=1**（Founder）；attempt_count；revoke；created_by；campus/student scope.
- Default TTL **7 days**；staff may choose **24h / 72h / 7d** only；**no permanent codes**；no extend-old-token.
- Active unused cap：**4** per student+campus.
- Each guardian gets an **independent** credential（no shared multi-use default）.
- Staff APIs：issue、regenerate、revoke； raw returned once.
- Parent APIs：safe status inspect、**atomic** consume → relationship + LINE projection.
- LINE `綁定碼 …` and/or LIFF deep link； QR； audit； rate limits + brute-force lockout.

## Non-scope

- SMS OTP； email provider； rewriting entire ParentPortal chrome； shared multi-use codes as default.

## Acceptance criteria

1. Raw token never stored； never in application logs.
2. Consume atomic； concurrent double-consume cannot exceed max_uses=1.
3. Expired/revoked/consumed return correct codes； inspect leaks no student PII.
4. Staff cannot issue cross-campus； 5th active unused → `ACTIVE_CREDENTIAL_CAP`.
5. UX matches TTL options and per-guardian codes.

## Tests

- Unit：hash、expiry、state transitions、cap.
- Feature：issue/consume/revoke/regenerate； brute force； IDOR； cross-campus； replay； concurrent consume.
- Security：log PII scan on pairing paths.

## Rollback

- Flag `parent_binding_pairing=off`； legacy bind still available if flag on.
