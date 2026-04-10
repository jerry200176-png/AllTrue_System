# GitHub Sync Workflow (No Reminder Needed)

This SOP is for keeping local updates and GitHub always in sync.

## One-Time Setup

1. Ensure SSH auth works:
   ```bash
   ssh -T git@github.com
   ```
2. Ensure remote uses SSH:
   ```bash
   git remote -v
   # expected: git@github.com:jerry200176-png/AllTrue_System.git
   ```

## Standard Update Flow

Run from `/home/admin`:

```bash
./scripts/git-sync.sh "feat: concise message"
```

That is the only command needed for normal updates.

## New Feature Flow (Team)

```bash
git checkout main
git pull
git checkout -b feature/your-topic
./scripts/git-sync.sh "feat: your-topic"
```

Then open a PR to `jerry-sync-main`.

## Safety Rules

- Do not force push to shared branch.
- Do not run branch surgery (`reset --hard`, unrelated-history merges) on a dirty tree.
- Before major Git operations, run:
  ```bash
  git status -sb
  ```

## If Push Is Rejected

```bash
git fetch origin
git status -sb
git pull --rebase
git push
```

If histories are unrelated, create a clean branch from remote base and cherry-pick.

