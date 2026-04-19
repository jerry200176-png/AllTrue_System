# 樹莓派 RFID（RC522）讀卡程式

## 檔案

- `GetRFID.py` — IRQ 觸發讀取 UID → 呼叫 `POST /api/v1/swipe-rfid` → 依學生 `TelegramID` / `TelegramID1` / `TelegramID2` 發送 Telegram
- `requirements-rfid.txt` — Python 依賴（於樹莓派上安裝）

## 函式庫

使用 [pi-rc522](https://github.com/ondryaso/pi-rc522)（`pip install pi-rc522`），並需啟用 SPI、`spidev`、樹莓派上的 `RPi.GPIO`。

## 環境變數

| 變數 | 說明 |
|------|------|
| `SWIPE_RFID_URL` | API 完整 URL 或網址基底（會自動加上 `/api/v1/swipe-rfid`） |
| `BRANCH_CODE` | 分校代碼，大安範例：`daan` |
| `CAMPUS_TOKEN` | **必填**：該分校 `Campus.Token`，對應表頭 `Authorization: Bearer …`（**不可**與 `BRANCH_CODE` 對調） |
| `RC522_PIN_IRQ` | 選用，IRQ 之 BOARD 腳位，預設 `18` |
| `RC522_PIN_RST` | 選用，RST 之 BOARD 腳位，預設 `22` |
| `RC522_SPI_BUS` / `RC522_SPI_DEVICE` | 選用，預設 `0` / `0` |
| `RFID_DEBOUNCE_SEC` | 選用，同一卡連續觸發間隔秒數，預設 `2.0` |

## 執行範例

```bash
cd scripts/raspberry-pi
pip install -r requirements-rfid.txt
pip install RPi.GPIO

export SWIPE_RFID_URL="https://daan.lifenet.com.tw"
export BRANCH_CODE="daan"
export CAMPUS_TOKEN="（由資料庫 Campus.Token 取得）"

python3 GetRFID.py
```

若出現 **HTML 404** 或 **invalid_json**：代表請求未到 Laravel，請確認 `SWIPE_RFID_URL` 主機的 Apache `DocumentRoot` 為 `backend/public`；詳見 `docs/api-swipe-rfid.md` 第 12 節。

## systemd（選用）

將環境變數寫入 `/etc/default/alltrue-rfid`，再以 `EnvironmentFile=` 載入後執行 `GetRFID.py`。
