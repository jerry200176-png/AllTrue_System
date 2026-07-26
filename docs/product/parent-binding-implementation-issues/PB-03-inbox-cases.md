# PB-03 — Action Inbox high-signal binding cases

| Field | Value |
|-------|-------|
| Phase | 1 |
| Risk class | T2 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| GitHub status | see issue after creation |
| Dependencies | PB-00, PB-02 |
| Blocks | PB-06 (SLA reuse) |

## Scope

- Extend Action Inbox（or parallel case source）with binding case types from Architecture §6.9.
- Dedupe keys、cooldown、SLA、deep link、resolve conditions.
- Staff notification text without full phone.
- Only high-signal：`CONTACT_PHONE_MISSING` with name confidence； repeated failures； later pending requests.

## Non-scope

- Creating inbox items on every typo.
- Using Notification table as sole business truth for case state.
- Pairing credential UI.

## Acceptance criteria

1. Typo-only failures do not create cases.
2. Same student missing contact → single open case（dedupe）.
3. Resolve when contact filled or relationship active（Phase 2+）.
4. Campus scoped； no cross-campus leak.
5. Contract version compatibility with existing Action Inbox clients documented.

## Tests

- Feature：dedupe、cooldown、campus scope、no phone in payload.
- Regression：existing leave inbox cases unchanged.

## Rollback

- Flag `parent_binding_inbox_v1=off`； stop creating new cases； open cases remain readable.
