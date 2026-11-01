## 2026-11-01 — ci(inapp): a reporter's 「問題仍存在」 reopens the closed GitHub issue (#4721)
<!-- release-notes: silent_ship=silent-2026-11-01-inapp-reopen-issue -->
<!-- silent-reason: internal tracking only: GitHub issue state follows an in-app reopen; nothing changes in the app -->
- `bugs:auto-intake --candidates` also lists `reopened`: reports whose latest status change is the reporter's own resolved → in_progress, as IDs and time only. The hourly `bug-auto-intake.yml` reopens each one's closed canonical issue with a `<!-- inapp-reopen:<id>:<log> -->` comment. That happens once per reopen and only counts trusted authors. It sends no ack and makes no production write. The lookup helpers moved out of the candidate loop, so a run with only reopened reports can use them.
