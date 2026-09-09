"""Release activation control-plane policy.

This adapter separates the two supported approval mechanisms:

* a post-merge ``workflow_run`` or autonomous ``repository_dispatch`` must
  pause on the production Environment's required-reviewer rule; and
* an explicitly dispatched exceptional operation keeps the existing typed
  confirmation path.

The function is intentionally read-only. It validates the live GitHub
Environment shape; it never changes repository settings or production state.
"""

from __future__ import annotations


APPLICATION_PHASE = "application-deploy"
MANUAL_PHASES = {
    APPLICATION_PHASE,
    "parent-portal-smoke",
    "pop-bootstrap",
    "phase1-create",
    "phase2-cutover",
    "phase3-lock",
}


def environment_protection_is_valid_for_release(
    *,
    event_name: str,
    phase: str,
    required_reviewers_configured: bool,
    prevent_self_review: bool,
) -> bool:
    """Return whether the event has a safe, non-duplicated approval boundary.

    A normal protected release is one workflow run: GitHub must hold the
    run until an independently configured reviewer approves the Environment.
    Manual exceptional operations retain the existing typed Founder gate and
    therefore reject a reviewer rule that would create an unsatisfiable
    second approval queue.
    """

    if event_name in {"workflow_run", "repository_dispatch"}:
        return (
            phase == APPLICATION_PHASE
            and required_reviewers_configured
            and prevent_self_review
        )
    if event_name == "workflow_dispatch":
        return (
            phase in MANUAL_PHASES
            and not required_reviewers_configured
            and not prevent_self_review
        )
    return False


__all__ = [
    "APPLICATION_PHASE",
    "MANUAL_PHASES",
    "environment_protection_is_valid_for_release",
]
