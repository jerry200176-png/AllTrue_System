#!/usr/bin/env bash
# DAG resolution for declarative pipeline manifest (stdlib python3, no third-party deps).

mempalace_dag_manifest_path() {
  echo "${MEMPALACE_PIPELINE_MANIFEST:-${ENGINE_DIR}/pipeline.manifest.json}"
}

mempalace_dag_resolve() {
  local manifest
  manifest="$(mempalace_dag_manifest_path)"
  if [ ! -f "$manifest" ]; then
    echo "Pipeline manifest missing: $manifest" >&2
    return 1
  fi

  python3 - "$manifest" "${1:-}" "${2:-}" <<'PY'
import json, sys

manifest_path, mode, arg = sys.argv[1], sys.argv[2], sys.argv[3]
with open(manifest_path, encoding="utf-8") as f:
    data = json.load(f)

nodes = data.get("nodes", {})
if not nodes:
    raise SystemExit("manifest has no nodes")

# Validate edges
for name, spec in nodes.items():
    for dep in spec.get("depends_on", []):
        if dep not in nodes:
            raise SystemExit(f"node {name} depends on unknown {dep}")

# Kahn topological sort (stable: sorted queue)
in_deg = {n: 0 for n in nodes}
for n, spec in nodes.items():
    for dep in spec.get("depends_on", []):
        in_deg[n] += 1

ready = sorted([n for n, d in in_deg.items() if d == 0])
order = []
while ready:
    n = ready.pop(0)
    order.append(n)
    for m, spec in nodes.items():
        if n in spec.get("depends_on", []):
            in_deg[m] -= 1
            if in_deg[m] == 0:
                ready.append(m)
                ready.sort()

if len(order) != len(nodes):
    raise SystemExit("cycle detected in pipeline DAG")

def downstream_only(start: str):
    if start not in nodes:
        raise SystemExit(f"unknown node: {start}")
    deps_map = {n: set(spec.get("depends_on", [])) for n, spec in nodes.items()}
    keep = {start}
    changed = True
    while changed:
        changed = False
        for n in nodes:
            if n in keep:
                continue
            if deps_map[n] & keep:
                keep.add(n)
                changed = True
    return [n for n in order if n in keep]

if mode == "validate":
    print("ok")
elif mode == "list":
    print("\n".join(order))
elif mode == "only":
    if arg not in nodes:
        raise SystemExit(f"unknown node: {arg}")
    print(arg)
elif mode == "from":
    for n in downstream_only(arg):
        print(n)
else:
    print("\n".join(order))
PY
}

mempalace_dag_list_nodes() {
  mempalace_dag_resolve list
}

mempalace_dag_execution_plan() {
  local only="${MEMPALACE_INGEST_STAGE_ONLY:-}"
  local from="${MEMPALACE_INGEST_FROM_STAGE:-}"

  if [ -n "$only" ]; then
    mempalace_dag_resolve only "$only"
  elif [ -n "$from" ]; then
    mempalace_dag_resolve from "$from"
  else
    mempalace_dag_resolve
  fi
}

mempalace_dag_node_json() {
  local node="$1"
  local field="$2"
  local manifest
  manifest="$(mempalace_dag_manifest_path)"
  python3 - "$manifest" "$node" "$field" <<'PY'
import json, sys
manifest_path, node, field = sys.argv[1:4]
with open(manifest_path, encoding="utf-8") as f:
    data = json.load(f)
spec = data["nodes"][node]
val = spec.get(field, [])
if field == "retry":
    print(json.dumps(val))
elif isinstance(val, list):
    print("\n".join(val))
else:
    print(val)
PY
}

mempalace_dag_validate_manifest() {
  mempalace_dag_resolve validate >/dev/null
}
