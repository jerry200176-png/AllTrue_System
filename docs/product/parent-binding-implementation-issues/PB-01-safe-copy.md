# PB-01 — Safe external copy & reason mapping

| Field | Value |
|-------|-------|
| Phase | 1 |
| Risk class | T1 |
| Dependencies | PB-00 |
| Blocks | PB-09 (partial) |

## Scope

- Replace anonymous/parent-visible failure copy with UX Spec safe copy.
- Stop echoing student name in LINE failure messages.
- Remove Portal 401 empty-phone existence leak for unauthenticated callers（use safe copy； keep internal reason）.
- Ambiguous LINE same-campus match → fail closed（no first-win）.
- Rate limit LINE bind attempts（per line_user_id / IP as practical）.
- Update outdated `LineIntegration` / help copy that teaches phone-less bind.

## Non-scope

- Pairing codes； relationship tables； Inbox.
- Changing successful bind verification method.

## Acceptance criteria

1. Name+wrong phone、name+empty phone、unknown name → **same** parent-visible copy.
2. LINE no longer selects first of multiple same-name+phone matches； returns safe failure + `AMBIGUOUS_MATCH` internally.
3. Frontend/API clients can rely on `reason_code` where authenticated staff tools need it； parents do not receive discriminating codes.
4. Webhook bind path rate-limited； excess → `RATE_LIMITED` copy.
5. Docs/UI no longer instruct `綁定 姓名` without credential.

## Tests

- Feature：enumeration suite（timing optional）； ambiguous names； rate limit.
- Snapshot／assert LINE reply strings for failure matrix.
- Regression：valid name+phone still binds.

## Rollback

- Flag `parent_binding_safe_copy=off` restores previous messages（keep reason logging）.
