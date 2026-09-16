"""Load repository-backed Program contracts from YAML."""

from __future__ import annotations

from pathlib import Path
from typing import Any

import yaml

from .models import Program, Task
from .store import HarnessStore

PROGRAMS_DIR = Path(__file__).resolve().parent / "programs"


def list_program_config_paths() -> list[Path]:
    return sorted(PROGRAMS_DIR.glob("*.yaml"))


def load_program_config(path: Path) -> tuple[Program, list[Task]]:
    data = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"program config must be a mapping: {path}")
    prog_data = dict(data.get("program") or {})
    if "program_id" not in prog_data:
        prog_data["program_id"] = path.stem
    program = Program.from_dict(prog_data)
    tasks: list[Task] = []
    for item in data.get("candidate_tasks") or []:
        item = dict(item)
        item.setdefault("program_id", program.program_id)
        tasks.append(Task.from_dict(item))
    return program, tasks


def sync_programs_to_store(store: HarnessStore, *, only_missing_tasks: bool = True) -> list[str]:
    """Load YAML contracts into the durable store."""
    synced: list[str] = []
    for path in list_program_config_paths():
        program, tasks = load_program_config(path)
        store.upsert_program(program)
        for task in tasks:
            existing = store.get_task(task.task_id)
            if existing and only_missing_tasks:
                continue
            store.upsert_task(task)
        synced.append(program.program_id)
    return synced
