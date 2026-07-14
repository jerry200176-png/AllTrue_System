#!/usr/bin/env bash
# =============================================================================
# 🔴 P0：AllTrue Pi 主機強化 — UFW / SSH / fail2ban / 服務停用
# Issue: #887
# 參考: docs/SECURITY.md §2（FIND-001, FIND-002, FIND-010, FIND-011, FIND-008）
# 日期：2026-07-13
# =============================================================================
set -euo pipefail

echo "============================================"
echo " AllTrue Pi 主機強化"
echo " 執行時間：$(TZ=Asia/Taipei date '+%Y-%m-%d %H:%M:%S')"
echo " 主機：$(hostname)"
echo "============================================"
echo ""

VERIFICATION_FAILED=0

# ── Step 0: 檢查 sudo ──
if ! sudo -n true 2>/dev/null; then
    echo "❌ 需要 sudo 權限，無法繼續。"
    exit 1
fi
echo "✅ sudo 權限確認"
echo ""

# ════════════════════════════════════════════
# Step 1: UFW 防火牆
# ════════════════════════════════════════════
echo "── [1/5] UFW 防火牆設定 ──"

if ! command -v ufw &>/dev/null; then
    echo "安裝 UFW..."
    sudo apt-get update -qq && sudo apt-get install -y -qq ufw
fi

sudo ufw --force enable
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp
sudo ufw deny 1883/tcp
sudo ufw deny 8883/tcp
sudo ufw deny 5432/tcp
sudo ufw deny 11434/tcp
sudo ufw deny 5678/tcp
sudo ufw deny 445/tcp
sudo ufw deny 139/tcp
sudo ufw reload

echo "UFW 狀態："
sudo ufw status numbered
echo ""

if sudo ufw status | grep -q 'Status: active'; then
    echo "✅ UFW 已啟用"
else
    echo "❌ UFW 未啟用！"
    VERIFICATION_FAILED=1
fi
echo ""

# ════════════════════════════════════════════
# Step 2: SSH 強化
# ════════════════════════════════════════════
echo "── [2/5] SSH 強化 ──"

sudo cp /etc/ssh/sshd_config /etc/ssh/sshd_config.bak.$(date +%Y%m%d)

sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo sed -i 's/^#\?PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config
sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config

sudo systemctl restart sshd

echo "SSH 設定驗證："
sudo sshd -T | grep -E 'passwordauthentication|pubkeyauthentication|permitrootlogin'
echo ""

if sudo sshd -T | grep -qi 'passwordauthentication no'; then
    echo "✅ SSH 密碼登入已關閉"
else
    echo "❌ SSH 密碼登入仍啟用！"
    VERIFICATION_FAILED=1
fi
echo ""

# ════════════════════════════════════════════
# Step 3: fail2ban
# ════════════════════════════════════════════
echo "── [3/5] fail2ban 安裝與設定 ──"

if ! command -v fail2ban-client &>/dev/null; then
    echo "安裝 fail2ban..."
    sudo apt-get update -qq && sudo apt-get install -y -qq fail2ban
fi

sudo tee /etc/fail2ban/jail.local > /dev/null <<'EOF'
[sshd]
enabled = true
port = ssh
maxretry = 3
bantime = 3600
findtime = 600
EOF

sudo systemctl restart fail2ban
sudo systemctl enable fail2ban

echo "fail2ban 狀態："
sudo fail2ban-client status sshd 2>/dev/null || echo "（fail2ban sshd jail 未啟動）"
echo ""

if sudo fail2ban-client status sshd 2>/dev/null | grep -q 'Jail list'; then
    echo "✅ fail2ban sshd jail 已啟用"
else
    echo "❌ fail2ban sshd jail 未啟用！"
    VERIFICATION_FAILED=1
fi
echo ""

# ════════════════════════════════════════════
# Step 4: 停用不需要的服務
# ════════════════════════════════════════════
echo "── [4/5] 停用不需要的服務 ──"

if systemctl is-active smbd &>/dev/null 2>&1; then
    sudo systemctl disable --now smbd
    echo "✅ smbd 已停用"
else
    echo "ℹ️  smbd 未運行"
fi

if systemctl is-active nmbd &>/dev/null 2>&1; then
    sudo systemctl disable --now nmbd
    echo "✅ nmbd 已停用"
else
    echo "ℹ️  nmbd 未運行"
fi

if systemctl is-active mosquitto &>/dev/null 2>&1; then
    sudo systemctl disable --now mosquitto
    echo "✅ mosquitto 已停用"
else
    echo "ℹ️  mosquitto 未運行"
fi

if [ -d /etc/systemd/system/ollama.service.d ] && [ -f /etc/systemd/system/ollama.service.d/override.conf ]; then
    echo "ℹ️  Ollama override 已存在"
else
    sudo mkdir -p /etc/systemd/system/ollama.service.d
    echo -e '[Service]\nEnvironment="OLLAMA_HOST=127.0.0.1"' | sudo tee /etc/systemd/system/ollama.service.d/override.conf > /dev/null
    sudo systemctl daemon-reload
    if systemctl is-active ollama &>/dev/null 2>&1; then
        sudo systemctl restart ollama
    fi
    echo "✅ Ollama 已限制為 localhost only"
fi

if [ -d /home/admin/.vnc ]; then
    rm -rf /home/admin/.vnc
    echo "✅ .vnc 殘留已清除"
else
    echo "ℹ️  無 .vnc 殘留"
fi

echo ""

# ════════════════════════════════════════════
# Step 5: 最終驗收
# ════════════════════════════════════════════
echo "── [5/5] 最終驗收 ──"
echo ""

echo "=== UFW 防火牆 ==="
sudo ufw status verbose
echo ""

echo "=== SSH 設定 ==="
echo "密碼登入：$(sudo sshd -T 2>/dev/null | grep passwordauthentication)"
echo "金鑰登入：$(sudo sshd -T 2>/dev/null | grep pubkeyauthentication)"
echo "Root 登入：$(sudo sshd -T 2>/dev/null | grep permitrootlogin)"
echo ""

echo "=== fail2ban ==="
sudo fail2ban-client status sshd 2>/dev/null || echo "fail2ban sshd jail 未啟動"
echo ""

echo "=== 對外監聽 port（排除 localhost）==="
sudo ss -tlnp | grep -v '127.0.0.1\|::1' || echo "（僅 localhost 監聽 ✅）"
echo ""

# ── 彙總 ──
echo "============================================"
if [ "$VERIFICATION_FAILED" -eq 0 ]; then
    echo "✅ 所有強化步驟完成，驗收通過！"
    echo "============================================"
    echo ""
    echo "已完成的防護："
    echo "  🔒 UFW 防火牆 — 僅開放 80/443/22"
    echo "  🔒 SSH 金鑰登入 — 密碼登入已關閉"
    echo "  🔒 fail2ban — 3 次失敗鎖 1 小時"
    echo "  🔒 Samba / Mosquitto — 已停用"
    echo "  🔒 VNC 殘留 — 已清除"
    echo "  🔒 Ollama — localhost only"
else
    echo "⚠️  部分驗收未通過，請檢查上方輸出！"
    echo "============================================"
    exit 1
fi
