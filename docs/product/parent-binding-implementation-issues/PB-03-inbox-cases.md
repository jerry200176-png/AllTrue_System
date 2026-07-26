# PB-03 — Inbox binding cases

| Field | Value |
|-------|-------|
| Phase / Risk | 1 / T2 |
| Issue | [#1439](https://github.com/jerry200176-png/AllTrue_System/issues/1439) |
| Depends / Blocks | PB-00, PB-02 / PB-06 |
| Board | backlog |

**Scope:** High-signal Inbox types (Architecture); dedupe/cooldown/SLA/deep-link/resolve; staff notify without full phone.  
**Non-scope:** Case per typo; Notification as sole truth; pairing UI.

**AC:** No typo spam; one open case per missing contact; resolve on phone filled or GSR active; campus-scoped; Inbox client compat noted.  
**Tests:** dedupe/cooldown/campus/no phone; leave cases unchanged.  
**Rollback:** `parent_binding_inbox_v1=off`.
