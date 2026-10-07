## 2026-10-07 — chore(deps): clear Dependabot #68/#70 (dev lint deps, unused pusher test server)
<!-- release-notes: silent_ship=silent-2026-10-07-dependabot-cleanup -->
<!-- silent-reason: 只改開發工具版本並刪除未使用的上游測試檔，教職員看到的畫面與操作不變。 -->
- 刪除未使用的上游測試伺服器 `frontend/vendor-modules/pusher-js/integration_tests_server/`（Dependabot #70 proxy-addr）。
- `eslint-plugin-vue` 9→10、`vue-eslint-parser` 9→10（開發用；Dependabot #68 postcss-selector-parser 6.1.4→7.1.6）。
