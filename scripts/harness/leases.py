"""Local lease manager for program WIP and shared contracts."""

from __future__ import annotations

import uuid
from datetime import datetime, timedelta, timezone
from typing import Any

from .models import SHARED_CONTRACTS
from .store import HarnessStore

DEFAULT_TTL_SEC = 3600


class LeaseError(RuntimeError):
    pass


class LeaseBusyError(LeaseError):
    pass


def _now() -> datetime:
    return datetime.now(timezone.utc).replace(microsecond=0)


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def _parse(iso: str) -> datetime:
    return datetime.fromisoformat(iso.replace("Z", "+00:00"))


def program_resource(program_id: str) -> str:
    return f"program:{program_id}:mutating"


def contract_resource(name: str) -> str:
    if name not in SHARED_CONTRACTS:
        raise LeaseError(f"unknown shared contract {name}")
    return f"contract:{name}"


def acquire(
    store: HarnessStore,
    resource_key: str,
    *,
    task_id: str,
    worker: str,
    ttl_sec: int = DEFAULT_TTL_SEC,
    now: datetime | None = None,
) -> dict[str, Any]:
    """Acquire or reclaim a lease. Stale (expired) leases are recoverable."""
    now = now or _now()
    existing = store.get_lease(resource_key)
    if existing:
        expires = _parse(str(existing["expires_at"]))
        if expires > now and existing["holder_task_id"] != task_id:
            raise LeaseBusyError(
                f"{resource_key} held by task={existing['holder_task_id']} "
                f"worker={existing['holder_worker']} until {existing['expires_at']}"
            )
        fencing = int(existing["fencing_token"]) + 1
    else:
        fencing = 1

    lease = {
        "lease_id": f"lease_{uuid.uuid4().hex[:12]}",
        "resource_key": resource_key,
        "holder_task_id": task_id,
        "holder_worker": worker,
        "expires_at": _iso(now + timedelta(seconds=ttl_sec)),
        "fencing_token": fencing,
        "payload": {"acquired_at": _iso(now)},
    }
    store.put_lease(lease)
    return lease


def release(
    store: HarnessStore,
    resource_key: str,
    *,
    task_id: str,
    fencing_token: int | None = None,
) -> None:
    existing = store.get_lease(resource_key)
    if not existing:
        return
    if existing["holder_task_id"] != task_id:
        raise LeaseError("cannot release lease held by another task")
    if fencing_token is not None and int(existing["fencing_token"]) != fencing_token:
        raise LeaseError("stale fencing token")
    store.delete_lease(resource_key)


def reclaim_stale(store: HarnessStore, now: datetime | None = None) -> list[str]:
    """Delete expired leases; returns reclaimed resource keys."""
    now = now or _now()
    reclaimed: list[str] = []
    for lease in store.list_leases():
        if _parse(str(lease["expires_at"])) <= now:
            store.delete_lease(lease["resource_key"])
            reclaimed.append(lease["resource_key"])
    return reclaimed
