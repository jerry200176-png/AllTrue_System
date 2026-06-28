# History Purge Validation Log (2026-06-28)

Disposable clone test of [`scripts/security-filter-repo.sh`](../scripts/security-filter-repo.sh):

| Check | Result |
|-------|--------|
| `.env.monitor` removed from tree | PASS |
| `.cursor/projects/**` untracked | PASS |
| Production SQL dump blobs | PASS (only `scripts/*.sql` remain) |
| Telegram token / `ghp_*` grep | PASS (no matches) |
| gitleaks full history | **37 findings** — mostly test PEM, CI placeholders, legacy `TelegramWebHook/` backups |

## Before force-push to `origin`

1. Merge HEAD cleanup PR (removes `.env.monitor`, `.cursor/projects/**`, `TelegramWebHook/**` from tip).
2. CEO completes [`SECURITY_CREDENTIAL_ROTATION.md`](SECURITY_CREDENTIAL_ROTATION.md).
3. Maintenance window: relax branch protection → run filter-repo on fresh mirror → `git push --force --all && git push --force --tags`.
4. Re-run `scripts/security-gitleaks-audit.sh`; triage remaining findings (expand `--invert-paths` if needed).
5. All collaborators re-clone; enable GitHub secret scanning + push protection.

**Do not force-push `main` without explicit CEO approval** (P0: incident A).
