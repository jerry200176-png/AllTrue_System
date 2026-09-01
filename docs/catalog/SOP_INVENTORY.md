# SOP / runbook inventory

This is the read-only inventory for [#894](https://github.com/jerry200176-png/AllTrue_System/issues/894), generated from the committed review metadata as of **2026-09-01 (Asia/Taipei)**. The machine-readable records are in [`SOP_INVENTORY.json`](SOP_INVENTORY.json).

## Scope and meaning

- The inventory covers every committed `docs/**/*.md` file that currently declares `review_cycle` metadata (23 records, including lifecycle-managed plans and RFCs).
- `next_review` is calculated from the declared cycle. `null` means the document is reviewed on demand or at the next Founder review, not that it is exempt.
- `last_drill_evidence_link: null` is an explicit evidence gap; it does not claim that a drill happened.
- `review_status` is a triage field as of the inventory date: `current`, `overdue`, or `not_scheduled`. The inventory is a registry, not a decision or execution authority.

## Read-only overdue query

List records currently marked overdue without changing the repository:

```sh
rg -n '"review_status": "overdue"' docs/catalog/SOP_INVENTORY.json
```

For each result, the owner should confirm the document and date against current operational truth, update the source document and inventory in one reviewed change, and attach a real workflow/issue/log link if a drill was completed. Opening or updating a GitHub issue remains a deliberate human/agent workflow step; this inventory does not create issues automatically.

## Maintenance contract

When a reviewed document changes its lifecycle metadata, update the matching record in the same PR. Do not invent statutory retention, SLA, drill, or approval evidence. Future automation may consume this JSON to report overdue records, but it must preserve the manual review and issue-opening gate.
