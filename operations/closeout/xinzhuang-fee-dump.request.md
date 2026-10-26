# Request: Xinzhuang fee case dumps (read-only)

# Allowlisted StudentClass IDs only (campus 11): 4064, 3753, 3693
student_class_ids: 4064,3753,3693

Purpose: billing closeout fresh dumps after #4290 completeness token + #4283/#4291 tip.
**No writes.** Encrypted artifacts only (DUMP_ARTIFACT_KEY).

# kickoff 2026-10-19T18:20:00Z — cloud closeout: refresh three fee dumps via request-file path (workflow_dispatch 403 from agent)
# kickoff 2026-10-19T18:55:00Z — re-fire after serialize fix: #4293 land cancelled mid-batch dump (3753) under production-ssh env race
