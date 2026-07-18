# Evidence Contract

## What counts as done

| Claim | Required evidence |
|-------|-------------------|
| Code fixed | PR merged to main + linked tests |
| Deployed | Workflow success + production SHA/hash matches merge |
| Behavior fixed | Prod API/UI observation **or** bundle marker + targeted API check |
| In-app bug engineering-complete | Public comment + `resolved` ([CHAT_BUG_SYSTEM](../CHAT_BUG_SYSTEM.md) §3.7) |
| In-app bug closed | Reporter-verify **or** timeout below |
| Governance control live | File on main + ≥1 CI/Rule/code enforcement |

## Anti-metrics (never sole success)

- File/doc count  
- Issue close count  
- CI green alone  
- “Agent said done”

## Reporter-verify timeout (in-app)

After `resolved` + public ask-to-retest:

- Wait **7 calendar days** for reporter reply.  
- If no reply and no regression signal: may move to `closed` with note `closed_by_timeout` citing this contract.  
- If reporter reports still broken: reopen to `in_progress` (do not game close).

## Independent verification

Prefer a second agent/role assuming the fix is wrong before merge of high-risk changes. Same-session self-check is Partial only.
