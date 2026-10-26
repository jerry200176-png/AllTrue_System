## 2026-10-27 — fix(ci): inspect reusable workflow runner inputs faithfully
<!-- release-notes: silent_ship=reusable-runner-parser -->
<!-- silent-reason: Internal CI parsing repair; runner trust declarations and product UI/API remain unchanged. -->
- Preserve quoted comment characters and decoded key names, inspect reusable runner inputs, and fail closed on duplicate or uninspectable job/input structures.
- Keep legal indentless steps/needs sequences. Reuse the independently reviewed parser implementation and add no runner, reusable workflow or trusted SHA.
- The separate #4426 dependency pin and supply-chain Hold remain in force; this parser change does not approve the action upgrade.
