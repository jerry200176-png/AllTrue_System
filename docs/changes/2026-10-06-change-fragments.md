## 2026-10-06 — chore(ci): PRs add change fragments instead of editing shared CHANGELOG / updates lists
<!-- release-notes: silent_ship=silent-2026-10-06-change-fragments -->
<!-- silent-reason: 只改內部發版紀錄的寫法與產生方式，教職員看到的畫面與版本更新內容完全不變。 -->
- 新增 `docs/changes/<date>-<slug>.md`（CHANGELOG 條目＋release-notes 標記，靜默例外原因寫在同檔 `silent-reason`）、`docs/staff-updates/<id>.yml`、`docs/parent-updates/<id>.yml`（一檔一卡）；`docs/CHANGELOG.md`、`RELEASE_NOTES_EXEMPTIONS.yml`、`STAFF_UPDATES.yml`、`PARENT_UPDATES.yml` 凍結為歷史，產生器與覆蓋／分級／release 腳本讀「舊檔＋fragment」，既有資料輸出逐位元組相同。
- `frontend/src/lib/{changelogDraft,staffUpdates,parentUpdates}.generated.js` 不再進 git；`postinstall`／`predev`／`pretest:*`／`build` 會自動產生，CI 移除「generated 與 commit 不符」檢查。`SystemTrustController` 的近期改善也讀 fragment。
