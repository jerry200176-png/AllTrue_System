"""Bounded retry for read-only ``gh api`` GET calls used by deploy classification.

Deploy run 38610137074 failed closed because one ``/commits/{sha}/pulls`` read
returned an empty body (``gh`` exit 1, ``unexpected end of JSON input``); the
same read succeeded minutes later. This helper retries only that transient
shape (empty body or HTTP 5xx) a bounded number of times with backoff.

It never decides anything: every failure still raises ``RuntimeError`` so the
caller's existing fail-closed handler stays in charge. Client errors such as
HTTP 404 are not retried and keep their message, because callers match on
``"HTTP 404"``.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
from typing import Callable

DEFAULT_ATTEMPTS = 3
DEFAULT_BACKOFF_SECONDS = (2.0, 5.0)

_SERVER_ERROR = re.compile(r"\bHTTP 5\d\d\b")
_EMPTY_JSON_MARKER = "unexpected end of JSON input"


def _clip(text: str) -> str:
    return (text or "").strip().replace("\n", " ")[:180]


def is_transient_failure(returncode: int, stdout: str, stderr: str) -> bool:
    """True only for an empty body or an HTTP 5xx answer."""
    body_empty = not (stdout or "").strip()
    if returncode == 0:
        return body_empty
    # stderr only: a paginated 4xx body on stdout may quote "HTTP 502" text.
    if _SERVER_ERROR.search(stderr or ""):
        return True
    return body_empty and _EMPTY_JSON_MARKER in (stderr or "")


def _parse(path: str, returncode: int, stdout: str, stderr: str):
    if returncode != 0:
        err = _clip(stderr or stdout or "")
        raise RuntimeError(f"gh api failed path={path} code={returncode} err={err!r}")
    stripped = (stdout or "").strip()
    if not stripped:
        raise RuntimeError(f"gh api empty JSON body path={path} err={_clip(stderr)!r}")
    try:
        return json.loads(stripped)
    except json.JSONDecodeError as exc:
        prefix = stripped[:80].replace("\n", " ")
        raise RuntimeError(
            f"gh api invalid JSON path={path}: {exc}; body_prefix={prefix!r}"
        ) from exc


def gh_api_json(
    path: str,
    *extra: str,
    attempts: int = DEFAULT_ATTEMPTS,
    backoff: tuple[float, ...] = DEFAULT_BACKOFF_SECONDS,
    runner: Callable[..., subprocess.CompletedProcess] = subprocess.run,
    sleep: Callable[[float], None] = time.sleep,
    log: Callable[[str], None] | None = None,
):
    """Run ``gh api path *extra`` and return parsed JSON, retrying transient failures.

    Raises ``RuntimeError`` (never returns an empty value) when every attempt
    fails or when the failure is not transient.
    """
    if attempts < 1:
        raise ValueError("attempts must be at least 1")
    emit = log or (lambda message: print(message, file=sys.stderr))
    for attempt in range(1, attempts + 1):
        proc = runner(
            ["gh", "api", path, *extra],
            text=True,
            capture_output=True,
            env=os.environ.copy(),
            check=False,
        )
        stdout, stderr = proc.stdout or "", proc.stderr or ""
        if not is_transient_failure(proc.returncode, stdout, stderr):
            return _parse(path, proc.returncode, stdout, stderr)
        if attempt == attempts:
            emit(
                f"gh api transient failure persisted after {attempts} attempts "
                f"path={path}; failing closed"
            )
            return _parse(path, proc.returncode, stdout, stderr)
        delay = backoff[min(attempt - 1, len(backoff) - 1)] if backoff else 0.0
        emit(
            f"gh api transient failure attempt {attempt}/{attempts} path={path} "
            f"code={proc.returncode} err={_clip(stderr)!r}; retrying in {delay:g}s"
        )
        sleep(delay)
    raise RuntimeError(f"gh api retry loop exhausted path={path}")  # unreachable
