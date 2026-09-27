# TrueFit local paper fixture MVP evidence

**Status:** local synthetic validation only
**Scope:** no backend write, schema, auth/SSO, DNS, production flag, external OCR/AI, fee or real-student data

## Demo

Run the existing frontend locally and open `#/truefit/paper-fixture`, or use the **合成資料驗證** action on the TrueFit workspace. The action and route are render-gated by `import.meta.env.DEV`; production builds fall back to the existing workspace. The authored synthetic fixture module remains in the production bundle, so this is a display/route boundary rather than a claim that fixture code is absent from compiled assets.

The demo contains 12 AllTrue-authored synthetic **page objects** for one synthetic student; they are not physical OCR images. The operator view defaults to a 1–2 page short-paper sample while retaining all 12 objects for multi-page state checks. It exercises fixture OCR success/failure, teacher editing, missing-answer-key stop, unique confirmation, separate draft review/return/approval, approved revision rendering, page replacement and simulated 30-day raw expiry. It is not an OCR accuracy or teacher-time study.

## Contract evidence

| Gate | Evidence |
|---|---|
| Retry does not duplicate confirmation | unchanged job + attempt + student + input revision + processor key reuses one run; confirmation object/audit entry remains unique |
| Student/page/answer changes do not reuse stale work | student participates in key; replacement and correction increment input revision and clear downstream confirmation/draft/approval |
| Missing answer snapshot stops | synthetic item 8105 produces `NEEDS_ANSWER_KEY` until its authored fixture snapshot is supplied |
| Teacher confirmation required | draft generation returns null without `confirmed` evidence |
| 30-day purge isolation | `expireRaw` removes raw OCR availability while retaining the confirmed record |
| Approved PDF is reproducible | student and teacher A4 PDFs are generated from the same immutable approved revision; reflow changes only layout |
| OCR/AI failure fallback | `OCR_FAILED` permits manual verification; `AI_UNAVAILABLE` permits a manual diagnostic/pack draft and continuation |

## Evidence layers

| Layer | Result | Evidence |
|---|---|---|
| State model | VERIFIED | `TrueFitPaperFixture.test.js`: idempotent reconfirmation, answer/page invalidation, draft revision/return, approval, expiry and manual fallback |
| UI operation | VERIFIED | Vue Test Utils mounts the real SFC; Playwright clicks the real controls and checks the resulting DOM in `truefit-fixture-print.spec.js` |
| Content reproduction | VERIFIED | student/teacher output and reflow retain `pack-r14.1`; draft content change creates a new content revision and invalidates approval |
| PDF print | VERIFIED LOCALLY | Chromium generated A4 PDFs (595×842 pt): one student page and two teacher pages; print-media DOM checks cover answer privacy and item overflow, and every rendered PDF page was inspected for readable Chinese, intact exercise blocks and answer space |
| OCR/AI evaluation | NOT EXECUTED | all OCR/AI results are fixtures; no accuracy, latency, cost or time-saving claim is supported |

Artifacts are under `docs/truefit/evidence/fixture-print-acceptance/`: the student PDF, teacher PDF, approved-workflow screenshot and teacher-preview screenshot. The student version omits answer keys; the teacher version contains answers and explanations. Both identify the same approved revision.

Automated proof: `frontend/src/components/__tests__/TrueFitPaperFixture.test.js` and `frontend/e2e/truefit-fixture-print.spec.js`. Shell routing remains covered by `TrueFitShellContract.test.js`. Running the same Playwright spec with `TRUEFIT_PRODUCTION_BOUNDARY=1` builds and browses the production-mode mount, verifies that `#/truefit/paper-fixture` falls back to the workspace, and confirms the fixture page/action are absent from the DOM.

## Third-party question sources

- **TestGo:** public material confirms a question-bank product and versioned API surface, but the authorized account export/API fields and licence grant for AllTrue reuse are not verified.
- **UPAD12:** public material confirms question-bank and assessment features; public terms prohibit unauthorized copying/download/redistribution. No export contract was exercised.
- **Result:** `NEEDS_VENDOR_EVIDENCE`. Repository fields (`source_question_key`, `source_version`, `license_ref`) are intake capacity, not proof that content may be obtained.

Required vendor evidence is one authorized sample/export specification showing stable question ID, answer, rubric/points, assessment/data version, format and written reuse terms. No credential, private endpoint or vendor content was used in this MVP.

## Pilot candidate

Evidence supports one bounded candidate for the next evaluation: **upper-primary arithmetic, printed AllTrue-authored sheets with stable question IDs, short numeric answers, one student per 10–12 page job**. It exercises snapshot matching, wrong-answer diagnosis and A4 remediation without assuming Traditional Chinese handwriting or free-form mathematics OCR.

Minimum unresolved items are vendor export/licence evidence, final Tier C retention after enrolment, fixture-evaluation thresholds, and approval to evaluate named OCR/AI providers. Formal SSO remains a separate T3 implementation Plan.
