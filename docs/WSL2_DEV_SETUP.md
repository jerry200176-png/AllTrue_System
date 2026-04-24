# WSL2 本地開發環境設定指引

> 目標：在 Windows WSL2 上建立完整開發環境，透過 GitHub Flow + CI/CD 自動部署到樹莓派。

---

## 整體工作流程

```
WSL2（你的電腦）          GitHub                   Raspberry Pi（生產）
────────────────         ──────                   ───────────────────
寫程式                    
git push feature/xxx →   開 PR
                         ci.yml 自動跑測試
你 merge PR         →    CI 通過
                         deploy.yml 自動觸發  →   SSH 拉程式碼 + 部署
```

**你只需要 push，剩下的全自動。**

---

## 第一步：安裝系統依賴

打開 WSL2 終端機（Ubuntu），依序執行：

### PHP 8.2

```bash
sudo apt update && sudo apt upgrade -y

sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.2 php8.2-cli php8.2-mbstring php8.2-pdo \
  php8.2-mysql php8.2-bcmath php8.2-json php8.2-tokenizer \
  php8.2-xml php8.2-ctype php8.2-curl php8.2-zip php8.2-dom

php -v   # 確認顯示 PHP 8.2.x
```

### Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer -V   # 確認顯示 Composer 2.x
```

### Node.js 22

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v   # 確認顯示 v22.x
npm -v
```

### Git

```bash
sudo apt install -y git
git --version
```

---

## 第二步：設定 GitHub SSH 金鑰

```bash
# 生成 SSH 金鑰（已有可略過）
ssh-keygen -t ed25519 -C "你的email@example.com"

# 複製公鑰
cat ~/.ssh/id_ed25519.pub
```

到 [GitHub → Settings → SSH Keys](https://github.com/settings/keys) 貼上公鑰。

驗證：
```bash
ssh -T git@github.com
# 應顯示：Hi <你的username>! You've successfully authenticated...
```

---

## 第三步：Clone 專案

```bash
cd ~
git clone git@github.com:<你的org-or-username>/alltrue.git
cd alltrue
```

---

## 第四步：設定後端

```bash
cd ~/alltrue/backend

# 安裝 PHP 依賴
composer install

# 複製 .env（⚠️ 這是本地開發用的 .env，不要改生產的 .env）
cp .env.example .env.local
```

> **重要**：本地開發不需要連接到生產 DB。後端測試只走 GitHub Actions CI，你在本地**不需要**跑 `php artisan test`。
>
> 如果需要 local DB 測試，使用 Docker：詳見 `docs/DEPLOYMENT.md` §2。

---

## 第五步：設定前端

```bash
cd ~/alltrue/frontend
npm install

# 本地預覽（連接到生產 API，適合 UI 調整）
npm run dev
```

打開瀏覽器 `http://localhost:5173` 即可看到前端。

> **注意**：`npm run dev` 連的是生產 API（`daan.lifenet.com.tw`），只適合 UI 層的開發預覽。
> 涉及後端邏輯改動必須透過 PR + CI 流程驗證。

---

## 第六步：日常開發工作流

### 開始新功能 / 修 Bug

```bash
cd ~/alltrue
git checkout main
git pull origin main          # 永遠從最新 main 開始

git checkout -b feat/功能名稱  # 或 fix/bug名稱
```

### 寫完程式後

```bash
git add .
git commit -m "feat: 簡短描述這次改了什麼"
git push origin feat/功能名稱
```

### 開 PR

到 GitHub 開 Pull Request，目標是 `main`。

GitHub Actions 會自動：
1. 跑 PHPUnit 測試
2. 跑 Vite build 驗證

### 等 CI 綠燈

PR 頁面會顯示 CI 狀態。確認 **全部通過** 後再 merge。

### Merge → 自動部署

Merge 後 `deploy.yml` 自動執行，約 2-3 分鐘後生產環境更新完畢。

可以到 GitHub Actions tab 查看部署進度。

---

## Branch 命名規範

| 類型 | 格式 | 範例 |
|------|------|------|
| 新功能 | `feat/<名稱>` | `feat/makeup-slot-export` |
| Bug 修復 | `fix/<名稱>` | `fix/rfid-null-teacher` |
| 技術債 | `td-batch<N>-<名稱>` | `td-batch2-cache-cleanup` |
| 文件/維護 | `chore/<名稱>` | `chore/update-changelog` |

---

## 常用指令速查

```bash
# 查看目前分支狀態
git status

# 查看最近 commit
git log --oneline -10

# 放棄本地修改（回到上次 commit）
git checkout HEAD -- <檔案路徑>

# 同步最新 main（不切換分支）
git fetch origin main

# 前端本地預覽
cd frontend && npm run dev

# 查看 GitHub Actions 最新狀態（需安裝 gh CLI）
gh run list --limit 5
gh run watch   # 即時追蹤最新 run
```

---

## 安裝 GitHub CLI（選用但推薦）

```bash
sudo apt install -y gh
gh auth login   # 跟著步驟登入
```

有了 `gh` 後可以直接在終端機開 PR、查看 CI 狀態，不用切換到瀏覽器。

---

## ⛔ 絕對禁止

| 禁止操作 | 原因 |
|---------|------|
| `git push --force origin main` | 觸發 deploy.yml，可能覆蓋生產 .env（事故 A）|
| 在 Pi 上跑 `php artisan test` | 會清空生產 DB（事故 C，損失 1h42m 資料）|
| 直接 SSH 到 Pi 改程式碼 | 沒有 CI 保護，等於繞過所有安全網 |
| 在 feature branch 直接改生產設定 | 必須透過 PR + CI 才能上線 |

---

## 驗證部署成功

PR merge 後，在本地或 GitHub Actions 查看 health check 輸出：

```bash
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
# 應顯示 {"status": "ok", ...}
```
