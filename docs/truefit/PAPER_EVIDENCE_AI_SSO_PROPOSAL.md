# TrueFit paper evidence, AI and no-relogin proposal

**Status:** DECISION PROPOSAL — planning only; no auth, schema, vendor, PII or production authorization  
**Updated:** 2026-09-20 (Asia/Taipei)  
**Depends on:** [`PRODUCT_ARCHITECTURE_BRIEF.md`](PRODUCT_ARCHITECTURE_BRIEF.md), [`PROGRAM_STATUS.md`](PROGRAM_STATUS.md), [`AUTH_SUBDOMAIN_FINDINGS.md`](AUTH_SUBDOMAIN_FINDINGS.md)

## 1. Decision and acceptance target

Design the smallest safe TrueFit pilot in which a teacher can enter a separate TrueFit website without typing credentials again, upload one student's paper assessment, bind it to the supplied question bank, review extraction and diagnosis, and approve a printable remediation pack. Raw media expires after 30 days while confirmed learning history remains useful.

This proposal does not select a production vendor or pilot cohort and does not authorize real-student data, external AI, DNS, feature flags, auth changes or migrations.

## 2. Fixed product decisions

| Topic | Decision |
|---|---|
| Correct-answer authority | Supplied question bank and its versioned assessment snapshot |
| Upload unit | One student per upload job; one or more pages allowed |
| Raw evidence | Automatic purge 30 days after successful upload |
| Learning history | Must not disappear when raw media expires |
| TrueFit authentication | No second credential entry |
| Undecided | Pilot grade/subject/format, OCR and AI providers |

## 3. Proposed teacher journey

```text
AllTrue teacher session
  → launch TrueFit (short-lived one-time code)
  → select session and one student
  → select assessment/question-bank version
  → upload pages and confirm page quality
  → OCR + question matching
  → review low-confidence text/answers and unmatched questions
  → submit verified evidence
  → AI proposes misconceptions with question evidence
  → teacher confirms/edits/rejects
  → AI drafts remediation from approved concepts + bank constraints
  → teacher edits/approves and prints student + answer-key variants
  → later retrieval check updates mastery
```

The primary action is always the next unresolved review, not “generate again.” Manual entry must remain available when OCR or AI is unavailable.

## 4. Question-bank binding

The repository already has `question_banks`, `question_bank_items`, versioned `assessment_question_snapshots`, attempts and answers. Paper ingestion should reference those contracts rather than create a second answer store.

Recommended matching order:

1. Stable printed question identifier or QR/barcode → exact snapshot match.
2. Assessment version + page/position → deterministic match.
3. Text/image similarity → candidate list only, never automatic truth.
4. Teacher selects the correct bank item or marks it unmatched.

For future AllTrue-generated paper assessments, print a human-readable assessment code and question ID beside each item. This sharply reduces OCR ambiguity. For external worksheets without IDs, the teacher must confirm every inferred match before diagnosis.

The snapshot—not a later edited bank item—provides the correct answer, rubric, points and curriculum tags for that attempt. If no authoritative snapshot/key exists, the item remains `NEEDS_ANSWER_KEY`; AI analysis stops for that item.

## 5. Data lifecycle: 30 days without losing the student record

Separate disposable evidence from durable educational facts:

| Tier | Examples | Proposed retention | After expiry |
|---|---|---|---|
| A — source media | Original photo/PDF, page crops, thumbnails, vendor request/response content | 30 days from upload | Hard-delete object and content payload; retain purge receipt/hash only |
| B — working extraction | Raw OCR tokens, bounding boxes, temporary match candidates | 30 days; earlier after teacher confirmation is allowed | Delete; confirmed projection remains |
| C — confirmed learning record | Assessment snapshot ID, verified student answer, score, misconception decision, teacher edits, remediation revision, mastery result | Not tied to 30-day purge | Retain under the future student-learning-record policy |
| D — audit metadata | Actor, timestamps, model/prompt/schema versions, confidence summary, hashes, consent and purge status; no raw page content | Proposed 365 days minimum | Policy-governed deletion/anonymization |
| E — printable output | Approved structured pack and answer-key manifest; generated PDF cache | Structured revision follows Tier C; PDF cache 30 days | Regenerate PDF from approved revision if still authorized |

The purge worker must select only Tier A/B rows by explicit `purge_after`, be idempotent, emit a purge receipt, and never cascade into Tier C. Dashboard states should distinguish `SOURCE_AVAILABLE`, `SOURCE_EXPIRED`, and `LEARNING_RECORD_RETAINED`.

Still requiring Founder/privacy decision: Tier C retention after a student leaves, deletion/export requests, legal hold, backups, and whether anonymized aggregate mastery may outlive the identifiable record. Proposed starting discussion point: active enrollment plus 365 days, not an approved policy.

## 6. No-relogin architecture

### Recommended v1: AllTrue-issued one-time launch code

This is smaller than migrating the whole staff app to cookie/OIDC auth and still prevents a second login:

1. A direct visit to `truefit.<domain>` redirects to an allowlisted AllTrue launch page.
2. The AllTrue page reads its existing origin-scoped session and calls an authenticated launch endpoint.
3. Server issues an opaque, random, single-use code with hashed storage, teacher/campus binding, exact TrueFit audience/redirect URI and 30–60 second expiry.
4. Browser returns with the code—not an access token—to the TrueFit callback.
5. TrueFit exchanges it server-to-server, receives a new origin-specific staff token/session, then invalidates the code atomically.
6. Replay, wrong audience, wrong redirect, expiry or campus mismatch fails closed and returns through AllTrue launch; valid AllTrue session means no credential prompt.

Mandatory controls: exact redirect allowlist, single-use transaction, rate limits, audit events, CSRF/state binding, no bearer token in URL, no open redirects, token rotation/revocation compatibility, logout behavior, and integration tests for replay and cross-campus attempts. OAuth Security BCP recommends authorization code flow, PKCE and exact redirect matching; the implementation Plan must either adopt those controls or justify an equivalent internal protocol.

### Future option: standards-based OIDC

If more products or third parties need SSO, promote AllTrue identity into an OpenID Provider or use a managed provider. Do not build a partial OIDC server during the TrueFit pilot. The one-time code contract should be replaceable so TrueFit does not depend on localStorage copying.

This is a T3 identity/auth change. It requires a dedicated Plan, threat model, independent security review, rollback to same-origin `/#/truefit`, and Founder GO before implementation or activation.

## 7. OCR provider proposal

Do not select from marketing claims. Run a fixture-only bake-off using the same de-identified pages and a provider-neutral adapter.

| Candidate | Current documented fit | Main gap / disposition |
|---|---|---|
| Google Document AI Enterprise OCR | Officially lists Traditional Chinese and handwriting support, quality analysis, sync/async processing and regional choices | **Primary bake-off candidate**; must verify Taiwanese handwriting, mathematics, diagrams, contractual retention and cost |
| Azure Document Intelligence | Strong layout/confidence output and printed Traditional Chinese; official handwritten list currently names Simplified Chinese, not Traditional | Secondary candidate for printed/layout tests; do not assume Traditional Chinese handwriting support |
| Amazon Textract | Mature async document flow | Official language list does not include Chinese; reject for initial Taiwanese exam OCR |
| Local/open-source OCR | Better data-control potential and no external page transfer | Benchmark only unless accuracy, maintenance, compute and Traditional Chinese handwriting evidence meet the same gates |

Provider adapter output must normalize pages, regions, text, confidence, handwriting indicator, table/selection marks, provider/model version, latency and cost. No provider-specific response may become the domain record.

### OCR bake-off dataset

Before choosing grade or subject, assemble de-identified/fixture samples across difficulty dimensions:

- printed Traditional Chinese with multiple-choice marks;
- short handwritten Traditional Chinese answers;
- Arabic numerals and Latin symbols;
- mathematical notation, fractions, exponents, equations and geometry diagrams;
- clean scan versus phone photo, skew, shadow, blur and multi-page PDF.

This matrix selects the pilot. Start with the cell that has adequate educational value and passes thresholds—not automatically the easiest grade. Likely first candidate is printed or lightly handwritten single-subject material with stable question IDs; full free-form mathematics should not be assumed.

## 8. AI diagnosis/generation provider proposal

Keep OCR, domain diagnosis and content generation as separate adapters so one vendor does not own the full pipeline.

The AI receives only teacher-verified structured evidence where possible: question snapshot, verified answer, score/rubric, unit tags and minimal pseudonymous learner context. It should not need the original page after review. Required output is schema-constrained with evidence question IDs, misconception candidates, confidence/abstention and remediation objectives.

Evaluate at least two eligible models using the same golden cases. Provider eligibility gates:

- contract prohibits training on submitted data by default;
- documented retention/deletion and acceptable processing region;
- image/file behavior is separately understood even if the initial call is text-only;
- version pinning or change notification, rate limits, auditability and outage behavior;
- acceptable Traditional Chinese educational quality and cost;
- no tool/action permission beyond returning a proposal.

OpenAI is an evaluation candidate only after project data controls are verified; its official documentation describes endpoint-specific retention controls and special image/file handling, so “API data is never retained” must not be assumed. Google/Vertex and Azure-hosted model options require the same contractual review. No production recommendation is possible before fixture eval and procurement/privacy evidence.

## 9. States and failure recovery

```text
DRAFT → UPLOADED → SCANNING → EXTRACTED → TEACHER_REVIEW
      → VERIFIED → DIAGNOSIS_PROPOSED → TEACHER_CONFIRMED
      → PACK_DRAFTED → PACK_APPROVED → PRINTED → MASTERY_PENDING → VERIFIED_OUTCOME
```

Side states: `UPLOAD_REJECTED`, `OCR_FAILED`, `NEEDS_REVIEW`, `NEEDS_ANSWER_KEY`, `AI_UNAVAILABLE`, `PURGE_PENDING`, `SOURCE_EXPIRED`, `CANCELLED`.

Retries use the upload hash + stage + provider/model version as an idempotency key. Retry never creates a second confirmed attempt. Teachers can correct extraction, replace a page, select a bank item, enter evidence manually, retry generation or continue without AI. Every transition records actor and source version.

## 10. Security, privacy and operations

- Campus and teacher authorization is enforced server-side on every upload, job, result and print request.
- Use private object storage paths, short-lived signed access, encryption, MIME/content validation, malware scan, size/page limits and EXIF stripping.
- Queue OCR/AI separately from AllTrue operational work; set concurrency, timeout, circuit breaker and cost quotas so TrueFit cannot starve attendance/billing.
- Backups must respect deletion policy; document whether deleted raw files age out of encrypted backups rather than promising immediate physical erasure.
- Observability: stage duration/error, provider/model, confidence bands, teacher correction rate, retry, cost, purge lag and orphan-object count—never page content or student names in logs.
- Copyright authority is recorded for each assessment source. Generated variants must not reproduce unauthorized textbook content.

## 11. Pilot selection scorecard

Score each candidate grade × subject × format from 1–5:

| Dimension | Weight |
|---|---:|
| Question-bank coverage and stable IDs | 25% |
| OCR accuracy on representative evidence | 20% |
| Teacher review time saved | 15% |
| Diagnostic/remediation educational value | 15% |
| Copyright/data permission readiness | 10% |
| Print usability | 5% |
| Expected cost/latency | 5% |
| Operational failure containment | 5% |

Minimum gates regardless of weighted score: no unresolved data-rights issue; 100% teacher confirmation before durable diagnosis; zero cross-student/cross-campus leakage; question matching precision high enough that uncertain matches abstain; raw purge demonstrated; manual fallback works.

## 12. Delivery roadmap

### Phase 0 — decision/evaluation package

- Inventory question-bank coverage and exam-print identifiers.
- Create 50–100 fixture/de-identified pages across the format matrix with ground-truth transcription and item mapping.
- Execute OCR and AI golden-case bake-offs; record quality, latency and cost.
- Complete retention, copyright, consent, vendor terms and SSO threat-model decisions.
- Output: selected pilot cell, vendor decision record, data-processing inventory and separate implementation Plans.

### Phase 1 — fixture-only vertical slice

- Provider-neutral artifact and job contracts, question matching, review UI and manual fallback.
- One-time launch-code flow in non-production with replay/campus/redirect tests.
- 30-day lifecycle simulated with short test TTL; purge receipt and non-cascade proof.
- AI proposal and printable pack from fixtures only.

### Phase 2 — bounded consented pilot

- One campus, one selected subject/format, named teachers, quotas and kill switches.
- Production activation requires Founder GO, security/privacy review, rollback and operational owner.
- Weekly quality/cost review; stop if teacher correction burden or leakage/error threshold fails.

### Phase 3 — evidence-led expansion

- Add grade/subject/format cells independently; do not generalize a printed-text result to handwriting or mathematics.
- Consider direct TrueFit OIDC only if multiple product surfaces justify the identity-platform cost.

## 13. Acceptance criteria before real-student pilot

- Teacher reaches TrueFit from a valid AllTrue session without credential re-entry; replay and malicious redirects fail.
- Each upload belongs to exactly one student, session, campus and assessment snapshot.
- Correct answers always resolve from a versioned bank snapshot; unresolved items cannot be diagnosed automatically.
- Teacher can see source region, OCR confidence and bank match, then edit/reject before confirmation.
- Raw objects and OCR payloads purge at 30 days while confirmed Tier C records remain readable and auditable.
- AI outputs cite question IDs, validate against schema, abstain safely and never write final learning truth without teacher action.
- Student and teacher print variants are A4-readable and do not leak answers.
- Provider outage, timeout and quota exhaustion preserve manual operation and do not affect AllTrue core queues.
- Cost, latency, correction rate, false-match rate, purge lag and delayed mastery are measurable.
- Rollback disables upload/AI/SSO entry without deleting confirmed records or affecting AllTrue operations.

## 14. Evidence register

- **Locally verified:** existing question-bank/snapshot/attempt schema, TrueFit structured continuum, and origin-scoped `alltrue_session` Bearer storage.
- **Official, accessed 2026-09-20:** [Google Document AI processor list](https://docs.cloud.google.com/document-ai/docs/processors-list), [Azure Document Intelligence language support](https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/language-support/ocr?view=doc-intel-4.0.0), [Amazon Textract best practices](https://docs.aws.amazon.com/textract/latest/dg/textract-best-practices.html), [OAuth 2.0 Security BCP RFC 9700](https://www.rfc-editor.org/info/rfc9700/), [OpenID Connect specifications](https://openid.net/developers/specs/), and [OpenAI API data controls](https://platform.openai.com/docs/models/default-usage-policies-by-endpoint).
- **Open-source patterns carried forward:** Moodle assessment/question boundaries at `e68a1418bea512dd5992c29eb9e36570a3844e94` (GPL-3.0) and Docling document pipeline/tests at `890dd42d017497c955a56a1d2cfc3f0af5bc2aa9` (MIT); no code copied.
- **Live authenticated comparison:** NOT_EXERCISED; no private competitor account was authorized or needed.
- **Unknown:** real Taiwanese exam accuracy, costs and contractual fit remain UNVERIFIED until Phase 0.
