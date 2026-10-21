## 2026-10-21 — fix(billing): 加購新批次略過原課程仍占用的時段（in-app #422）
<!-- release-notes: staff_update=staff-2026-10-21-purchase-batch-source-overlap -->

- 加購（purchase-batch）時，若原課程仍有已預排的未來堂次，新批次會從原課程最後一堂之後的固定時段開始排，不再把原課程本身誤判為「其他課程」而整筆拒絕。
- 只略過原課程自己占用的時段；學生其他課程占用的時段仍照舊拒絕（`student_slot_conflict`）。續報預覽顯示的開始／結束日期與實際建立一致。
- 不修改正式資料；已失敗的加購需重新送出。Refs #4287。
