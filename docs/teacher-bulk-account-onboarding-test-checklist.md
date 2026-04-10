# 批量老師帳號與首登改密碼 測試清單

## 後端 API 驗收

- `POST /api/v1/profiles/bulk-teachers` 可同時建立多位老師帳號，回傳 `created[] / failed[] / summary`。
- 同批次包含重複帳號時，回傳部分成功，且不影響其他有效列建立。
- `created[]` 每筆包含 `initial_password` 與 `must_change_password=true`。
- 新建立老師 `User.MustChangePassword = 1`，且可查到對應 `Teacher`、`UserCampus`、`teacher_subjects` 資料。

## 首登鎖定流程（老師端）

- 老師以初始密碼登入後，登入回應 `session.user.must_change_password = true`。
- 鎖定期間僅能進入個人管理的密碼修改流程；其餘功能 API 會回 `428` + `PASSWORD_CHANGE_REQUIRED`。
- 呼叫 `PUT /api/v1/me` 成功修改密碼後，`must_change_password` 變為 `false`。
- 修改後可正常存取一般頁面與原本受限 API。

## 前端驗收（主任）

- 老師管理頁可開啟「批次新增老師」視窗。
- 貼上 CSV/文字資料可正確預覽列數、帳號、姓名、主分校、科目。
- 成功建立後可看到一次性帳密清單，支援複製與 CSV 下載。
- 建立失敗列會顯示錯誤，且可用「僅保留失敗筆」重新送出。

## 前端驗收（老師）

- `must_change_password=true` 時，側欄除個人管理外功能不可進入，頁面顯示安全提示。
- Profile 安全性頁會引導先改密碼，改密碼成功後解除鎖定。
- 解鎖後自動回到正常使用流程（老師進學習評量、主任進總覽）。
