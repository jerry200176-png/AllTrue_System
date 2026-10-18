# Isolated payment race test support

silent_ship: true

Extract the synthetic database allowlist and bounded two-process race helpers
from #4248 before landing the billing lock repair. Source: `265d4717b148a8761820a4275043e403b17977bc`.
This slice changes test infrastructure only; it does not change billing behavior,
production data, schema, feature flags, or deployment permissions.

The existing source branch retains the void/confirm regression suites. They will
land with the remaining implementation slices after this prerequisite merges.
Allowlist unit tests reject production, empty, illegal and newline-suffixed schemas.
