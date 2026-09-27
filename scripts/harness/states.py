"""Task / Program state vocabulary for the AllTrue harness."""

from __future__ import annotations

from enum import Enum


class TaskState(str, Enum):
    DISCOVERED = "DISCOVERED"
    READY = "READY"
    BLOCKED = "BLOCKED"
    FOUNDER_REQUIRED = "FOUNDER_REQUIRED"
    LEASED = "LEASED"
    PLANNING = "PLANNING"
    EXECUTING = "EXECUTING"
    TESTING = "TESTING"
    PR_OPEN = "PR_OPEN"
    CI_PENDING = "CI_PENDING"
    CI_FAILED = "CI_FAILED"
    CI_GREEN = "CI_GREEN"
    MERGE_READY = "MERGE_READY"
    MERGED = "MERGED"
    STAGING_PENDING = "STAGING_PENDING"
    STAGING_VERIFIED = "STAGING_VERIFIED"
    PRODUCTION_ELIGIBLE = "PRODUCTION_ELIGIBLE"
    PRODUCTION_PENDING = "PRODUCTION_PENDING"
    PRODUCTION_VERIFIED = "PRODUCTION_VERIFIED"
    DONE = "DONE"
    FAILED = "FAILED"
    PAUSED = "PAUSED"


# Deterministic allowed edges. Evidence + governance checks are enforced in transitions.py.
ALLOWED_TRANSITIONS: dict[TaskState, frozenset[TaskState]] = {
    TaskState.DISCOVERED: frozenset({
        TaskState.READY, TaskState.BLOCKED, TaskState.FOUNDER_REQUIRED, TaskState.FAILED,
    }),
    TaskState.READY: frozenset({
        TaskState.LEASED, TaskState.BLOCKED, TaskState.FOUNDER_REQUIRED, TaskState.PAUSED,
    }),
    TaskState.BLOCKED: frozenset({
        TaskState.READY, TaskState.FOUNDER_REQUIRED, TaskState.PAUSED, TaskState.FAILED,
    }),
    TaskState.FOUNDER_REQUIRED: frozenset({
        TaskState.READY, TaskState.BLOCKED, TaskState.PAUSED, TaskState.FAILED,
    }),
    TaskState.LEASED: frozenset({
        TaskState.PLANNING, TaskState.READY, TaskState.PAUSED, TaskState.FAILED,
    }),
    TaskState.PLANNING: frozenset({
        TaskState.EXECUTING, TaskState.FOUNDER_REQUIRED, TaskState.PAUSED, TaskState.FAILED,
    }),
    TaskState.EXECUTING: frozenset({
        TaskState.TESTING, TaskState.PR_OPEN, TaskState.PAUSED, TaskState.FAILED,
    }),
    TaskState.TESTING: frozenset({
        TaskState.PR_OPEN, TaskState.EXECUTING, TaskState.FAILED, TaskState.PAUSED,
    }),
    TaskState.PR_OPEN: frozenset({
        TaskState.CI_PENDING, TaskState.FAILED, TaskState.PAUSED,
    }),
    TaskState.CI_PENDING: frozenset({
        TaskState.CI_GREEN, TaskState.CI_FAILED, TaskState.PR_OPEN, TaskState.PAUSED,
    }),
    TaskState.CI_FAILED: frozenset({
        TaskState.EXECUTING, TaskState.CI_PENDING, TaskState.FAILED, TaskState.PAUSED,
    }),
    TaskState.CI_GREEN: frozenset({
        TaskState.MERGE_READY, TaskState.FOUNDER_REQUIRED, TaskState.CI_PENDING, TaskState.FAILED,
    }),
    TaskState.MERGE_READY: frozenset({
        TaskState.MERGED, TaskState.FOUNDER_REQUIRED, TaskState.FAILED, TaskState.PAUSED,
    }),
    TaskState.MERGED: frozenset({
        TaskState.STAGING_PENDING, TaskState.DONE, TaskState.PRODUCTION_ELIGIBLE, TaskState.FAILED,
    }),
    TaskState.STAGING_PENDING: frozenset({
        TaskState.STAGING_VERIFIED, TaskState.FAILED, TaskState.PAUSED,
    }),
    TaskState.STAGING_VERIFIED: frozenset({
        TaskState.PRODUCTION_ELIGIBLE, TaskState.DONE, TaskState.FAILED,
    }),
    TaskState.PRODUCTION_ELIGIBLE: frozenset({
        TaskState.PRODUCTION_PENDING, TaskState.FOUNDER_REQUIRED, TaskState.DONE, TaskState.PAUSED,
    }),
    TaskState.PRODUCTION_PENDING: frozenset({
        TaskState.PRODUCTION_VERIFIED, TaskState.FAILED, TaskState.FOUNDER_REQUIRED,
    }),
    TaskState.PRODUCTION_VERIFIED: frozenset({TaskState.DONE, TaskState.FAILED}),
    TaskState.DONE: frozenset(),
    TaskState.FAILED: frozenset({TaskState.READY, TaskState.PAUSED}),
    TaskState.PAUSED: frozenset({
        TaskState.READY, TaskState.LEASED, TaskState.EXECUTING, TaskState.CI_PENDING,
        TaskState.FOUNDER_REQUIRED, TaskState.FAILED,
    }),
}

ACTIVE_MUTATING = frozenset({
    TaskState.LEASED,
    TaskState.PLANNING,
    TaskState.EXECUTING,
    TaskState.TESTING,
    TaskState.PR_OPEN,
    TaskState.CI_PENDING,
    TaskState.CI_FAILED,
    TaskState.CI_GREEN,
    TaskState.MERGE_READY,
})

TERMINAL = frozenset({TaskState.DONE, TaskState.FAILED})
