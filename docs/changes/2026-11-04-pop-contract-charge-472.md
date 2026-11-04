## 2026-11-04 — chore(ops): contract charge POP gains the course 2525 case (in-app #472, dry-run only)
<!-- release-notes: silent_ship=silent-2026-11-04-pop-contract-charge-472 -->
<!-- silent-reason: 只在既有 POP 操作加一個案例與測試，尚未對正式站執行（需 Jerry 審核），教職員看不到差異 -->
- `contract-charge-correction-3868` 加上 `contract_case=472`：合約 2525 的合約金額 88000 改成 76 堂 × 1100 = 83600。這張合約的帳單已是 83600 且已付清，只讀不改；帳單、付款都要剛好等於單價 × 堂數才會執行，可回復。Refs #4841
