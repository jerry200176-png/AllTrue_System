# Privacy request SOP: access, export, delete, archive (#903)

> Standard practice reference: this follows the shape of a GDPR-style Data Subject Access Request (DSAR) process — request intake, identity verification, data-map lookup, timeboxed response, documented outcome — adapted to AllTrue's single-operator scale. Not a legal opinion; if a request looks contentious or ambiguous, escalate to the Founder before acting.

## When this applies

A parent, student (if of appropriate age), or staff member asks — via any channel (in-app bug report, LINE, email, in person) — to: see what data AllTrue holds on them, get a copy, correct it, or delete/archive it.

## Intake

1. Log the request as an in-app bug report (severity `medium`, or `high` if the requester indicates urgency) even if it arrived elsewhere (LINE/email) — this repo's convention is that all trackable staff-facing work flows through the bug/issue system (`docs/CHAT_BUG_SYSTEM.md`).
2. Record: who is asking, what they're asking for (access/export/correction/deletion), and which student/family it concerns.

## Identity verification (before touching any data)

- For a parent: verify via the same standard already used for LINE binding (name + student ID/number + registered phone) — see `CLAUDE.md`'s parent cross-student isolation notes and `docs/security/PARENT_BINDING_THREAT_MODEL.md`. Do not act on an unverified request.
- For a staff member: verify via existing account/role.

## Locate the data

Use `docs/security/PII_DATA_INVENTORY.md` (#889) as the starting map of which tables to check for a given data subject.

## Handling by request type

| Type | Action | Notes |
|---|---|---|
| **Access / "what do you have on us"** | Query the relevant tables (read-only), summarize in plain language (follow `.cursor/rules/user-facing-communication.mdc` — white-language rules, no field/table names in the reply) | No code change needed |
| **Export** | Same as access, formatted as a document/CSV handed to the requester | Do not email/LINE raw DB exports — sanitize to only the requester's own data |
| **Correction** | Route to the normal data-correction path for that domain (e.g., billing corrections go through `DIRECTOR_PAYMENT_ALERT_RULES.md`'s "no silent AI edits to production accounting" rule — a director/super_admin makes the actual change) | Do not let an AI agent directly UPDATE production PII rows from a privacy request |
| **Deletion / archive** | **Founder-gated.** A minor's enrollment/billing records likely have a statutory retention floor (see #889's retention column) — deletion cannot simply mean `DELETE FROM`. Escalate; do not delete unilaterally. | This is the highest-risk request type — treat as owner-only regardless of who is executing the SOP |

## Timebox

Recommend acknowledging within 3 business days and resolving/responding within 30 days (standard DSAR-style default) — adjust once the Founder sets an official SLA; not enforced by tooling yet.

## Recordkeeping

Log the outcome (what was found/exported/corrected/why deletion was or wasn't possible) as a comment on the tracking bug report, and note anything retention-policy-relevant in `docs/security/PII_DATA_INVENTORY.md` if it reveals a gap in that map.
