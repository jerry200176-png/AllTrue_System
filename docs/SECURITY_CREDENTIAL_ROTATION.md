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

- [x] Old Telegram token returns 401 from `api.telegram.org`
- [x] Revoked GitHub PATs cannot `gh auth status`
- [ ] `git ls-files .env.monitor` returns empty (after PR #1023 merge)
- [ ] `git ls-files '.cursor/projects/**'` returns empty (after PR #1023 merge)
- [ ] History purge completed per `scripts/security-filter-repo.sh` (maintenance window)

## CEO sign-off (2026-06-28)

| Item | Status | Date |
|------|--------|------|
| Telegram monitor bot revoked/reissued via BotFather | **CONFIRMED** | 2026-06-28 |
| GitHub PATs from agent transcripts revoked | **CONFIRMED** | 2026-06-28 |
| Campus swipe / LINE / Telegram DB secrets reviewed | **CONFIRMED** | 2026-06-28 |
| Pi live config at `/home/admin/.env.monitor` only | **CONFIRMED** | 2026-06-28 |

Signed off by CEO for publication remediation sequence. History purge and gitleaks gate still required before public toggle.

## References

- [`docs/SECURITY.md`](SECURITY.md) §6 — history rewrite gate before public
- [`scripts/security-filter-repo.sh`](../scripts/security-filter-repo.sh)
- [`scripts/security-gitleaks-audit.sh`](../scripts/security-gitleaks-audit.sh)
- GitHub issues: #975 (campus secret echo), #1021 (Telegram webhook)
