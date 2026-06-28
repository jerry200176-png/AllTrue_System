# Credential Rotation Checklist — Secret Exposure Audit (2026-06-28)

> **Trigger:** GitHub Secret Exposure Audit found live tokens and historical leaks.  
> **Do this BEFORE making the repo public** and **immediately** if the repo was ever shared broadly.

## Phase 0 — Rotate outside git (human / Pi)

| # | Credential | Action | Where |
|---|------------|--------|-------|
| 1 | **Telegram monitor bot** | Revoke via [@BotFather](https://t.me/BotFather) → `/revoke`, issue new token | Pi: `/home/admin/.env.monitor` (never commit) |
| 2 | **GitHub PATs** pasted in agent transcripts | GitHub → Settings → Developer settings → Personal access tokens → **Revoke all unknown** | Assume `ghp_*` in history are compromised |
| 3 | **Campus swipe `Token`** (RFID API bearer) | Regenerate per campus if AdminCampus API was exposed | DB `Campus.Token` or super-admin UI |
| 4 | **Campus `TelegramToken`** | Regenerate via @BotFather per campus bot | DB `Campus.TelegramToken` |
| 5 | **LINE `messaging_channel_secret`** | LINE Developers Console → reissue channel secret per campus | DB `Campus.messaging_channel_secret` |
| 6 | **Telegram webhook `secret_token`** | After deploy: `setWebhook` with new `secret_token`; store in `Campus.TelegramWebhookSecret` | See `backend/docs/telegram_setup.md` |

## Pi monitor env (post-rotation)

```bash
# On Pi only — NOT in git
cat > /home/admin/.env.monitor <<'EOF'
TELEGRAM_BOT_TOKEN=<new-token-from-botfather>
TELEGRAM_CHAT_ID=<your-chat-id>
HEALTH_URL=https://daan.lifenet.com.tw/api/v1/health
DISK_ALERT_PCT=85
TEMP_ALERT_C=70
EOF
chmod 600 /home/admin/.env.monitor
```

## Verification

- [ ] Old Telegram token returns 401 from `api.telegram.org`
- [ ] Revoked GitHub PATs cannot `gh auth status`
- [ ] `git ls-files .env.monitor` returns empty
- [ ] `git ls-files '.cursor/projects/**'` returns empty
- [ ] History purge completed per `scripts/security-filter-repo.sh` (maintenance window)

## References

- [`docs/SECURITY.md`](SECURITY.md) §6 — history rewrite gate before public
- [`scripts/security-filter-repo.sh`](../scripts/security-filter-repo.sh)
- [`scripts/security-gitleaks-audit.sh`](../scripts/security-gitleaks-audit.sh)
- GitHub issues: #975 (campus secret echo), #1021 (Telegram webhook)
