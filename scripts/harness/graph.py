"""Minimal graph node/edge view over durable harness state (H2)."""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any

from .states import TaskState
from .store import HarnessStore


@dataclass
class GraphNode:
    node_id: str
    kind: str
    state: str
    program_id: str = ""
    refs: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


@dataclass
class GraphEdge:
    edge_id: str
    kind: str
    src: str
    dst: str
    meta: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


@dataclass
class GraphSnapshot:
    nodes: list[GraphNode]
    edges: list[GraphEdge]

    def to_dict(self) -> dict[str, Any]:
        return {"nodes": [n.to_dict() for n in self.nodes], "edges": [e.to_dict() for e in self.edges]}


def build_graph(store: HarnessStore) -> GraphSnapshot:
    nodes: list[GraphNode] = []
    edges: list[GraphEdge] = []
    for prog in store.list_programs():
        nodes.append(GraphNode(
            node_id=f"program:{prog.program_id}", kind="program",
            state="blocked" if prog.blockers else "active", program_id=prog.program_id,
        ))
    for task in store.list_tasks():
        tid = f"task:{task.task_id}"
        nodes.append(GraphNode(
            node_id=tid, kind="task", state=task.status.value, program_id=task.program_id,
            refs={"lease_id": task.lease_id, "assignee": task.assignee},
        ))
        edges.append(GraphEdge(
            edge_id=f"owns:{task.task_id}", kind="owns",
            src=f"program:{task.program_id}", dst=tid,
        ))
        for dep in task.dependencies:
            edges.append(GraphEdge(
                edge_id=f"depends:{task.task_id}:{dep}", kind="depends_on",
                src=tid, dst=f"task:{dep}",
            ))
    for goal in store.list_goals():
        nodes.append(GraphNode(
            node_id=f"goal:{goal.goal_id}", kind="goal", state="bound",
            program_id=goal.program_id, refs={"subject_sha": goal.subject_sha},
        ))
        edges.append(GraphEdge(
            edge_id=f"binds:{goal.goal_id}", kind="binds",
            src=f"goal:{goal.goal_id}", dst=f"task:{goal.task_id}",
        ))
    for lease in store.list_leases():
        lid = f"lease:{lease['lease_id']}"
        nodes.append(GraphNode(
            node_id=lid, kind="lease", state="held",
            refs={"resource_key": lease["resource_key"], "holder_task_id": lease["holder_task_id"]},
        ))
        edges.append(GraphEdge(
            edge_id=f"owns-lease:{lease['lease_id']}", kind="owns",
            src=f"task:{lease['holder_task_id']}", dst=lid,
        ))
    return GraphSnapshot(nodes=nodes, edges=edges)


def deny_and_continue_peers(store: HarnessStore, blocked_task_id: str) -> list[str]:
    blocked = store.get_task(blocked_task_id)
    if not blocked:
        return []
    return [
        t.task_id for t in store.list_tasks()
        if t.task_id != blocked_task_id and t.status == TaskState.READY
        and blocked.task_id not in t.dependencies
    ]
