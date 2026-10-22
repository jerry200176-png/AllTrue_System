## 2026-10-21 — ci(security): weekly credential expiry check
<!-- release-notes: silent_ship=silent-2026-10-21-credential-expiry-reminder -->
<!-- silent-reason: ops workflow change; no product UI change -->

`secret-rotation-reminder.yml` gains a weekly job that reads `docs/ops/credential-expiry.yml` (names, owners, `expires_on`, where to rotate; no values). It uses `lead_days` plus a six-day polling buffer: with `lead_days: 14`, an on-time weekly run first alerts 14–20 UTC calendar days before expiry. GitHub schedule delays or newly recorded dates can shorten that window. Registry dates are unchanged; the registry's “14 days before” describes the minimum target, not an exact send date. Undated entries are listed in the run summary without issue writes. Sunrise Cafe needs the same registry (separate PR).

Manual runs select `mode=expiry` (default) or `mode=quarterly`; only the selected job runs. The quarterly schedule and reminder body stay unchanged. Expiry runs serialize and deduplicate exact credential/date titles across all issue states and pages, ignoring PRs. Failed registry parsing or issue lookup stops writes.

The expiry job installs `scripts/requirements-credential-expiry.txt` in a fresh venv, independent of runner packages. No pinned Python YAML dependency existed to reuse; the frontend lock's JavaScript parser would require a separate runtime bridge. PyYAML 6.0.3 is the current [PyPI release](https://pypi.org/project/PyYAML/6.0.3/); [upstream](https://github.com/yaml/pyyaml) uses the [MIT license](https://github.com/yaml/pyyaml/blob/6.0.3/LICENSE), and its [6.0.3 changelog](https://github.com/yaml/pyyaml/blob/6.0.3/CHANGES) records Python 3.14 support (2025-09-25).

T3/control-plane merge remains held for the Founder decision. Rollback: revert this PR before further dispatch; already created reminder issues remain for owner triage. No credential creation/rotation or production operation is authorized.
