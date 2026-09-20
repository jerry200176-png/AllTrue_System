# TrueFit local paper fixture MVP evidence

**Status:** local synthetic validation only
**Scope:** no backend write, schema, auth/SSO, DNS, production flag, external OCR/AI, fee or real-student data

## Demo

Run the existing frontend locally and open `#/truefit/paper-fixture`, or use the **合成資料驗證** action on the TrueFit workspace. The action and fixture page are compiled behind `import.meta.env.DEV`; production builds fall back to the existing workspace.

The demo contains 12 AllTrue-authored synthetic pages for one synthetic student. It exercises fixture OCR success/failure, teacher editing, missing-answer-key stop, unique confirmation, fixture diagnosis/remediation, approved revision rendering, page replacement and simulated 30-day raw expiry. It is not an OCR accuracy or teacher-time study.

## Contract evidence

| Gate | Evidence |
|---|---|
| Retry does not duplicate confirmation | unchanged job + attempt + student + input revision + processor key reuses one run; confirmation object/audit entry remains unique |
| Student/page/answer changes do not reuse stale work | student participates in key; replacement and correction increment input revision and clear downstream confirmation/draft/approval |
| Missing answer snapshot stops | synthetic item 8105 produces `NEEDS_ANSWER_KEY` until its authored fixture snapshot is supplied |
| Teacher confirmation required | draft generation returns null without `confirmed` evidence |
| 30-day purge isolation | `expireRaw` removes raw OCR availability while retaining the confirmed record |
| Approved PDF is reproducible | layout may change while approved revision and content remain byte-equivalent objects |
| OCR/AI failure fallback | `OCR_FAILED` permits manual verification; `AI_UNAVAILABLE` permits a manual diagnostic/pack draft and continuation |

Automated proof: `frontend/src/components/__tests__/TrueFitPaperFixture.test.js`. Shell routing remains covered by `TrueFitShellContract.test.js` in the same directory.

## Third-party question sources

- **TestGo:** public material confirms a question-bank product and versioned API surface, but the authorized account export/API fields and licence grant for AllTrue reuse are not verified.
- **UPAD12:** public material confirms question-bank and assessment features; public terms prohibit unauthorized copying/download/redistribution. No export contract was exercised.
- **Result:** `NEEDS_VENDOR_EVIDENCE`. Repository fields (`source_question_key`, `source_version`, `license_ref`) are intake capacity, not proof that content may be obtained.

Required vendor evidence is one authorized sample/export specification showing stable question ID, answer, rubric/points, assessment/data version, format and written reuse terms. No credential, private endpoint or vendor content was used in this MVP.

## Pilot candidate

Evidence supports one bounded candidate for the next evaluation: **upper-primary arithmetic, printed AllTrue-authored sheets with stable question IDs, short numeric answers, one student per 10–12 page job**. It exercises snapshot matching, wrong-answer diagnosis and A4 remediation without assuming Traditional Chinese handwriting or free-form mathematics OCR.

Minimum unresolved items are vendor export/licence evidence, final Tier C retention after enrolment, fixture-evaluation thresholds, and approval to evaluate named OCR/AI providers. Formal SSO remains a separate T3 implementation Plan.
