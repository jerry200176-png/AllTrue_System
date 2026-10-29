## 2026-10-29 — chore(learning): the frontend stops sending DirectorID to learning-record endpoints (#4443)
<!-- release-notes: silent_ship=silent-2026-10-29-learning-record-no-director-id -->
<!-- silent-reason: Request bodies only; the server already ignores DirectorID (reviewer comes from the login, #4440), so nothing changes on screen. -->
- 學習日誌核准／退回／批次與補登請求不再帶 `DirectorID`；後端規則保留給舊版畫面（#4443）。
