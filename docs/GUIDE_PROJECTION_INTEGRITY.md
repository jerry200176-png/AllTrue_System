# Projection Integrity Guide

> Calendar / analytics APIs must never silently truncate. CI: `ClassSessionProjectionTest`.

## SOP-1: Projection Integrity

Completeness-safe consumers (calendar, analytics, reporting): no silent pagination; return 413/422 instead of partial data.

## SOP-2: API Classification

| Class | Pagination | Example |
|-------|------------|---------|
| **LIST** | Allowed | `GET /api/v1/class-sessions` (`api_kind: list`) |
| **PROJECTION** | Forbidden | `GET /api/v1/class-sessions/projection` (`completeness: full`) |

Projection JSON MUST NOT include `current_page`, `last_page`, or `per_page`.

## SOP-3: Calendar Data

Materialized calendar sessions: **ClassSession projection API only** → `sessionDatesByCourseId` → `mergeWeekCalendarOccurrences()`.

Not session truth: attendance, StudentClass OR-queries, paginated list API.

## SOP-4: Regression Tests

`ClassSessionProjectionTest`: DB count == projection total; >2000 rows complete; branch isolation; no pagination meta.

Frontend: `fetchClassSessionsProjection()` in `useCalendarDataLoad.js` only.
