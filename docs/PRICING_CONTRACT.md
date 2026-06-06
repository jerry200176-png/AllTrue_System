---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-06-06
---

# Pricing Contract (Course Management)

Date: 2026-04-10

## Purpose

Prevent fee regressions where per-session price is accidentally treated as hourly price.

## Rule

- `price_per_session` / `rate_per_30min` is **per-session fee** by default.
- Only when request explicitly passes `rate_unit=hour`, system uses hourly pricing.
- Do not auto-infer hourly pricing from `day_time_slots` or `duration_minutes`.

## Formula

- Session pricing: `Charge = per_session_fee * SessionCount`
- Hour pricing (explicit only): `Charge = hourly_rate * total_session_hours`

## Regression checklist

1. Create enrollment **without** `rate_unit`; verify `rate_unit=session`.
2. Verify total fee equals `per_session_fee * session_count`.
3. Create enrollment **with** `rate_unit=hour`; verify proportional hour-based charge.
