# AllTrue Phase 2 — Journey selection + OSS research brief

**Task:** #1319 · **Selected journey:** Parent LINE → LIFF deep-link tabs (`ParentPortal` schedule / attendance / billing)  
**Product close-first vehicle:** [PR #1200](https://github.com/jerry200176-png/AllTrue_System/pull/1200)  
**Risk close-first (separate track):** [PR #1292](https://github.com/jerry200176-png/AllTrue_System/pull/1292) — not this journey

---

## Why this journey (evidence, not aesthetics)

| Signal | Evidence |
|--------|----------|
| Usage | LINE is primary off-app parent channel; Flex buttons already encode `?tab=` targets |
| Bug / Issue density | P1 parent notification contract incomplete without deep links (#1200 body; portfolio failed-CI) |
| User-blocking | Parents land on wrong/default tab after push → miss billing/attendance actions |
| Mobile friction | LIFF is mobile-first; tab UX must work in LINE webview |
| Business impact | Tuition / attendance trust; reduces “通知点了没反应” support load |
| Operational importance | Backend templates already assume endpoints — dependency unblock |

### Candidates not selected

| Journey | Why not now |
|---------|-------------|
| 主任首頁與今日決策 | High ops value but fewer ready PRs; #911 family heavier; capacity = 1 UX item |
| 課表／調課 | Covered by **risk** track (#1292), not product redesign |
| 請假／補課 | Lower Issue density vs LINE parent P1 this sprint |
| 收費與帳務異常 | Money risk largely Founder-decision (#1096/#1152); no ready UX PR |
| 學習紀錄 | Chronic debt; not highest close-first ROI |
| in-app bug report | Governance tooling, not core parent job |

---

## Required Research Brief

```text
User problem
  Parent opens LINE Flex button expecting a specific portal tab (課表 / 出缺勤 / 帳務)
  but LIFF loads default view — must hunt or ask staff.

Current evidence
  PR #1200 (ParentPortal ?tab=); portfolio marks failed CI; backend Flex already
  emits deep-link URLs; mobile LIFF is production path.

Sources reviewed
  1) Gibbon (school management) — student/parent portal navigation patterns
  2) OpenEMIS Core — role-scoped admin vs guardian information hierarchy
  3) FullCalendar / school-calendar admin UIs — schedule density on mobile
  4) Stripe Dashboard / accessible enterprise patterns (via RULE_DESIGN_SYSTEM lineage)
     — secondary for tokens only, not parent IA

Patterns observed
  - Deep links map notification intent → exact destination
  - Guardian vs staff terminology stay separate
  - Mobile tabs with clear selected state + focus

Pattern selected
  Complete ?tab= deep-link handling (merge/fix #1200); preserve default when param absent;
  align copy with Chinese parent vocabulary (課表／出缺勤／帳務).

Patterns rejected
  - New parallel parent app shell / redesign of entire ParentPortal
  - Card-heavy dashboard chrome
  - Copying Gibbon/OpenEMIS layouts wholesale

Why it fits this domain
  AllTrue parents already live in LINE; fixing intent→tab is the actual job,
  not a new design system.

License impact
  Research-only; no dependency adoption. Gibbon GPL / OpenEMIS AGPL — do not copy code.

Engineering impact
  Frontend routing in ParentPortal.vue; fix Presubmit on #1200; no schema change.

Expected outcome
  LINE Flex clicks land on correct tab; support tickets for “点了没反应” drop;
  mobile LIFF remains fully operable.

Validation method
  Manual LIFF URLs for each tab; regression: absent param → default tab;
  a11y: focus on selected tab; screenshot desktop/mobile if LIFF allows;
  production verify after merge on one campus LINE push.
```

---

## UX investigation (14)

1. **Role:** Parent (LINE LIFF), not director.
2. **Job:** Reach the specific school fact the notification mentioned (schedule / attendance / billing).
3. **Stuck step:** Landing on wrong tab / default home after Flex click.
4. **Longest think:** Which menu item matches the Chinese Flex label.
5. **Easy mistake:** Opening wrong tab then believing data is missing.
6. **Engineering jargon:** Avoid “portal”, “LIFF”, “query param” in UI — keep 課表／出缺勤／帳務.
7. **Mobile:** Must work fully in LINE webview (primary).
8. **Loading:** Tab switch should show pending state if data fetch is slow.
9. **Empty:** Each tab needs next step (e.g. 尚無出缺勤 → 聯絡主任), not blank.
10. **Error:** Recoverable retry; do not wipe tab selection.
11. **Partial data:** Do not show zero tuition as “paid in full” when API failed.
12. **Destructive:** N/A for deep-link landing (read-mostly).
13. **Success:** Correct tab selected + content matches Flex intent.
14. **Need engineer?** Today yes if deep link broken — after #1200, should not.

---

## Product PR verdict for this phase

**Ship path:** Close-first **#1200** (repair CI / merge) — **not** a greenfield redesign.

If #1200 cannot be repaired with evidence this phase → honest **FAIL** for “AllTrue selected journey improvement” subsection (do not invent a visual redesign PR).

**Design SSOT:** `docs/RULE_DESIGN_SYSTEM.md` (+ root `design.md` pointer). No design-v2.
