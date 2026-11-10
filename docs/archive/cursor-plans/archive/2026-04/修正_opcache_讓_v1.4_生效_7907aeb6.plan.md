---
name: 修正 opcache 讓 v1.4 生效
overview: v1.4 程式碼已正確寫入 FinanceController.php，但 PHP-FPM opcache 快取舊版 bytecode，導致 Web 請求仍執行舊邏輯。重啟 PHP-FPM 並加入 opcache 自動清除機制解決此問題。
todos:
  - id: restart-fpm
    content: sudo service php8.2-fpm restart 清除 opcache
    status: completed
  - id: verify-report
    content: 重新載入 Ruth蔣 薪資報表，確認 04-03 合計從 1350 變為 900
    status: completed
  - id: add-deploy-script
    content: 在 deploy.sh 加入 fpm restart，避免 opcache 問題再次發生
    status: completed
isProject: false
---

# 修正 opcache 讓 v1.4 薪資邏輯生效

## 診斷結果

- `opcache.enable = ON`（PHP 8.2）
- PHP-FPM master process 啟動於 **15:37**，早於我們的程式碼修改
- 結果：Web 請求仍執行舊版 `buildConcurrencyBonusMap`（v1.3 行為）
- PHPUnit 測試通過是因為測試直接讀原始碼，不走 opcache

## 預期修正後的數字（Ruth蔣 04-03，n=3 同層級）

計算規則（使用者確認）：`350 + (3-1)×50 = 450/h × 2h = 900`

| 項目 | 修正前（截圖） | 修正後（v1.4） |
|------|------------|-------------|
| 陳則佑（primary） | 750 | 900 |
| 張正甫（non-primary） | 600 | 0 |
| 張正崩（non-primary） | 0 | 0 |
| **合計** | **1350** | **900** |

## 步驟

### Step 1 - 重啟 PHP-FPM 清除 opcache
```bash
sudo service php8.2-fpm restart
```

### Step 2 - 確認 Web 服務恢復正常
```bash
curl -s http://localhost/api/health 2>/dev/null || echo "check manually"
```

### Step 3 - 防止未來重蹈覆轍：加入部署腳本
在 `backend` 目錄建立或更新 `deploy.sh`，確保每次部署都清 opcache：
```bash
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
sudo service php8.2-fpm restart   # ← 加入這行
```

### Step 4 - 驗收：開啟興隆 Ruth蔣 薪資報表確認
- 04-03：合計應為 900（陳則佑 900，其他 0）
- 04-10：確認四筆 LR 是否為兩個不同時段（若是，各群 800；若同時段 n=4，則 1000）

## 關鍵檔案

- [`backend/app/Http/Controllers/FinanceController.php`](backend/app/Http/Controllers/FinanceController.php)（v1.4 程式碼，行 1465-1482，已正確）
- `/etc/php/8.2/fpm/php-fpm.conf`（php-fpm 設定）