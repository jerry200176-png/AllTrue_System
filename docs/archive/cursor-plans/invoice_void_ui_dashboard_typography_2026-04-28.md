# PRD — Invoice 作廢入口 + 代課動態排版字型

## 背景 / 根因

- 系統已支援 `Invoice.Status='void'` 排除家長應收、課程帳單與主任催繳統計，但主任沒有系統內作廢入口，只能靠資料修復草案。
- 「代課動態」header 會被 badge/卡片寬度擠壓，且字型 fallback 未集中成穩定 token。

## Scope

In:
- 新增受權限保護的單筆 Invoice 作廢 API。
- 課程管理月結帳單 Modal 新增作廢按鈕、原因輸入與二次確認。
- 作廢後必填原因並保存操作者/時間於稽核文字。
- 修正代課動態 header layout 與全域 `Inter + Noto Sans TC + 台灣系統字` fallback。

Out:
- 不作廢 paid/partial invoice。
- 不刪 Invoice / Payment / PaymentReport。
- 不直接寫 production DB。
- 不新增 paid font 或新的外部 font CDN。

## 需求

- FR-001：director/admin/super_admin 才可作廢 Invoice，且必須通過 campus isolation。
- FR-002：只允許非 paid/partial、`PaidAmount=0`、無正向 Payment 的 Invoice 作廢。
- FR-003：作廢原因必填，3-255 字。
- FR-004：作廢寫入 `Status='void'`，`Note` 追加 reason/user/time。
- FR-005：前端只對可作廢帳單顯示「作廢」。
- FR-006：代課動態 header 在窄寬度不切字、不溢出。

## 風險與決策

- 已收款帳單必須走收款撤銷/沖銷流程；直接 void paid invoice 會破壞帳務稽核。
- 暫不加 migration；若作廢量增加，再補結構化 `VoidedAt/VoidedByUserID/VoidReason`。
- 業界 SaaS dashboard 常用 Inter；中文需明確 CJK fallback，避免標點與中文字渲染不穩。

## 驗收

- PHPUnit 覆蓋成功作廢、paid/partial/有 payment 擋下、cross-campus 403、teacher 403。
- 作廢後 `student-classes/{id}/invoices` 不再回傳該帳單。
- `npm run build` 成功。
- PR CI / PHPStan / Vite / Presubmit 全綠。
