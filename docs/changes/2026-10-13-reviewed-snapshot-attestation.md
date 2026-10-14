## 2026-10-13 — fix(inapp): 重新核對已審查快照
<!-- release-notes: silent_ship=silent-2026-10-13-reviewed-snapshot-attestation -->
<!-- silent-reason: 只新增內部唯讀快照核對 helper，尚未接入正式回寫，教職員與家長看不到差異 -->
- 新增唯讀快照核對 helper：只有回報、留言、附件與狀態證據仍與已審查簽章一致時，才產生當下的新證據；原本十五分鐘驗證保持不變。未接入正式回寫。
