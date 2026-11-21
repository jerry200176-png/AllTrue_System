## 2026-11-21 — fix(scheduling): 已上完的課跨分校撞課只提醒；續課也擋跨分校撞課
<!-- release-notes: staff_update=staff-2026-11-21-cross-campus-past-reminder -->

- 已經上完的課（補登出勤、補登代課、建課時補過去的堂次）如果老師同一時間在其他分校也有課：照樣儲存，畫面跳出黃色提醒，寫出日期、時間和分校，不寫學生姓名。
  - 後端把提醒放在回應的 `X-Schedule-Warnings` header（URL 編碼、一行一則），回應內容不變；前端在全站 fetch 統一顯示。
  - 補登代課（過去的堂次）原本完全不檢查，現在也會提醒。
- 續課（購買堂數、月結續期）原本不檢查老師在其他分校有沒有課；現在新的一期如果有未來堂次撞到其他分校，整個續課不建立，回 409。
- 查證：建課畫面與 AI 代建課都走 `POST class-sessions/batch`（已有檢查）、`create-multi-subject`、`schedules`、`continue-tutoring`（已有檢查）；`student-classes/sync` 已停用（410）。Excel 匯入列為低優先，這次不改。
- 來源：Founder 2026-11-21（#5588：過去課程只提醒不阻擋；優先 AI 建課路徑）。
