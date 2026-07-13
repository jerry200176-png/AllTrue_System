#!/bin/bash
# ============================================================
# AllTrue 主機強化腳本（#887）
# 執行順序：UFW → SSH → fail2ban → VNC 清理 → 服務停用
# 設計原則：每一步皆前向安全 — SSH (22) 在所有步驟前確保放行
# ============================================================
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

CRITICALS=0
WARNINGS=0

log_section() { echo -e "\n${GREEN}=== [$1] ===${NC}"; }
log_ok()     { echo -e "  ${GREEN}✅${NC} $1"; }
log_warn()   { echo -e "  ${YELLOW}⚠️${NC}  $1"; WARNINGS=$((WARNINGS + 1)); }
log_fail()   { echo -e "  ${RED}❌${NC} $1"; CRITICALS=$((CRITICALS + 1)); }

# ── 前置檢查 ──────────────────────────────────────────────
log_section "0/6 前置檢查"

if [ "$(id -u)" -ne 0 ] && ! sudo -n true 2>/dev/null; then
  log_fail "需要 sudo 權限（無密碼），請確認 NOPASSWD 設定"
  exit 1
fi
log_ok "sudo 權限正常"

# ── 1. UFW 防火牆 ─────────────────────────────────────────
log_section "1/6 UFW 防火牆啟用"

if ! command -v ufw &>/dev/null; then
  log_warn "ufw 未安裝，嘗試安裝..."
  sudo apt-get update -qq && sudo apt-get install -y -qq ufw || log_fail "無法安裝 ufw"
fi

UFW_STATUS=$(sudo ufw status 2>/dev/null | head -1 || echo "inactive")
if echo "$UFW_STATUS" | grep -q "active"; then
  log_warn "UFW 已為 active，顯示目前規則："
  sudo ufw status numbered
else
  log_ok "UFW 目前 inactive，開始設定..."
  sudo ufw allow 22/tcp
  sudo ufw allow 80/tcp
  sudo ufw allow 443/tcp
  sudo ufw deny 1883/tcp 2>/dev/null || true
  sudo ufw deny 8883/tcp 2>/dev/null || true
  sudo ufw deny 5432/tcp 2>/dev/null || true
  sudo ufw deny 11434/tcp 2>/dev/null || true
  sudo ufw deny 5678/tcp 2>/dev/null || true
  sudo ufw deny 445/tcp 2>/dev/null || true
  sudo ufw deny 139/tcp 2>/dev/null || true
  sudo ufw default deny incoming
  sudo ufw default allow outgoing
  sudo ufw --force enable
  sudo ufw reload
  if sudo ufw status | grep -q "active"; then
    log_ok "UFW 已啟用"
    sudo ufw status numbered
  else
    log_fail "UFW 啟用失敗！"
  fi
fi

# ── 2. SSH 強化 ───────────────────────────────────────────
log_section "2/6 SSH 強化"

SSHD_CONFIG="/etc/ssh/sshd_config"
BACKUP_DATE=$(date +%Y%m%d)
if [ ! -f "${SSHD_CONFIG}.bak-${BACKUP_DATE}" ]; then
  sudo cp "$SSHD_CONFIG" "${SSHD_CONFIG}.bak-${BACKUP_DATE}"
  log_ok "sshd_config 已備份"
fi

CURRENT_PASS_AUTH=$(sudo sshd -T 2>/dev/null | grep "^passwordauthentication" | awk '{print $2}' || echo "unknown")
if [ "$CURRENT_PASS_AUTH" = "no" ]; then
  log_ok "PasswordAuthentication 已為 no"
else
  log_warn "PasswordAuthentication 為 $CURRENT_PASS_AUTH，修改為 no..."
  sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' "$SSHD_CONFIG"
  sudo sed -i 's/^#\?PubkeyAuthentication.*/PubkeyAuthentication yes/' "$SSHD_CONFIG"
  sudo sed -i 's/^#\?ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' "$SSHD_CONFIG"

  if sudo sshd -t 2>/dev/null; then
    log_ok "sshd_config 語法通過，重啟 sshd..."
    sudo systemctl restart sshd
    sleep 2
    VERIFY=$(sudo sshd -T 2>/dev/null | grep "^passwordauthentication" | awk '{print $2}')
    if [ "$VERIFY" = "no" ]; then
      log_ok "PasswordAuthentication 已成功設為 no"
    else
      log_fail "PasswordAuthentication 驗證失敗（$VERIFY）"
    fi
  else
    log_fail "sshd_config 語法檢查失敗，還原備份..."
    sudo cp "${SSHD_CONFIG}.bak-${BACKUP_DATE}" "$SSHD_CONFIG"
    sudo systemctl restart sshd
  fi
fi

# 變更使用者密碼
log_section "2b/6 變更使用者密碼（admin, jeng）"
for USER in admin jeng; do
  if id "$USER" &>/dev/null; then
    NEW_PASS=$(openssl rand -base64 16 2>/dev/null || head -c 16 /dev/urandom | base64)
    echo "${USER}:${NEW_PASS}" | sudo chpasswd 2>/dev/null && {
      log_ok "已變更 ${USER} 密碼"
      sudo bash -c "echo '${USER}: rotated $(date -Iseconds)' >> /root/credential-rotation-${BACKUP_DATE}.txt"
      sudo chmod 600 "/root/credential-rotation-${BACKUP_DATE}.txt"
    } || log_warn "無法變更 ${USER} 密碼"
  else
    log_warn "使用者 ${USER} 不存在"
  fi
done

# ── 3. fail2ban ────────────────────────────────────────────
log_section "3/6 fail2ban 設定"

if ! command -v fail2ban-client &>/dev/null; then
  log_warn "fail2ban 未安裝，嘗試安裝..."
  sudo apt-get update -qq && sudo apt-get install -y -qq fail2ban || log_fail "無法安裝 fail2ban"
fi

if command -v fail2ban-client &>/dev/null; then
  sudo tee /etc/fail2ban/jail.local > /dev/null <<'EOF'
[sshd]
enabled = true
port = ssh
maxretry = 3
bantime = 3600
findtime = 600
EOF
  sudo systemctl restart fail2ban
  sleep 2
  if sudo fail2ban-client status 2>/dev/null | grep -q "sshd"; then
    log_ok "fail2ban sshd jail 已啟用"
  else
    log_warn "無法確認 fail2ban sshd jail 狀態"
  fi
fi

# ── 4. VNC 殘留清理 ───────────────────────────────────────
log_section "4/6 VNC 殘留清理"

for HOME_DIR in /home/admin /home/jeng /root; do
  if [ -d "${HOME_DIR}/.vnc" ]; then
    rm -rf "${HOME_DIR}/.vnc"
    log_ok "已移除 ${HOME_DIR}/.vnc"
  else
    log_ok "${HOME_DIR}/.vnc 不存在"
  fi
  if [ -f "${HOME_DIR}/.gitignore" ]; then
    if ! grep -qx '\.vnc/' "${HOME_DIR}/.gitignore" 2>/dev/null; then
      echo '.vnc/' >> "${HOME_DIR}/.gitignore"
      log_ok "已將 .vnc/ 加入 ${HOME_DIR}/.gitignore"
    fi
  fi
done

# ── 5. 停用不需要的服務 ────────────────────────────────────
log_section "5/6 停用不需要的服務"

for SVC in smbd nmbd mosquitto; do
  if systemctl list-unit-files "${SVC}.service" 2>/dev/null | grep -q "${SVC}.service"; then
    sudo systemctl disable --now "$SVC" 2>/dev/null && \
      log_ok "已停用 $SVC" || log_warn "無法停用 $SVC"
  else
    log_ok "$SVC 未安裝或已停用"
  fi
done

# ── 6. Ollama 限制 localhost ───────────────────────────────
log_section "6/6 Ollama 限制 localhost"

if systemctl list-unit-files ollama.service 2>/dev/null | grep -q "ollama.service"; then
  sudo mkdir -p /etc/systemd/system/ollama.service.d
  printf '[Service]\nEnvironment="OLLAMA_HOST=127.0.0.1"\n' | sudo tee /etc/systemd/system/ollama.service.d/override.conf > /dev/null
  sudo systemctl daemon-reload
  sudo systemctl restart ollama
  log_ok "Ollama 已限制為 127.0.0.1"
else
  log_ok "Ollama 未安裝"
fi

# ── 驗收檢查 ──────────────────────────────────────────────
log_section "=== 驗收檢查 ==="

echo ""
echo "1. UFW 狀態："
sudo ufw status 2>/dev/null | head -5 || echo "  ufw 不可用"

echo ""
echo "2. SSH PasswordAuthentication："
sudo sshd -T 2>/dev/null | grep -E "passwordauthentication|pubkeyauthentication" || echo "  sshd 不可用"

echo ""
echo "3. fail2ban sshd jail："
sudo fail2ban-client status sshd 2>/dev/null || echo "  fail2ban 不可用"

echo ""
echo "4. VNC 殘留："
for d in /home/admin/.vnc /home/jeng/.vnc; do
  if [ -d "$d" ]; then echo "  ❌ $d 仍存在"; else echo "  ✅ $d 已移除"; fi
done

echo ""
echo "5. 對外監聽 port："
sudo ss -tlnp 2>/dev/null | grep -v "127.0.0.1\|::1" || echo "  （無異常對外監聽）"

echo ""
echo "6. 不需要的服務狀態："
for SVC in smbd nmbd mosquitto; do
  STATUS=$(systemctl is-active "$SVC" 2>/dev/null || echo "not-found")
  if [ "$STATUS" = "inactive" ] || [ "$STATUS" = "not-found" ]; then
    echo "  ✅ $SVC: $STATUS"
  else
    echo "  ❌ $SVC: $STATUS"
  fi
done

echo ""
echo "7. Ollama 綁定："
if systemctl is-active ollama 2>/dev/null | grep -q "active"; then
  if sudo ss -tlnp 2>/dev/null | grep ollama | grep -q "127.0.0.1"; then
    echo "  ✅ Ollama 綁定 localhost"
  else
    echo "  ❌ Ollama 未綁定 localhost"
  fi
else
  echo "  ✅ Ollama 未執行"
fi

# ── 總結 ──────────────────────────────────────────────────
echo ""
echo "============================================"
if [ "$CRITICALS" -gt 0 ]; then
  echo -e "${RED}❌ 強化完成，但有 ${CRITICALS} 項 CRITICAL 問題${NC}"
  exit 1
else
  echo -e "${GREEN}✅ 主機強化完成${NC}"
  [ "$WARNINGS" -gt 0 ] && echo -e "${YELLOW}   ${WARNINGS} 項警告（不影響驗收）${NC}"
fi
echo "============================================"
