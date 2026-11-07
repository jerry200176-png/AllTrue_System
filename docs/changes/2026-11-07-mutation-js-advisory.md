## 2026-11-07 — chore(ci): advisory StrykerJS diff mutation testing for the control-plane scripts
<!-- release-notes: silent_ship=silent-2026-11-07-mutation-js-advisory -->
<!-- silent-reason: 只新增開發流程中的建議性變異測試檢查，教職員看到的畫面與功能完全不變。 -->
- 新增 `mutation-js-advisory.yml`：PR 改到 `.github/scripts/` 的審查／合併腳本時，只對改動行跑 StrykerJS（Apache-2.0，固定 10.1.1），存活的 mutant 併入與 PHP 相同的黏性 PR 留言。建議性、不擋合併、不排程。
