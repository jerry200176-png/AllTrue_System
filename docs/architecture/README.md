# AllTrue architecture index

本目錄是 AllTrue 架構文件入口。既有 RFC、ADR 與 control-plane contract
仍是決策與 runtime 行為的權威；Archify 圖稿只提供以實際 source code
為證據的可視化，不建立第二套架構規則。

## Canonical format

- `*.architecture.json`、`*.lifecycle.json`、`*.workflow.json` 是 Archify
  圖稿的 canonical source，必須固定 repository revision，並在 architecture
  圖中保留 source path／line evidence。
- Generated HTML 不提交 git。它們約 700 KB／份，而 JSON 約 4–5 KB／份；
  HTML 可由 canonical JSON 隨時重建，避免 stale 文件與大型 generated diff。
- 需要 review 時，在本地以 Archify `deliver` 或 `preview` 產生 HTML；提交
  前只需確認 JSON parse、Archify validate 與一般 docs integrity。
- Archify 僅用於 critical domain／architecture：排課與出缺勤、Learning
  Record、堂次／合約 entitlement、收款／收據、以及 release／control-plane
  邊界。不要求每份文件都畫圖，也不新增每 PR 強制 Archify gate。

## Current code-backed diagrams

| Diagram | Canonical JSON | Evidence focus |
|---|---|---|
| Runtime architecture | [alltrue-runtime.architecture.json](alltrue-runtime.architecture.json) | Vue UI、API client、Laravel routes、auth/campus gate、domain services、operational DB、LINE/RFID |
| 排課 → 上課 → 點名 → Learning Record | [alltrue-session-lifecycle.lifecycle.json](alltrue-session-lifecycle.lifecycle.json) | `backend/routes/api.php` session／attendance／Learning Record routes；attendance effects 與 record integrity services |
| 合約／堂次 → 預排 → 使用 → 調整／轉移 → 結束 | [alltrue-entitlement-lifecycle.lifecycle.json](alltrue-entitlement-lifecycle.lifecycle.json) | StudentClass/session routes；amendment、transfer、deduction ledger services |
| 收款 → 對帳 → 電子收據 | [alltrue-payment-receipt.workflow.json](alltrue-payment-receipt.workflow.json) | payment-report／receipt routes、AccountingController、PaymentReportController、ReceiptModal |
| PR → CI → merge → deploy → production verify | [alltrue-release-deploy.workflow.json](alltrue-release-deploy.workflow.json) | `.github/workflows/ci.yml`、`.github/workflows/deploy.yml`、`docs/CONTROL_PLANE_CONTRACT.md` |

本次 pilot 的 repository evidence revision 是
`76d91d8b061315ede9358db035190aee48f27ca2`。五份 JSON 都使用 Archify
`showcase` profile；source cards 與完整 path／line range 以 JSON 為準。

## Existing architecture authorities

- [AllTrue Engineering North Star](ALLTRUE_ENGINEERING_NORTH_STAR.md) — 現行工程主線與不可整包重寫的邊界。
- [Schedule occurrence identity RFC](RFC_SCHEDULE_OCCURRENCE_IDENTITY.md) — 排課 occurrence 身分的規劃權威。
- [Reported-paid accounting split RFC](RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md) — 行政回報與會計入帳分離。
- [Control Plane Contract](../CONTROL_PLANE_CONTRACT.md) — production execution boundary。
- [Parent Identity target architecture](PARENT_IDENTITY_TARGET_ARCHITECTURE.md) — 家長／學生 identity 目標架構。

若圖稿與 RFC、ADR 或 control-plane contract 衝突，以後者為準；圖稿應
更新或刪除，不得自行成為新的行為規範。

## Local rendering example

```bash
ARCHIFY_BIN=/path/to/archify/bin/archify.mjs
node "$ARCHIFY_BIN" validate workflow docs/architecture/alltrue-payment-receipt.workflow.json --quality showcase
node "$ARCHIFY_BIN" deliver workflow docs/architecture/alltrue-payment-receipt.workflow.json /tmp/payment-receipt.html --quality showcase
```

`ARCHIFY_BIN` 由執行環境提供；不要把本機 skill 絕對路徑寫入產品
runtime。HTML 是暫時 review output，不是 repository source of truth。

## Scope boundary

本次 pilot 完成後不預設新增圖稿，也不延伸 parent identity／student onboarding。
只有未來有實際 evidence 顯示某 critical domain 因架構理解不足反覆出錯，才重新
評估新增或更新圖稿。
