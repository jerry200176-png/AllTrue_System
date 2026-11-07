## 2026-11-07 — feat(students): school input offers this campus's existing names (in-app #517, GitHub #5057)
<!-- release-notes: staff_update=staff-2026-11-07-school-name-combobox -->
- `SchoolNameInput` lists the campus's existing school spellings first (`GET /school-names?campus_id=`), so new variants (再興/在興) stop appearing. Director-only `GET /school-merge/suggestions` groups likely same-school names (`SchoolNameSuggester`: width, 台/臺, trailing 高一/國二 grade, one-character difference). Read-only, no migration; the merge/undo tool follows in a separate PR.
