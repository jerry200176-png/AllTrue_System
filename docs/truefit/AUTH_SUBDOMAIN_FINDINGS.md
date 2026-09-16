# TrueFit auth and subdomain findings (Slice 0)

**Status:** Investigation only — no auth, cookie, token, or DNS changes made.

## How staff authentication works today

| Layer | Mechanism |
|---|---|
| API identity | `Authorization: Bearer <token>` resolved by `AttachAuthUser` against `auth_tokens` |
| Client persistence | `localStorage` key **`alltrue_session`** — JSON `{ access_token, user, ... }` |
| Login flow | `frontend/src/supabase.js` → `POST /api/v1/auth/login` → writes `alltrue_session` |
| Laravel session cookie | Also named `alltrue_session` in `config/session.php`, but **staff SPA API calls use Bearer from localStorage**, not this cookie |

Parent portal uses a separate `parent_portal_token` in localStorage. TrueFit Slice 0 reuses the **staff** path only.

## Subdomain SSO: current behavior

Web storage is **origin-scoped** (scheme + host + port).

| Origin | Shares `alltrue_session` localStorage? |
|---|---|
| `https://daan.lifenet.com.tw` | yes (same origin) |
| `https://truefit.daan.lifenet.com.tw` | **no** — different host |
| `https://daan.lifenet.com.tw/#/truefit` | yes — same origin, hash route only |

Therefore **`truefit.<domain>` does not inherit staff Bearer tokens** from the main admin origin today. A teacher opening the subdomain would see the login screen with an empty session unless they sign in again (a new token would be issued and stored under the subdomain origin).

## Cookie / SameSite note

Production `.env.production.example` documents:

```text
SESSION_DOMAIN=   # empty = current host only; subdomain sharing would use .your-domain.com
```

Even if `SESSION_DOMAIN=.example.com` were set, that affects the **Laravel session cookie**, not the staff Bearer token in localStorage. Seamless subdomain SSO would require a **Founder-approved auth design change** (for example cookie-based staff session, token handoff page, or OAuth).

SameSite defaults to `lax` on the Laravel cookie (`config/session.php`). This is irrelevant to current staff API auth, which is Bearer-header based.

## Recommendation for v0.1 pilot

**Use `<existing-origin>/#/truefit`** for the pilot. This gives:

- Single sign-on via existing `alltrue_session` localStorage
- No cross-domain cookie policy work
- No auth hot-zone edits

Document `truefit.<domain>` as a **future optional entry point** that requires either:

1. Separate teacher login on the subdomain, or
2. An approved token/cookie bridge (out of Slice 0 scope)

## Minimal safe change if subdomain SSO is required later

Founder decision required. Options (not implemented):

| Option | Blast radius |
|---|---|
| A. Stay on hash route only | None — recommended for v0.1 |
| B. Post-login redirect that copies Bearer into subdomain via one-time exchange endpoint | New auth endpoint + short-lived code; medium |
| C. Move staff auth to httpOnly cookie with `SESSION_DOMAIN=.domain` | High — touches AttachAuthUser and SPA client |

Do not pursue B or C without explicit Founder GO and security review.
