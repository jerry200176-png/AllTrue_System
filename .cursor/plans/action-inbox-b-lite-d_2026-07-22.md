# Decision: Director Action Inbox (B-lite + D)

**Date:** 2026-07-22  
**Status:** Accepted (MVP in progress)

## Decision (one line)

採 B-lite + D：建立唯讀 Action Inbox 聚合 Notifications 與 open exception workflows，請假案件仍以 workflow 為唯一真相來源，不新增重複 Notification；MVP 只做可靠觸達、SLA 顯示與 deep link，既有補課結案流程不動。

## Why not Option A

Duplicating leave into `Notifications` creates dual-write / dual-status debt (read vs case open, resolve on every confirm/waive path, retry duplicates).

## Boundaries

- **Source of truth (cases):** `exception_workflows` (`student_leave`, open|candidate_ready)
- **Source of truth (ops reminders):** `Notifications` + `NotificationSyncService` managed types
- **Read model only:** `ActionInboxService` — no confirm/waive/mark-read writes
- **Badge contract:** `needs_attention` ≠ `unread_count`; expose `notifications_unread` + `cases_open` separately

## Out of scope (this MVP)

Inbox-inline candidate confirm, teacher co-ownership, LINE push to directors, schema rewrite, changing leave deduction / attendance rules.
