#!/usr/bin/env python3
"""Validate docs/catalog/services.json — fail on missing owners/runbooks/dup IDs/bad paths."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "docs/catalog/services.json"
REQUIRED = [
    "id",
    "product",
    "owner",
    "repository",
    "runtime",
    "production_environment",
    "source_of_truth",
    "dependencies",
    "external_integrations",
    "data_classification",
    "deployment_workflow",
    "health_signal",
    "runbook",
    "rollback",
    "slo_status",
    "lifecycle",
]


def main() -> int:
    data = json.loads(CATALOG.read_text(encoding="utf-8"))
    services = data.get("services") or []
    ids = []
    errs: list[str] = []
    id_set = {s.get("id") for s in services if isinstance(s, dict)}

    for s in services:
        if not isinstance(s, dict):
            errs.append("non-object service entry")
            continue
        sid = s.get("id")
        ids.append(sid)
        for k in REQUIRED:
            if k not in s or s[k] in (None, ""):
                errs.append(f"{sid}: missing {k}")
        if s.get("lifecycle") == "active":
            if not s.get("owner"):
                errs.append(f"{sid}: active without owner")
            rb = s.get("runbook") or ""
            if s.get("product") == "AllTrue" and (rb.startswith("docs/") or rb.startswith(".github/")):
                if not (ROOT / rb).exists():
                    errs.append(f"{sid}: runbook path missing: {rb}")
            if not s.get("repository"):
                errs.append(f"{sid}: missing repository")
            for dep in s.get("dependencies") or []:
                if dep not in id_set:
                    errs.append(f"{sid}: unknown dependency {dep}")
            dw = s.get("deployment_workflow") or ""
            if (
                s.get("product") == "AllTrue"
                and dw.startswith(".github/")
                and not (ROOT / dw).exists()
            ):
                errs.append(f"{sid}: deployment_workflow missing: {dw}")

    if len(ids) != len(set(ids)):
        errs.append(f"duplicate service ids: {ids}")

    if errs:
        print("CATALOG VALIDATION FAILED:")
        for e in errs:
            print(" -", e)
        return 1
    print(f"OK: {len(services)} services validated ({CATALOG})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
