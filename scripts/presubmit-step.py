#!/usr/bin/env python3
"""Print the `run:` body of the presubmit.yml step whose name starts with argv[1] (local hooks reuse CI shell)."""
import sys
from pathlib import Path

import yaml

wf = yaml.safe_load((Path(__file__).resolve().parents[1] / ".github/workflows/presubmit.yml").read_text(encoding="utf-8"))
steps = [s for s in wf["jobs"]["gate"]["steps"] if str(s.get("name", "")).startswith(sys.argv[1])]
if len(steps) != 1:
    sys.exit(f"presubmit-step: expected exactly one step matching {sys.argv[1]!r}, got {len(steps)}")
print(steps[0]["run"])
