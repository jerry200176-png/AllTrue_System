# Agreed-fee correction: fresh-main integration

Status: nonproduction validation preparation. The user authorized draft publication and nonproduction CI; this integration branch has no PR while prerequisites remain unmerged. Both fee correction and receipt reissue remain literal default OFF. No deployment, migration execution, activation or financial write is authorized by this record. The three real cases remain unverified; all test amounts and actors are synthetic.

## Immutable inputs and ownership

- Main `4e2763a0270937f06775ac68263cfde1bb04d9c9`, including agreed-charge display #4214 (`86ff1760`) and response labels #4218 (`4e2763a0`).
- Published source `9085e62641518c14ae4484ccbde100ea49368f01`, branch `fix/agreed-fee-handoff-20261017`.
- Draft prerequisite #4217 `45eaab46d47ad0dbd4e03062cfa830dadf752010`.
- Draft prerequisite #4132 `24565acf124ad5f5f044301d1ae7d953dfa5c64b`; its current kind-aware replay, retained history, reauthorization and disabled receipt switch are already in source. The create-table migration and InvoiceCorrectionLink are byte-identical to that owner head. Do not create a second owner migration or duplicate these fixes.

The local dependency proof merges these exact prerequisite heads into main. It is an analysis artifact, never a stacked-PR base. The local integration merge preserves main's display and labels and imports only the remaining source delta. After both prerequisites merge, each proposed slice must start fresh from then-current main, be reconciled, reviewed and checked independently.

## Integration repairs

The published source had restored receipt staff UI/announcement that current #4132 deliberately deferred. This integration restores that deferral: PaymentEntry keeps #4217 guidance, the ledger keeps the fee-only capability-gated entry, and the unavailable receipt correction controls and announcement are removed. The receipt UI is not a dependency of fee preview/apply or retained history. Its original bytes remain recoverable from source; a separate reversible patch/hash is retained in the integration evidence package.

Hosted source validation exposed a stale generated route reference before PHPUnit could execute. Run the canonical generator, never hand-edit: `bash scripts/generate-ref-api-routes.sh`, followed by `--check`. The repaired reference has 510 routes, including both role+campus-protected fee routes. This file must accompany the API routes in the same future slice.

## Validation and protected boundaries

Fresh-main full frontend build and design lint pass. The canonical route generator/check and PHP syntax checks pass using the prepared PHP 8.2.34 toolchain. Local cloud PHPUnit is prohibited and was not run; the exact integrated SHA still requires hosted backend/security validation. Source validation is evidence for the source revision only, never proof of deployment or integrated-head acceptance.

The existing synthetic API/concurrency/compatibility cases remain intact. No real account facts, invoice shape or agreement were inferred from those fixtures. Reverting code must keep correction history/schema and kind-aware consumers; a pre-discriminator receipt reader is unsafe after fee history exists. Both switches stay OFF. A future protected decision must name exact code/schema scope, nullable history semantics, credible downside, rollback and runtime verification; it cannot authorize the three unverified account corrections by implication.
