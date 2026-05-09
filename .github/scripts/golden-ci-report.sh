#!/usr/bin/env bash
# Golden scenarios — path → § mapping for CI / Presubmit (no manual checkboxes).
# See docs/QA_GOLDEN_SCENARIOS.md
set -euo pipefail

BASE="${GOLDEN_BASE_REF:-origin/main}"
HEAD="${GOLDEN_HEAD_REF:-HEAD}"

if ! git rev-parse "$BASE" >/dev/null 2>&1; then
  echo "::notice::$BASE not found; skipping Golden report"
  exit 0
fi

mapfile -t FILES < <(git diff --name-only "$BASE...$HEAD" 2>/dev/null || true)

S0=0 S1=0 S2=0 S3=0 S4=0

for f in "${FILES[@]}"; do
  # §0 auth / campus / health
  if [[ "$f" =~ backend/routes/api\.php ]] || [[ "$f" =~ backend/app/Http/Middleware/ ]] || \
     [[ "$f" =~ backend/app/Http/Controllers/Auth ]] || [[ "$f" =~ AttachAuthUser\.php ]] || \
     [[ "$f" =~ backend/app/Http/Kernel\.php ]]; then
    S0=1
  fi
  # §1 parent
  if [[ "$f" =~ ParentPortal ]] || [[ "$f" =~ ParentSession ]] || [[ "$f" =~ parent/ ]] || \
     [[ "$f" =~ ParentController ]] || [[ "$f" =~ backend/app/Models/Student\.php ]] || \
     [[ "$f" =~ backend/tests/Feature/Parent ]]; then
    S1=1
  fi
  # §2 attendance / swipe / sessions
  if [[ "$f" =~ AttendancePage ]] || [[ "$f" =~ AttendanceController ]] || [[ "$f" =~ SwipeRfid ]] || \
     [[ "$f" =~ ClassSession ]] || [[ "$f" =~ StudentSingIn ]] || [[ "$f" =~ backend/tests/Feature/Attendance ]] || \
     [[ "$f" =~ backend/tests/Feature/Swipe ]] || [[ "$f" =~ backend/tests/Feature/MakeupAttendance ]]; then
    S2=1
  fi
  # §3 calendar merge
  if [[ "$f" =~ SmartCalendar ]] || [[ "$f" =~ calendarOccurrenceMerge ]] || [[ "$f" =~ CourseLeave ]] || \
     [[ "$f" =~ ScheduleController ]] || [[ "$f" =~ lib/sessionDates\.js ]] || \
     [[ "$f" =~ backend/tests/Feature/Substitute ]] || [[ "$f" =~ backend/tests/Feature/ScheduleLeave ]]; then
    S3=1
  fi
  # §4 billing / tuition / student class lifecycle
  if [[ "$f" =~ AlertController ]] || [[ "$f" =~ Invoice ]] || [[ "$f" =~ Payment ]] || \
     [[ "$f" =~ Tuition ]] || [[ "$f" =~ StudentClassController ]] || [[ "$f" =~ CoursePackage ]] || \
     [[ "$f" =~ backend/tests/Feature/Billing ]] || [[ "$f" =~ backend/tests/Feature/Payment ]] || \
     [[ "$f" =~ backend/tests/Feature/Tuition ]] || [[ "$f" =~ backend/tests/Feature/MonthlyRenew ]] || \
     [[ "$f" =~ backend/tests/Feature/StudentClass ]]; then
    S4=1
  fi
done

cell() { [[ "${1:-0}" -eq 1 ]] && echo 'yes' || echo '—'; }

SUMMARY_BLOCK=$(cat <<EOF
## Golden scenarios (automated traceability)

> Range: \`$BASE...$HEAD\` — see \`docs/QA_GOLDEN_SCENARIOS.md\`

| § | Zone | Touched | Covered when CI is green |
|---|------|---------|---------------------------|
| §0 | Auth / campus / routes | $(cell "$S0") | Backend job → PHPUnit |
| §1 | Parent / Student contact | $(cell "$S1") | Backend job → PHPUnit (Parent* tests) |
| §2 | Attendance / RFID / ClassSession | $(cell "$S2") | Backend job → PHPUnit |
| §3 | Calendar merge / leave | $(cell "$S3") | Frontend job → \`npm run test:calendar\` + Vite build |
| §4 | Billing / tuition / packages | $(cell "$S4") | Backend job → PHPUnit |

EOF
)

HIT=$((S0+S1+S2+S3+S4))
if [[ "$HIT" -eq 0 ]]; then
  SUMMARY_BLOCK+=$'\n'"**No Golden-zone production paths in this diff** (docs-only / tooling-only is OK)."
else
  SUMMARY_BLOCK+=$'\n'"**No manual checklist.** Required checks: PHPUnit and/or Vite per \`ci.yml\` \`changes\` job."
fi

SUMMARY_BLOCK+=$'\n\n'"<details><summary>Changed files (${#FILES[@]})</summary>"$'\n\n'
if [[ ${#FILES[@]} -gt 0 ]]; then
  SUMMARY_BLOCK+=$(printf '%s\n' "${FILES[@]}" | sed 's/^/- /')
else
  SUMMARY_BLOCK+="_(none — empty diff range)_"
fi
SUMMARY_BLOCK+=$'\n\n'"</details>"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  printf '%s\n' "$SUMMARY_BLOCK" >> "$GITHUB_STEP_SUMMARY"
else
  printf '%s\n' "$SUMMARY_BLOCK" >&2
fi

echo "Golden zones: §0=$S0 §1=$S1 §2=$S2 §3=$S3 §4=$S4 (files=${#FILES[@]})"
exit 0
