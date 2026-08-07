# Production host hardening audit — 2026-08-07 (#887)

> Read-only SSH audit. **No host configuration was changed.** This is findings + recommendations only — closing any of these gaps is an owner decision given production blast radius.

## Findings

### 1. No firewall installed — **highest-priority finding**

`ufw` is not installed on the Pi. There is no host-level firewall observed. Combined with the port scan below, several services are reachable from the network with no firewall layer in front of them (only whatever access control each service implements itself, if any).

### 2. Listening ports (`ss -tln`) bound to `0.0.0.0` / `[::]` (not localhost-only)

| Port | Likely service | Concern |
|---|---|---|
| 22, 2222 | SSH (two listeners) | Expected, but confirm 2222 is intentional (second SSH listener is unusual — verify it's not a leftover/shadow access path) |
| 80, 443 | Web (app + likely reverse proxy) | Expected |
| 445, 139 | **SMB / NetBIOS** | Unexpected on an app server. If not actively used, this is attack surface that should be disabled. |
| 8883, 1883 | MQTT (TLS/plain) | Likely IoT/RFID-adjacent (`docs/api-swipe-rfid.md` mentions device integration) — confirm intended, restrict to known device IPs if possible |
| 5678 | Likely **n8n** (workflow automation) or similar | Another automation tool on production, similar category of concern to #1128/#1676 (Hermes) — worth identifying who owns this |
| 51821 | Likely WireGuard-UI or similar VPN admin panel | Admin panels on 0.0.0.0 are a common compromise vector if not otherwise protected |
| 9993 | Possibly ZeroTier | Confirm intended |
| 11434 | **Ollama** (local LLM server) | Ollama has no auth by default — exposed on 0.0.0.0 this allows anyone who can reach the port to use (or potentially abuse) the host's compute/models |
| 3000 | Node app (Grafana default port, or a dashboard — possibly Hermes-related, see #1676) | Identify owner/purpose |
| 631 (loopback only), 3306 (loopback only) | CUPS, MySQL | Correctly localhost-only — no action needed |

**None of the above were disabled or reconfigured by this audit.** Each needs a human who knows what's actually using them before anything is closed — several may be legitimate (RFID/MQTT, the colleague's automation tools) and shutting them blind could break something in production.

### 3. fail2ban

Active and running — good baseline protection against brute-force SSH attempts.

### 4. Could not verify (needs sudo access this session's key doesn't have)

- `sshd_config` hardening (`PermitRootLogin`, `PasswordAuthentication`, `PubkeyAuthentication`)
- `unattended-upgrades` — the systemd unit was not found by name; auto security-patching status is unconfirmed, not confirmed-absent (may be handled by a different mechanism, e.g. Raspberry Pi OS's own update path)

## Recommendations (owner decision required before any action)

1. **Install and configure `ufw`**, default-deny inbound except 22/2222(if intended)/80/443/RFID-relevant ports, once each open port's owner/purpose is confirmed.
2. **Identify the owner of ports 445/139 (SMB), 5678, 51821, 9993, 3000, 11434** — likely candidates: the colleague's Hermes/automation tooling (#1676) and RFID device management. Do not disable blind.
3. **Restrict or firewall Ollama (11434)** at minimum to localhost if it's only used by local tooling — exposed LLM inference endpoints are a real cost/abuse vector.
4. Get sudo access to confirm SSH hardening settings in a follow-up pass.
