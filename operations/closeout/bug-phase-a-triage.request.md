# Request: Phase-A triage write-back (cloud path, `bug-phase-a-request.yml`)
#
# OFF until the Founder separately approves setting repository variable PHASE_A_REQUEST_ENABLED=true.
# Even then, merging this file writes nothing by itself: the run waits for the Founder to approve it
# in the `production-activation` environment after reading the logged sha256, bug IDs and decisions.
#
# One block per report, at most 5, each starting with `bug_id:`. Lines starting with `#` are ignored;
# any other line fails the run. Allowed dispositions: bug, already_solved, needs_info
# (suggestion-type reports still use bug-phase-a-triage.yml close_as_logged).
# Each report must be `new`; the issue must be in this repo and name `in-app #<id>`; the reply must
# contain the issue URL, follow docs/CHAT_BUG_SYSTEM.md §3.8 and hold no name, phone or email.
# Public repo: write the reply exactly as the reporter will see it, nothing else.
#
# bug_id: 123
# github_issue_url: https://github.com/jerry200176-png/AllTrue_System/issues/4000
# disposition: bug
# public_reply: 已看過您的回報和截圖，原因是…，我們會…。追蹤：https://github.com/jerry200176-png/AllTrue_System/issues/4000
