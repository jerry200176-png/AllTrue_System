#!/usr/bin/env python3
"""Build the class-sessions/batch payload for the actual-duration-billing
production acceptance walk.

RFC_NONSTANDARD_SESSION_DURATION_BILLING acceptance case: buy 8 standard
units of a 120-minute standard, really teach six 180-minute sessions.
    purchased_minutes = 8 * 120 = 960
    scheduled_minutes  = 6 * 180 = 1080  (so the sixth deduction runs the
                                          balance from 60 down through 0)

Each occurrence is dated in the past (kind="confirmed" == "already happened,
recorded retroactively"), spread a week apart to match the case's own
"twice a week" framing rather than compressing six real 3-hour classes into
one instant. See .github/workflows/actual-duration-acceptance.yml for why
"confirmed" is the entry that actually deducts at creation time.

Used by exactly one workflow (actual-duration-acceptance.yml) against exactly
one Founder-designated test course. Not a general-purpose course-creation
tool.
"""

import argparse
import datetime
import json
import sys


def build_session_plan(today: datetime.date) -> list[dict]:
    """Six 180-minute occurrences, most recent last, one per week going back
    ~5 weeks (offset by 3 days so no occurrence lands exactly on `today`)."""
    plan = []
    for weeks_ago in range(5, -1, -1):
        session_date = today - datetime.timedelta(days=weeks_ago * 7 + 3)
        plan.append({
            "session_date": session_date.isoformat(),
            "start_time": "14:00",
            "kind": "confirmed",
            "duration_minutes": 180,
        })
    return plan


def build_payload(student_id: int, teacher_id: int, branch_id: int) -> dict:
    today = datetime.date.today()
    return {
        "branch_id": branch_id,
        "confirmed_dates": [],
        "future_dates": [],
        "student_id": student_id,
        "teacher_id": teacher_id,
        "subject": "Math",
        "class_type": "one_on_one",
        "payment_type": "session",
        "price_per_session": 500,
        "duration_minutes": 180,
        "start_time": "14:00",
        "days_of_week": [1, 2, 3, 4, 5, 6, 7],
        "course_start_date": today.isoformat(),
        "total_classes": 8,
        "deduction_basis": "actual_duration",
        "standard_lesson_minutes": 120,
        "session_plan": build_session_plan(today),
        # 6 x 180 = 1080 > 8 x 120 = 960 -- this is deliberately the overage
        # case, so the operator must have already reviewed the coverage
        # numbers (see the RFC's acceptance case) before this script ever
        # runs; the workflow gates on dry_run/confirm before reaching here.
        "overage_confirmed": True,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--student-id", type=int, required=True)
    parser.add_argument("--teacher-id", type=int, required=True)
    parser.add_argument("--branch-id", type=int, required=True)
    args = parser.parse_args()

    payload = build_payload(args.student_id, args.teacher_id, args.branch_id)
    print(json.dumps(payload))
    return 0


if __name__ == "__main__":
    sys.exit(main())
