---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-08-08
---

# Production Host Hardening Verification

> Addresses [#887](https://github.com/jerry200176-png/AllTrue_System/issues/887). Read-only audit of `pi.lifenet.com.tw`, 2026-08-08. **No host configuration was changed** — this is findings + recommendations only, per this repo's own R6 rule (no direct production config changes outside PR/CI).

## ⚠️ Most urgent finding: services bound to all interfaces (`0.0.0.0`), not just localhost

```
0.0.0.0:445    ← SMB/CIFS (Windows file sharing)
0.0.0.0:139    ← NetBIOS (SMB-related)
0.0.0.0:1883   ← MQTT (unencrypted)
0.0.0.0:8883   ← MQTT over TLS
0.0.0.0:9993   ← ZeroTier
0.0.0.0:5678   ← unidentified (n8n default port, or similar)
0.0.0.0:3000   ← unidentified (commonly Grafana/dev server)
0.0.0.0:51821  ← unidentified (commonly wg-easy WireGuard admin UI)
0.0.0.0:2222   ← alternate SSH
*:11434        ← Ollama (local LLM server)
*:80, *:443    ← expected (the web app)
*:22           ← expected (SSH, used by deploy.yml)
127.0.0.1:3306 ← MySQL — correctly localhost-only
127.0.0.1:631  ← CUPS — correctly localhost-only
```

**Why this matters**: MySQL and CUPS are correctly scoped to localhost, which shows someone already applied that discipline somewhere — but SMB/NetBIOS, MQTT, Ollama, and several unidentified services are bound to all interfaces on the same box that serves this production education app. Whether these are actually internet-reachable depends on router-level port forwarding, which **cannot be verified from inside the host** — this audit only confirms what the host itself is listening on, not what's reachable from outside. That verification (checking the router/NAT config, or running an external port scan against the public IP) is the concrete next step and needs to happen from outside this network.

**Not touched, not guessed at**: I did not identify what's running on 3000/5678/51821/9993/2222 beyond common-default guesses — flagging as unidentified rather than asserting.

## Other findings

| Check | Result |
|---|---|
| `ufw` | Not installed. No host-level firewall confirmed active via ufw; iptables rules not checked (would need root) |
| `fail2ban` | **Active** — SSH brute-force protection is running |
| OS version | Debian 12 (bookworm) |
| MySQL bind | `127.0.0.1:3306` only — correct |

## Owner decisions needed

- [ ] Confirm from **outside** the network (e.g. `nmap` against the public IP, or check router port-forward rules) which of the non-web/SSH ports are actually internet-reachable — this audit can't answer that from inside.
- [ ] For each internet-reachable non-essential service (SMB especially — Windows file sharing on a public-facing Linux box is unusual and worth explaining or closing): either firewall it to localhost/LAN-only, or document why it's intentionally exposed.
- [ ] Identify the services on 3000/5678/51821/9993/2222 and confirm each is intentional.
- [ ] Consider `ufw` (or equivalent) as a host-level firewall layer — currently absent; `fail2ban` alone only protects against repeated auth failures, not an open port with no auth at all.

## Acceptance against #887

- [x] UFW/SSH/fail2ban/service-exposure audit — done, findings above.
- [x] Findings documented, not silently fixed (matches R6 — production config changes need PR/CI, not ad hoc SSH edits).
- [ ] Remediation — **explicitly not done this pass**; needs owner confirmation on external reachability first, since closing a port that's actually load-bearing (e.g. ZeroTier for remote access) would be a self-inflicted lockout.
