# Learning assessment question bank research record

**Date:** 2026-08-21 (Asia/Taipei)
**Decision:** add a staff-managed, campus-scoped question bank as the next
bounded context beside the existing assessment/result ledger.
**Scope:** question authoring, knowledge tags, difficulty, CSV intake,
human review, immutable versions, and audit history. Student online attempts,
automatic grading, parent display, and AI-generated production content remain
later slices.

## Evidence reviewed

Official product documentation was reviewed on 2026-08-21:

- [Moodle Question bank](https://docs.moodle.org/405/en/Question_bank):
  reusable questions are organized by category, searchable, have draft/ready
  status, history/version views, peer comments, and usage-aware hiding rather
  than destructive deletion.
- [Open edX Content Libraries](https://docs.openedx.org/en/latest/educators/concepts/instructional_design/content_libraries.html):
  content is authored in a reusable library, tagged with controlled
  taxonomies, permissioned independently, and synchronized after publication.
- [Open edX publishing workflow](https://docs.openedx.org/en/latest/educators/how-tos/course_development/publish_library_content.html):
  draft, published, and published-with-pending-edits are distinct states;
  only published content is reusable.
- [Open edX problem banks](https://docs.openedx.org/en/release-teak/educators/how-tos/course_development/add_a_problem_bank_to_your_course.html):
  a course reuses selected library problems for randomized delivery rather
  than copying authoring data into every course.

Maintained open-source source was checked through GitHub API; no source code
was copied and no new dependency is proposed:

| Project | Inspected revision | Relevant evidence | Adaptation |
|---|---|---|---|
| [Moodle](https://github.com/moodle/moodle) | `6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb` (`main`) | question-bank docs, quiz/question lifecycle and tests | keep stable question identity, history, status, and usage-safe retirement |
| [Gibbon](https://github.com/GibbonEdu/core) | `80f2d3c52e40b02ca6eedca3dea7a6083a98eca8` (`v31.0.00`) | Markbook/formal assessment permissions and bulk entry | preserve teacher/director separation; do not adopt page-level PHP architecture |
| [Open edX](https://github.com/openedx/edx-platform) | `1249fbfb3f7c01de5b420725aa0d651f1f927bb5` (`master`) | content-library models, API tests, publishing and permission modules | separate authoring state from reusable/published state; keep AllTrue's campus scope |

## AllTrue decision

The first question-bank slice uses one stable `question_key` with append-only
integer versions. Editing never overwrites an old version. A version moves
through `draft → pending_review → approved → retired`; imported rows start at
`pending_review`, and only a director or super admin can approve or retire a
version. Teachers can author and submit review within their authorized
campuses.

Question banks and question versions are campus-scoped. A request must pass
the existing `require_campus` middleware and controller checks against
`auth_campus_ids`; frontend filtering is only a usability feature. The new
tables do not reference `LearningRecord`, `ClassSession`, attendance, billing,
or parent records. Later assessment snapshots may reference an approved
version, but this slice does not alter existing assessment results.

CSV import is deliberately strict: UTF-8 CSV, fixed required headers, bounded
row/byte counts, validated JSON for choices/answers, and all-or-nothing
transaction semantics. A failed row returns row-numbered errors and writes no
questions. The importer records source type/reference so licensed or internal
material can be traced; `ai_draft` is metadata only and never bypasses review.

## Deferred decisions

- subject-specific taxonomy governance and cross-campus shared libraries;
- image/math asset storage;
- student delivery, randomized selection, auto-marking, and free-text review;
- importing publisher formats such as GIFT or Moodle XML;
- AI drafting or automatic approval.

## Authorized vendor provenance boundary (2026-08-21)

The CSV contract now preserves `source_name`, `source_version`,
`source_question_key`, `grade_level`, `subject_name`,
`source_ref`, and `license_ref` on every imported version.
`question_key` remains AllTrue's internal UUID; a vendor key is stored
separately so TestGo or UPAD12 exports do not need to be rewritten into an
incompatible identifier format.

Rows marked `licensed` must include source name, source version, and a
licensing reference. The import remains UTF-8, bounded, campus-scoped,
all-or-nothing, and enters `pending_review`; no vendor account, private
session, or undocumented endpoint is accessed. Re-importing or changing
content still creates an immutable version, and the provenance is included in
the audit snapshot.
