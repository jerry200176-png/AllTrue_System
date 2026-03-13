# 資料庫建立說明（Database Setup Guide）

本專案支援三種資料庫使用方式，請依需求選擇。

---

## 方式一：Docker 一鍵部署（推薦，使用 PostgreSQL）

最簡單的方式，Docker 會自動建立資料庫。

### 前置需求
- 安裝 [Docker Desktop](https://www.docker.com/products/docker-desktop/)

### 步驟

```powershell
# 1. 在專案根目錄執行（會自動建立 PostgreSQL 資料庫）
docker-compose up -d --build

# 2. 執行資料庫遷移（建立資料表）
docker-compose exec app php artisan migrate --force

# 3. 執行 Seed（灌入初始資料）
docker-compose exec app php artisan db:seed --force
```

資料庫設定已在 `backend/.env` 中配置好：
- 資料庫名稱：`alltrue`
- 帳號：`alltrue`
- 密碼：`secret`
- 連接埠：`5432`

> [!TIP]
> 資料會持久保存在 `./data/postgres/` 目錄，重啟 Docker 不會遺失。

---

## 方式二：匯入 MySQL 資料庫（舊系統資料）

若需使用**原始 MySQL/MariaDB 資料庫**，SQL 匯出檔案在專案根目錄：

📄 **`AllTrue (3).sql`**

### 前置需求
- 安裝 [MySQL](https://dev.mysql.com/downloads/installer/) 或 [XAMPP](https://www.apachefriends.org/)

### 步驟

#### 1. 建立資料庫

打開 MySQL 命令列（或 phpMyAdmin）：

```sql
-- 建立資料庫
CREATE DATABASE AllTrue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 建立使用者（選用，可用 root）
CREATE USER 'alltrue'@'localhost' IDENTIFIED BY '你的密碼';
GRANT ALL PRIVILEGES ON AllTrue.* TO 'alltrue'@'localhost';
FLUSH PRIVILEGES;
```

#### 2. 匯入 SQL 檔案

**方法 A — 命令列：**
```bash
mysql -u root -p AllTrue < "AllTrue (3).sql"
```

**方法 B — phpMyAdmin：**
1. 開啟 phpMyAdmin（XAMPP 預設 http://localhost/phpmyadmin）
2. 點選左邊「新增」建立資料庫 `AllTrue`
3. 點進 `AllTrue` 資料庫
4. 點上方「匯入」分頁
5. 選擇 `AllTrue (3).sql` 檔案
6. 點「執行」

#### 3. 修改 Laravel 後端設定

編輯 `backend/.env`，將資料庫設定改為：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=AllTrue
DB_USERNAME=root
DB_PASSWORD=你的密碼
```

> [!IMPORTANT]
> 記得將 `DB_SSLMODE=prefer` 那行刪除或註解掉，MySQL 不需要此設定。

---

## 方式三：本機開發（使用 SQLite，免安裝資料庫）

`backend-local/` 目錄包含一個獨立的本機開發伺服器，使用 SQLite，**免安裝任何資料庫**。

```powershell
cd backend-local
npm install
node server.js
```

資料儲存在 `backend-local/alltrue.db`。

---

## SQL 檔案說明

| 檔案 | 說明 |
|------|------|
| `AllTrue (3).sql` | 完整的 MySQL 資料庫匯出（含資料表結構 + 資料） |
| `scripts/list-users.sql` | 查詢使用者列表的 SQL |
| `scripts/promote-super-admin.sql` | 提升超級管理員的 SQL |

---

## 資料表一覽

`AllTrue (3).sql` 包含以下資料表：

| 資料表 | 說明 |
|--------|------|
| `BaseData` | 基礎資料（年級、課程、一對X、上課時數等） |
| `Campus` | 分校資料 |
| `Student` | 學生基本資料 |
| `StudentClass` | 學生課程 |
| `StudentSingIn` | 學生簽到記錄 |
| `Teacher` | 老師基本資料 |
| `TeacherSchedule` | 老師周排程 |
| `TeacherSingIn` | 老師簽到記錄 |
| `LineBot` | LINE Bot 設定 |
| `Subject` | 科目 |

---

## 常見問題

**Q: 找不到 SQL 檔案？**
A: `AllTrue (3).sql` 在專案最上層根目錄。

**Q: 匯入時出現編碼錯誤？**
A: 確保資料庫使用 `utf8mb4` 編碼。

**Q: Docker 和 MySQL 要選哪個？**
A: 新開發建議用 **Docker（方式一）**，自動處理一切。若需要使用舊系統的真實資料，用**方式二**匯入 MySQL。
