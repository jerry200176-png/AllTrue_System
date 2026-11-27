## 2026-11-28 — ci(deploy): 段考參考日期資料檔缺少 patch 時不再卡住上線
<!-- release-notes: silent_ship=silent-2026-11-28-patchless-exam-data-file -->
<!-- silent-reason: 只改上線分類規則，使用者畫面沒有變化 -->
- Founder 2026-11-28 決定（`FOUNDER_STANDING_DECISIONS.md` 同日條）：`deploy.yml` 只對 `frontend/src/lib/schoolExamReferenceData.generated.js` 的原地修改允許沒有 GitHub patch；其他路徑照舊擋下。
- 新增測試釘住這條規則，並檢查該檔維持純資料。
