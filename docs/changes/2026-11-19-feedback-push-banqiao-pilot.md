## 2026-11-19 — feat(feedback): 學習回饋推播先在板橋分校試辦（TD-063）
<!-- release-notes: staff_update=staff-2026-11-19-feedback-push-banqiao-pilot -->

- 板橋分校（25）開啟學習回饋推播：家長送出或追加回饋，老師／主任的鈴鐺會收到通知；老師／主任回覆後，有綁 LINE 且沒有關閉通知的家長會收到 LINE。同一則回饋 10 分鐘內只推一次。
- 其他分校維持關閉，行為不變。名單由 `PERF_FEEDBACK_PUSH_CAMPUS_IDS`（預設 `25`，`*` 全開，空白全關）決定；全域開關 `PERF_FEEDBACK_PUSH` 仍預設關閉。
- 家長可在家長端設定關閉「學習回饋通知」。
