## 2026-11-27 — 上線驗證多看一項：網頁服務真的在跑新版
<!-- release-notes: silent_ship=silent-2026-11-27-runtime-build-identity -->
<!-- silent-reason: Deploy verification only; the health endpoint gains one field that is already public in deployment.json. -->

- `/api/v1/health` 多回一個 `build`（這個 PHP 服務載入的版本 SHA）。上線後的驗證會比對它；如果網頁服務還在跑舊版，就不算上線成功（#4056 第 1 項）。
