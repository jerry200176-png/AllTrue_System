"""Atomic lease manager: acquire / renew / release / reclaim (fencing + identity)."""

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
    """Initial acquire only. Does not renew; same task_id alone cannot steal a live lease."""
    now = now or _now()
    lease = {
        "lease_id": f"lease_{uuid.uuid4().hex[:12]}",
        "resource_key": resource_key,
        "holder_task_id": task_id,
        "holder_worker": worker,
        "expires_at": _iso(now + timedelta(seconds=ttl_sec)),
        "fencing_token": 1,
        "payload": {"acquired_at": _iso(now)},
    }
    return store.cas_acquire_lease(lease, now_iso=_iso(now))


def renew(
    store: HarnessStore,
    resource_key: str,
    *,
    lease_id: str,
    fencing_token: int,
    task_id: str,
    worker: str,
    ttl_sec: int = DEFAULT_TTL_SEC,
    now: datetime | None = None,
) -> dict[str, Any]:
    """Extend ownership; requires current lease_id + fencing_token + task_id."""
    now = now or _now()
    return store.cas_renew_lease(
        resource_key,
        lease_id=lease_id,
        fencing_token=fencing_token,
        task_id=task_id,
        worker=worker,
        expires_at=_iso(now + timedelta(seconds=ttl_sec)),
        now_iso=_iso(now),
    )


def release(
    store: HarnessStore,
    resource_key: str,
    *,
    lease_id: str,
    fencing_token: int,
    task_id: str,
) -> None:
    """Release requires lease identity + fencing; stale holders cannot delete newer leases."""
    store.cas_release_lease(
        resource_key,
        lease_id=lease_id,
        fencing_token=fencing_token,
        task_id=task_id,
    )


def reclaim_stale(store: HarnessStore, now: datetime | None = None) -> list[str]:
    """Conditionally delete each expired lease by identity+fencing+expiry (no blind delete)."""
    now = now or _now()
    return store.cas_reclaim_stale_leases(now_iso=_iso(now))
