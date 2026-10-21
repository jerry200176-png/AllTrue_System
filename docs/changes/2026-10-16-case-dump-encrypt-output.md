## 2026-10-16 — chore(privacy): production-case-dump keeps case output out of logs and plaintext artifacts
<!-- release-notes: silent_ship=silent-2026-10-16-case-dump-encrypt-output -->
<!-- silent-reason: 只改內部唯讀診斷工作流程的輸出方式（log 不再含個案 JSON），教職員看不到差異 -->
- `production-case-dump`：個案輸出只進以 `DUMP_ARTIFACT_KEY` 加密的 `production-case.full.tar.gz.enc`（附 sha256），缺 secret 即在讀 production 前 fail closed；log 只留 case key、結束狀態、完整性旗標、位元組數與加密檔 sha256；明文在 `always()` 步驟 shred。
