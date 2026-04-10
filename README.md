# AllTrue System (Admin Workspace)

This repository is the active admin workspace for the AllTrue system.

## Collaboration Branch

- Current collaboration default branch on GitHub: `jerry-sync-main`
- Local daily branch in this workspace: `main` (tracking `origin/jerry-sync-main`)

## Daily Sync (Fast)

From `/home/admin`:

```bash
./scripts/git-sync.sh "feat: your update message"
```

This command will:
- stage all changes
- create a commit
- push to the current branch on GitHub

If no message is provided, it uses a timestamped default message.

## Recommended Team Workflow

1. Update local:
   ```bash
   git checkout main
   git pull
   ```
2. Create feature branch:
   ```bash
   git checkout -b feature/your-topic
   ```
3. Develop and sync:
   ```bash
   ./scripts/git-sync.sh "feat: your-topic"
   ```
4. Open PR into `jerry-sync-main`.

## Recovery / Incident SOP

See:
- `docs/INCIDENT_2026-04-10_GITHUB_AND_SITE_ROLLBACK.md`
- `docs/GITHUB_SYNC_WORKFLOW.md`

