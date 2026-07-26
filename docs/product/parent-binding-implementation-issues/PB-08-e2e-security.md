# PB-08 — E2E & security verification

| Field | Value |
|-------|-------|
| Phase | 2–3 |
| Risk class | T2 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| GitHub status | see issue after creation |
| Dependencies | PB-05, PB-06, PB-07 |
| Blocks | PB-09 |

## Scope

- Execute test matrix from task §十二（unit/feature/integration/e2e/security）as automated CI jobs + manual checklist.
- Mobile 390×844 and desktop smoke scripts／Playwright if repo already has patterns.
- Security：token brute force、replay、IDOR、cross-campus、log PII scan、ambiguous name、malformed input.
- Production smoke checklist after merge（health、director issue code、parent consume、revoke）.

## Non-scope

- Implementing new product features beyond gaps found（file follow-up issues）.
- Load testing entire API surface.

## Acceptance criteria

1. CI green on full parent-binding test group.
2. Manual checklist signed in PR with evidence links（Actions URLs）.
3. No raw phone/token in sampled logs.
4. Revoke removes portal access within one request cycle.

## Tests

- Own content is the matrix； must add any missing automated cases discovered.

## Rollback

- N/A（verification issue）； if fail → block PB-09 and disable pairing flag.
