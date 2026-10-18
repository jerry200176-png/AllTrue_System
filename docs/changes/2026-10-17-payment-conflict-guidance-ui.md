## 2026-10-17 — fix(billing): preserve payment conflict input and guidance
<!-- release-notes: silent_ship=silent-2026-10-17-payment-conflict-guidance-ui -->
<!-- silent-reason: Behavior is not production-verified yet; approved director announcement is prepared separately in held Draft #4265 and publishes only after deployed verification per GUIDE_STAFF_UPDATES.md. -->

- Keep the payment conflict form and entered amount visible, refresh both owners, show authoritative outstanding including zero and announce the refusal as an alert.
- Existing confirmation, rejection and void guidance does not reprice bills or post money; #4261 approved the exact director notice, whose publication remains conditional on actual verification.
