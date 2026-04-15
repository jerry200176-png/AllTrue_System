# 效能優化上線操作手冊

> 對應計畫：`mobile-learning-lag-fix`

## 變更摘要

| 項目 | 舊值 | 新值 | 回退方式 |
|------|------|------|----------|
| badge 輪詢間隔 | 25s (chat/bug), 60s (notifications) | 60s 統一，背景頁暫停 | `perfFlags.js` → `BADGE_POLL_INTERVAL: 25000`, `PAUSE_POLLING_ON_HIDDEN: false` → rebuild |
| 評量頁 per_page | 200 | 50（含載入更多） | `.env` `PERF_LR_DEFAULT_PER_PAGE=200` |
| 學生列表 per_page | 500 | 200 | `perfFlags.js` → `STUDENTS_PER_PAGE: 500` → rebuild |
| class-sessions per_page | 2000 | 500 | `perfFlags.js` → `SESSION_MAX_PER_PAGE: 2000` → rebuild |
| 通知 sync | 每次 unread-count 都 sync | 每分校 5min 內只 sync 一次 | `.env` `PERF_THROTTLE_NOTIF_SYNC=false` |
| 老師名稱查詢 | N+1 per record | batch load | 無需回退（純優化） |
| 手機 backdrop-filter | 啟用 | 640px 以下停用 | 移除 `styles.css` 中 `MOBILE PERF RELIEF` 區塊 → rebuild |
| DB indexes | 無 | 4 組複合索引 | `php artisan migrate:rollback --step=1` |

## 後端回退步驟（5 分鐘內）

```bash
# 1. 改 .env 回退通知 sync 與 per_page
echo "PERF_THROTTLE_NOTIF_SYNC=false" >> /home/admin/backend/.env
echo "PERF_LR_DEFAULT_PER_PAGE=200" >> /home/admin/backend/.env

# 2. 清設定快取
cd /home/admin/backend && php artisan config:clear

# 3. 如需回退索引
cd /home/admin/backend && php artisan migrate:rollback --step=1
```

## 前端回退步驟

```bash
# 1. 修改 perfFlags.js 回到舊值
# 2. 重新部署
cd /home/admin/frontend && npm run deploy
```

## SLO 監控

### 日誌位置
- 效能日誌：`/home/admin/backend/storage/logs/perf-*.log`
- 慢請求：`/home/admin/backend/storage/logs/laravel.log`（搜尋 `[slow-request]`）
- SLO 違規：`/home/admin/backend/storage/logs/perf-*.log`（搜尋 `[slo-breach]`）

### SLO 門檻
| 端點 | P95 目標 | P99 上限 | error rate |
|------|----------|----------|------------|
| `GET /api/v1/learning-records` | ≤ 1200ms | ≤ 2000ms | < 0.5% |
| `GET /api/v1/notifications/unread-count` | ≤ 300ms | ≤ 600ms | < 0.5% |
| `GET /api/v1/class-sessions` | ≤ 800ms | ≤ 1500ms | < 0.5% |

### 快速查詢 SLO 狀態
```bash
# 最近 1 小時 SLO 違規次數
grep -c 'slo-breach' /home/admin/backend/storage/logs/perf-$(date +%Y-%m-%d).log 2>/dev/null || echo 0

# 最近 1 小時慢請求
grep -c 'slow-request' /home/admin/backend/storage/logs/laravel.log 2>/dev/null || echo 0

# API 健康狀態
curl -s localhost/api/v1/health | python3 -m json.tool
```

## 前端效能查看

在瀏覽器 Console 中：
```js
// 查看效能歷史
JSON.parse(sessionStorage.getItem('__alltrue_perf_logs') || '[]')

// 開啟詳細模式
window.__ALLTRUE_PERF_DEBUG = true
```

## UAT 驗收清單

### 預設條件
- [ ] 至少 4 台真機（iPhone Safari ×2, Android Chrome ×2）
- [ ] 含 Wi-Fi 與 4G 網路環境

### 測試項目
- [ ] 老師登入 → 進入評量頁 → 首屏載入無明顯卡頓
- [ ] 切換 tab（待審/需修改/已核准）反應靈敏
- [ ] 展開學生群組 → 滑動列表流暢
- [ ] 開啟評量表單 → 填寫 → 送出成功
- [ ] 「載入更多」按鈕正常運作
- [ ] badge 紅點在可接受延遲內更新（≤ 60s）
- [ ] 切到背景頁再切回，數據自動重新整理
- [ ] 評量待審邏輯不回歸
- [ ] 請假過濾不回歸
- [ ] 主任：科目數統計不受影響

### Go / No-Go 條件
- **Go**：卡頓回報下降 ≥ 50%，無核心回歸
- **No-Go**：任一核心回歸（評量待審/請假過濾/核准扣堂/送出），或 SLO 30 分鐘持續超標

### 簽核
- [ ] 主任簽核「可接受」  日期：________
- [ ] 老師簽核「可接受」  日期：________
- [ ] 連續兩個尖峰時段觀測通過  日期：________
- [ ] 回退演練完成（5 分鐘內恢復）  日期：________
