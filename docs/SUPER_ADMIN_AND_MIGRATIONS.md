# Super Admin & Migrations (Docker)

Your app runs in Docker. Run these from the project root in PowerShell.

## 1. Run migrations

```powershell
docker compose exec app php artisan migrate
```

This applies the new migrations (Campus `code` column and ghost-user fix).

## 2. Promote your account to Super Admin

**Step 1:** Edit `scripts/promote-super-admin.sql` and replace `director@school.com` with your actual login email.

**Step 2:** From the project root, pipe the SQL file into psql (avoids PowerShell quoting issues):

```powershell
Get-Content .\scripts\promote-super-admin.sql -Raw | docker compose exec -T postgres psql -U alltrue -d alltrue
```

**Note:** If your database user or database name differ, check `backend/.env` for `DB_USERNAME` and `DB_DATABASE` and use those values instead of `alltrue`.

## 3. Verify

After logging in again, you should see "超級管理員 Super Admin" in the sidebar and be able to see all branches/teachers/students.
