---
title: "ci(deploy): release train retries every 15 min within each window"
silent_ship: silent-2026-10-06-release-train-retries
---
<!-- silent-reason: deploy control plane only; no user-facing change -->
- Release train cron retries at +15/+29/+44 min in the 07:30 and 12:30 windows (GitHub drops scheduled runs; the main tip may still be in CI). A retry stands down while another deploy run already waits for approval.
