# Parent Binding Reason Codes (PB-00)

Machine `reason_code` ≠ parent `message`. Never parse Chinese copy for state.

| outcome | Meaning |
|---------|---------|
| `success` | New bind / portal session |
| `failure` | Rejected |
| `noop` | Already bound (not a new success) |

PB-00 codes: `STUDENT_NOT_FOUND` · `CONTACT_PHONE_MISSING` · `PHONE_MISMATCH` · `AMBIGUOUS_MATCH` · `CAMPUS_MISMATCH` · `ALREADY_BOUND` · `INVALID_INPUT` · `AUTHORIZATION_DENIED` · `INTERNAL_ERROR`.

Storage: append-only `parent_binding_attempts` (not auth truth). No raw phone/name/LINE id/token. Flag: `PARENT_BINDING_OBSERVABILITY`.

Ops: `php artisan parent-binding:report --days=7 --format=json` · `php artisan parent-binding:report --missing-contact --format=json`
