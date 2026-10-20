# Bounded read-only mount policy diagnostic
<!-- release-notes: silent_ship=silent-2026-10-20-pop-mount-policy-diagnostic -->
<!-- silent-reason: Internal read-only diagnostic preparation; protected approval required before use. -->

The existing protected mount workflow can select a separate `probe-policy` branch
that reuses its environment credentials and returns encrypted evidence. This branch
skips every mount step; it does not grant sudo access or change financial data.
It requires a separate explicit approval, expires at the fixed diagnostic cutoff,
and uses one time-bounded SSH attempt with no fallback.

silent_ship: internal diagnostic preparation; no runtime activation or case acceptance claimed.
