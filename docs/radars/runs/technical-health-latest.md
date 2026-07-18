# Technical Health — latest run

- revision: `8caa4065`
- ran_at: `20260718T055106Z`
- overall: **PASS** (fail=0 warn=0)
- owner: Founder / CTO Agent
- cadence: weekly
- machine output: `docs/radars/runs/technical-health-20260718T055106Z.json`

## Log

```
=== php_ci_matches_composer ===
composer.php=8.2.30 want=8.2 ci=['8.2']
result: PASS
=== node_ci_single_major ===
ci.yml: node major 22
ci.yml: node major 22
control-plane-enforce.yml: node major 22
docs-integrity.yml: node major 22
ui-smoke.yml: node major 22
node major counts: {'22': 5}
result: PASS
```
