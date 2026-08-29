# Project Constitution

This constitution is literate: human guidance plus machine-parsed `exo-policy` blocks.
Edit these rules to match your project's needs, then run `exo build-governance` to recompile.

## Article: Secrets
[RULE-SEC-001] Agents must never read or write host credential stores or dotenv secrets.

```yaml exo-policy
{
  "id": "RULE-SEC-001",
  "type": "filesystem_deny",
  "patterns": ["~/.aws/**", "~/.ssh/**", "**/.env", "**/.env.local", "**/.env.*.local", "**/.env.production", "**/.env.staging", "**/.env.development", "**/.env.test"],
  "actions": ["read", "write"],
  "message": "Blocked by RULE-SEC-001 (Secrets). Use secret injection."
}
```

## Article: Git internals
[RULE-GIT-001] Agents must not mutate `.git` internals.

```yaml exo-policy
{
  "id": "RULE-GIT-001",
  "type": "filesystem_deny",
  "patterns": [".git/**"],
  "actions": ["read", "write", "delete"],
  "message": "Blocked by RULE-GIT-001 (.git internals are protected)."
}
```

## Article: Ticket lock required
[RULE-LOCK-001] Any governed write requires an active ticket lock.

```yaml exo-policy
{
  "id": "RULE-LOCK-001",
  "type": "require_lock",
  "message": "Blocked by RULE-LOCK-001 (acquire a ticket lock first)."
}
```

## Article: Checks before done
[RULE-CHECK-001] A ticket must pass checks before status can move to done.

```yaml exo-policy
{
  "id": "RULE-CHECK-001",
  "type": "require_checks",
  "message": "Blocked by RULE-CHECK-001 (checks must pass before done)."
}
```

## Article: Risk-based autonomous delivery
[RULE-EVO-001] T0/T1 work is autonomous after required checks; T2 requires verifiable independent review; T3 may be prepared autonomously but stops at protected boundaries.

```yaml exo-policy
{
  "id": "RULE-EVO-001",
  "type": "evolution_gate",
  "practice_requires": ["risk:classified", "checks:required"],
  "governance_requires": ["policy:current"],
  "message": "T0/T1 autonomous after checks; T2 requires independent review; T3 stops at protected boundaries."
}
```

## Article: Patch-first evolution
[RULE-EVO-002] No self-evolution applies without proposal + patch + approval + audit trail.

```yaml exo-policy
{
  "id": "RULE-EVO-002",
  "type": "patch_first",
  "requires": ["proposal_artifact", "patch_artifact", "review_artifact", "audit_trail"],
  "message": "Patch-first evolution required."
}
```
