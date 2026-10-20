# Goal A–E owner unblock — public-safe status

Last updated: 2026-10-20T02:15Z  
No secrets, financial amounts, student PII, or encrypted dump payloads in this file.

## Track verdict (current)

| Track | Status | Public evidence |
|-------|--------|-----------------|
| A Xinzhuang fee trio | **INCOMPLETE** | Live backend `ad9e36904283` (DtP `39241462999`); tip `e6208fc8d7aa`; #4283 `506729c78ab4` on tip **not** on live; acceptance **0/3** — see `xinzhuang-fee-closeout.status.md` |
| B #401/#402 / contract 4131 | **INCOMPLETE** (code LIVE) | #4245 ancestry under live pin; dump run `39327056654` public ok; **Dot PASS** still required |
| C #4132 | **COMPLETE** | Squash-merged on main |
| D remaining-18 sends | **INCOMPLETE** | Packages prepared; **production-write GO** absent; 0 sent |
| E ID→PR→SHA→驗收→回覆 | **INCOMPLETE** | SHA bindings prepared privately; 0/14 non-overlap sent; GH issues remain OPEN (no dummy Closes) |

## Do not

- Approve waiting release train `39333337571` while local Cursor case-execution pin holds live `ad9e369`
- Treat aggregate `deploy.yml` success as live when **Deploy to Production** is skipped
- Claim Goal Done without A/B/D/E product evidence

## Ordered owner asks

1. **A tip** — After local pin coordination: Founder `production-activation` for tip ≥ `506729c` → **Deploy to Production** success + `deployment.json` backend ancestry. **Pre-req:** tip Deploy Merge/activation contract must be green (pop-mount shared lock needs `queue: max`) so Deploy to Pi can reach DtP / train start again.
2. **A vault** — Bind `DUMP_ARTIFACT_KEY` on the AllTrue Cloud environment (Personal vault); never paste into chat/PR
3. **A 3a / 3b / POP** — `#4300` allowlist workflow after tip live; flag stays default-off until case POP; apply 4064/3693 only (3753 excluded until reversal-row proof)
4. **B Dot PASS** — Score dump `39327056654` (occupancy ignores `stale_skip_vs_contract=true` on stale teacher ids)
5. **D WRITE_GO** — Unlocks remaining-18 Dot-channel sends + Phase-C for live fixes; then 401/402 after B
6. **Product answers** — `docs/plans/2026-10-20-inapp-closeout-product-decisions.md` (#4317): #398 KEEP vs DIRECT_ENTRY; #412 amount rule; #413 inbox lanes; #410 follows protected `#4215`/`#4216` + `#4210` (flag OFF)

## Already landed (do not re-ask)

- #4132 merge (track C)
- #4308 calendar/course acceptance listens for `Deploy to Pi` **and** `Release train`
- #4314 / #4318 / #4319 Xinzhuang + Goal A–E tip/live status hygiene
- #4317 closeout product-decision packet
- #4298 contract_4131 dump request-file + dump `39327056654`
- #4300 agreed-fee allowlist activation surface

## Next sequence (A)

local pin coordinate → tip activation + migration → optional `set_allowlist` → fresh dumps → decrypt → POP → flag → corrections → verify → N/3.
