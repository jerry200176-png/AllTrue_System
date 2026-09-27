# TrueFit product and architecture brief

**Status:** Product intent and decision brief; not production activation or implementation approval  
**Updated:** 2026-09-20 (Asia/Taipei)  
**Delivery authority:** [`PROGRAM_STATUS.md`](PROGRAM_STATUS.md); code and merged PRs override stale build-book status

## Product boundary

> AllTrue manages operations. TrueFit manages how students learn.

TrueFit lets a teacher select the grade, subject and unit before an in-person lesson, teach with physical material, capture evidence from a student's paper assessment afterward, review an AI-assisted diagnosis, and approve a printable remediation pack. A later check records whether the misconception improved.

AllTrue remains the system of record for identity, teachers, students, campuses, courses and class sessions. TrueFit owns lesson preparation, evidence, diagnosis proposals, teacher decisions, remediation versions and mastery evidence. TrueFit references AllTrue IDs through scoped services/APIs; it must not duplicate master records or write attendance, enrollment, billing or general learning-record truth.

## Recommended product loop

```text
Select context → Prepare → Teach physically → Capture paper evidence
  → Extract/OCR → Teacher verifies → AI proposes diagnosis
  → Teacher confirms/edits/rejects → Generate remediation
  → Teacher approves/prints → Student practises → Verify later
```

The current code covers structured Prep → Observation → Diagnosis → Remediation → Mastery. It does **not** yet provide paper upload, OCR, question segmentation or a live external-LLM workflow. Paper evidence should enter as a new adapter before diagnosis, not replace the existing assessment or TrueFit contracts.

## Same server, different website

The desired shape is compatible with the current bounded-context design:

| Concern | Decision |
|---|---|
| Runtime | Same governed release, Laravel API and canonical database |
| Product surface | Distinct TrueFit shell; an optional `truefit.<domain>` vhost may point to the same deployment |
| Data | AllTrue master IDs are referenced, never copied into a second student/teacher database |
| Isolation | Separate TrueFit tables/services/permissions, queues, storage quotas and feature flags |
| Failure containment | AI/OCR failure must not block AllTrue attendance, scheduling or billing |

For the pilot, use the existing origin at `/#/truefit`. A separate subdomain is a different browser origin and cannot inherit the current `alltrue_session` localStorage token. The lowest-risk branded-site option is a separate teacher login using the same AllTrue identity service. Seamless navigation later requires a security-reviewed, short-lived, one-time, audience-bound exchange (or a standards-based identity flow). Never copy bearer tokens through query strings or assume a shared cookie fixes the current Bearer-token design. See [`AUTH_SUBDOMAIN_FINDINGS.md`](AUTH_SUBDOMAIN_FINDINGS.md).

Do not split TrueFit into a microservice or separate database for v0.1. That would add identity synchronization and operational failure modes without solving the product problem.

## Paper evidence and AI contract

The first bounded discovery/Plan must define these stages and failure states:

1. **Capture:** accept supported photo/PDF types; associate campus, teacher, session, student, subject and unit; malware-scan, strip unnecessary metadata, hash for duplicate detection, and preserve provenance.
2. **Extract:** OCR and layout/question segmentation create versioned derived artifacts. Store confidence per page/item; poor images and unsupported handwriting or diagrams go to `NEEDS_REVIEW`, never silent acceptance.
3. **Ground truth review:** the teacher confirms transcription, question boundaries, student answer, answer key/rubric and curriculum mapping. AI cannot be the sole source of the correct answer.
4. **Diagnosis proposal:** produce cited, schema-validated misconception hypotheses with confidence and abstention. The teacher may confirm, edit or reject; only confirmed decisions enter longitudinal state.
5. **Remediation:** generate concise concept explanation, worked examples, varied practice and a separate answer key. Preserve input, prompt, model and schema versions and the approving teacher.
6. **Print and verify:** provide A4 student/teacher variants, avoid answer leakage, and record a later retrieval check rather than treating generation or printing as learning success.

Every stage needs idempotency, retry/timeout state, manual fallback, audit history and explicit deletion/retention. External AI receives no real-student PII or exam image until Founder approval covers provider, contract/data-use terms, retention, residency, consent/notice and incident handling.

## Decisions still required before implementation

- Which subjects and evidence formats form the pilot, especially handwritten Chinese, mathematics and diagrams.
- AI/OCR provider, training opt-out, data location, cost/latency budget and outage fallback.
- Copyright/licence authority for uploaded exams, textbook-derived questions and generated variants.

These are product/security choices, not implied authorization from this brief.

### Founder decisions recorded 2026-09-20

- The supplied question bank is authoritative for correct answers and rubrics; AI may match/explain but must not invent answer truth.
- One upload job represents one student; batch multi-student splitting is out of the initial scope.
- Raw exam evidence is retained for 30 days. Confirmed structured learning records must survive raw-file expiry; exact educational-record retention remains a policy decision.
- A second teacher login on the TrueFit site is not acceptable. The proposed no-relogin handoff is specified in [`PAPER_EVIDENCE_AI_SSO_PROPOSAL.md`](PAPER_EVIDENCE_AI_SSO_PROPOSAL.md) and remains an auth/security Founder gate.
- Pilot grade, subject, format and AI/OCR provider remain undecided and must be selected by evidence, not assumed.

## Phased roadmap and success measures

1. **Operational baseline:** keep flags off until current merged slices receive runtime evidence and operational acceptance.
2. **Paper Evidence Discovery:** fixture-only workflow, representative de-identified samples, OCR/segmentation evaluation, threat/privacy review and human-review contract. Plan required; no production data.
3. **Bounded pilot:** one campus, 1–2 subjects, 2–3 teachers and a small consented cohort; async jobs, quotas, monitoring and rollback; browser print first.
4. **Validated expansion:** add formats/subjects only after quality, privacy, cost and teacher-workload evidence.

Primary outcomes are teacher correction rate/time, extraction accuracy by content type, diagnosis agreement, print usability, remediation completion and delayed mastery improvement. Upload count, generated-page count and model fluency are not success measures.

This proposed Paper Evidence track does not silently replace the currently recorded TF-S6-02 next-lesson carry-forward task; prioritization requires an explicit product decision after operational-baseline evidence.

## Evidence and reuse boundaries

- Source intent: `TrueFit_v0.1_工程施工BuildBook.docx` supplied 2026-09-20; useful for product intent, but its delivery statuses are superseded by [`PROGRAM_STATUS.md`](PROGRAM_STATUS.md).
- Repository evidence: current TrueFit controllers/services and auth findings show structured learning artifacts and origin-scoped Bearer persistence, but no paper/OCR adapter.
- Official guidance consulted 2026-09-20: [NIST AI RMF Generative AI Profile](https://www.nist.gov/publications/artificial-intelligence-risk-management-framework-generative-artificial-intelligence) for lifecycle risk measurement and evaluation; [1EdTech LTI](https://www.1edtech.org/standards/lti) was considered but rejected for this internal same-platform v0.1 because it solves cross-product LMS/tool interoperability, not the immediate evidence pipeline.
- Open-source patterns inspected at pinned revisions: [Moodle](https://github.com/moodle/moodle/tree/e68a1418bea512dd5992c29eb9e36570a3844e94) (GPL-3.0) for mature assessment/question boundaries, and [Docling](https://github.com/docling-project/docling/tree/890dd42d017497c955a56a1d2cfc3f0af5bc2aa9) (MIT) for document conversion pipelines and tests. They are architectural references only; no code was copied and real handwritten-exam suitability remains unverified.
- Live authenticated product comparison: **NOT_EXERCISED**; no authorized competitor account was needed or used.
