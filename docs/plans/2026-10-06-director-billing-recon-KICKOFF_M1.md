# Kickoff prompt — Director billing recon M1

把下面整段貼到 **Claude Code** 或 **Codex**（新 session）。不要貼進會改催繳規則的上下文。

---

## Prompt

```text
AllTrue · 接手實作 M1（只有這一片）

## 你的角色
Worker agent。規格已由 Cursor 鎖在文件裡；你實作＋測試＋開 PR。不要重開產品決策。

## 必讀（依序，勿全庫亂讀）
1. docs/plans/2026-10-06-director-billing-recon-IMPL_HANDOFF_M1.md
2. docs/plans/2026-10-06-director-student-billing-reconciliation-ia.md → §5 US-2、§6 FR-003/009、§8、§12 M1
3. .cursor/skills/alltrue-testing/SKILL.md
4. docs/GUIDE_NIGHTLY_SESSION_RECONCILE.md（「對帳」不是同一件事）
5. 程式入口：frontend/src/components/AccountingLedgerModal.vue
   backend/app/Http/Controllers/AccountingController.php（ledger）
   涵蓋日同源：MonthlyBillingService slip sessions、PaymentReport receipt session_dates

## Skills
- 必開：alltrue-testing
- merge 前：alltrue-code-review
- 原則參考（勿整包安裝）：addyosmani agent-skills 的 TDD + incremental-implementation
  見 docs/GUIDE_AGENT_SKILLS.md

## Outcome
主任在學生帳務檔展開帳單／收款 → 看到完整「涵蓋上課日」（月結＝服務期間；堂數制＝購買範圍），不是只有第一堂。

## Forbidden
- M2 入口收斂、M3 模式條、M4 文案清扫
- 改 DIRECTOR_PAYMENT_ALERT_RULES 列入條件
- 改 reported≠booked / Paid 寫入
- 第三套日期算法、新「對帳中心」、Pi 上跑測試、force-push main

## 作法
1. agent-start / 新 branch：cursor/director-billing-recon-m1-…
2. 先寫 RED 測試（ledger 或 UI 契約：涵蓋日必須出現）
3. 最小 GREEN：API 回傳或組合涵蓋日 + AccountingLedgerModal 展開 UI
4. CI 綠 → PR；body 連到 handoff 與 https://github.com/jerry200176-png/AllTrue_System/pull/3641
5. CHANGELOG 一行；不改 STAFF_UPDATES 除非教職員可見流程變了（M1 多半 silent_ship）

## Done when
- 月結＋堂數制各有自動化證明「完整日期可見」
- 截斷有「尚有 N 堂」；涵蓋日失敗時金額仍在
- diff 證明未改催繳列入條件檔
```

---

## 給人的一句話

合約 PR：#3641。Worker 只啃 M1 handoff；M2+ 另開 handoff。
