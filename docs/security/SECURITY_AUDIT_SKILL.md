# Project security audit skill

This repository vendors Cloudflare's `security-audit-skill` at upstream commit
`8bac42001ddd90a4dcd8d5a5045199283a8eba75` under
`.cursor/skills/security-audit/`.

## Operating boundary

- Use it for read-only reconnaissance, threat hunting, validation, and report generation.
- Run audits against local or staging fixtures first; never point the skill at production credentials or permit production mutations.
- Do not place student names, contact data, tokens, passwords, or raw database exports in audit reports or committed files.
- Treat findings as evidence for a human-reviewed issue/PR. The skill is not an auto-remediation or deployment authority.

## Review flow

1. Define the target and data boundary in the audit ticket.
2. Run the reconnaissance and hunting guidance from the vendored `SKILL.md` and reference files.
3. Validate structured findings locally:

   `node .cursor/skills/security-audit/validate-findings.cjs <report.json>`

4. Remove sensitive evidence, attach only the sanitized report, and open a security issue with severity, reachability, and remediation owner.
5. Independently reproduce high-severity findings before changing code or configuration.

Updates to the vendored skill must be proposed as a separate PR with the upstream commit, license, and a summary of changed audit behavior recorded here.
