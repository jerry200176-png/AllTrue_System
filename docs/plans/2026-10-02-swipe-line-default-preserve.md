# Preserve existing swipe LINE delivery before activation

## Goal

Correct PR #3437's default-off swipe settings before its production activation. Existing campuses with no saved notification setting must continue receiving arrival and departure LINE messages through the server photo path; directors can still turn either kind off explicitly.

## Steps

1. Keep the merged #3437 deployment canceled while verifying the reader-to-API contract.
2. Change only the new swipe defaults, update focused settings/photo tests and staff-facing release notes.
3. Verify the exact branch and required CI; inspect the deployed device path before claiming old delivery is fully preserved.
4. Publish a dedicated R3/T3 PR with rollback and activation evidence. A new production activation remains behind the Founder environment gate.

## Stop points

- If a reader calls only `swipe-rfid` and not `swipe-photo`, changing settings defaults alone cannot restore its LINE text because #3437 made `LineIDs` empty. Do not claim complete continuity without verifying that contract.
- No production DB writes, SSH, manual migration, or bypass of the environment reviewer.
