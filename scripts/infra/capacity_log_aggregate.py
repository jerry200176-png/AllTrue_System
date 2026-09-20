#!/usr/bin/env python3
"""Bounded, de-identified production log aggregation for capacity evidence.

The collector is intentionally read-only.  It emits aggregate JSON only and
never emits a raw path, query string, identifier, log line, or file contents.
"""

from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import math
import os
import re
import sys
import time
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import BinaryIO, Iterable, Iterator
from zoneinfo import ZoneInfo


LOCAL_TZ = ZoneInfo("Asia/Taipei")
METHODS = {"GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"}
SAFE_ROUTE_PREFIXES = (
    "/api/v1/learning-records",
    "/api/v1/notifications/unread-count",
    "/api/v1/class-sessions",
    "/api/v1/students",
    "/api/v1/student-classes",
)
DEFAULT_MAX_BYTES = 128 * 1024 * 1024
DEFAULT_MAX_LINE_BYTES = 64 * 1024
DEFAULT_MAX_RECORDS = 200_000
DEFAULT_MAX_OUTPUT_BYTES = 64 * 1024
DEFAULT_MAX_CANDIDATE_FILES = 128
DEFAULT_MAX_LIST_ITEMS = 64


@dataclass(frozen=True)
class Window:
    start: datetime
    end: datetime

    @property
    def expected_days(self) -> set[str]:
        return {
            (self.start.date() + timedelta(days=offset)).isoformat()
            for offset in range((self.end.date() - self.start.date()).days)
        }


@dataclass
class ReadBudget:
    max_bytes: int
    max_line_bytes: int
    max_records: int
    deadline: float
    read_bytes: int = 0
    read_compressed_bytes: int = 0
    line_too_long: int = 0
    parse_errors: int = 0
    records_seen: int = 0
    truncated: bool = False
    timeout: bool = False
    memory_limit_hit: bool = False

    def allow_chunk(self, requested: int) -> int:
        if time.monotonic() >= self.deadline:
            self.timeout = True
            return 0
        remaining = self.max_bytes - self.read_bytes
        if remaining <= 0:
            self.truncated = True
            return 0
        return min(requested, remaining)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--perf-dir", type=Path, required=True)
    parser.add_argument("--access-dir", type=Path, required=True)
    parser.add_argument("--window-end", default=None)
    parser.add_argument("--days", type=int, default=7)
    parser.add_argument("--max-seconds", type=float, default=55.0)
    parser.add_argument("--max-bytes", type=int, default=DEFAULT_MAX_BYTES)
    parser.add_argument("--max-line-bytes", type=int, default=DEFAULT_MAX_LINE_BYTES)
    parser.add_argument("--max-records", type=int, default=DEFAULT_MAX_RECORDS)
    parser.add_argument("--max-output-bytes", type=int, default=DEFAULT_MAX_OUTPUT_BYTES)
    return parser.parse_args(argv)


def local_now() -> datetime:
    return datetime.now(timezone.utc).astimezone(LOCAL_TZ)


def parse_window(window_end: str | None, days: int) -> Window:
    if days <= 0:
        raise ValueError("days must be positive")
    if window_end:
        parsed = datetime.fromisoformat(window_end.replace("Z", "+00:00"))
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=LOCAL_TZ)
        end = parsed.astimezone(LOCAL_TZ).replace(
            hour=0, minute=0, second=0, microsecond=0
        )
    else:
        end = local_now().replace(hour=0, minute=0, second=0, microsecond=0)
    return Window(start=end - timedelta(days=days), end=end)


def parse_log_timestamp(line: str) -> datetime | None:
    match = re.search(r"\[([^\]]+)\]", line)
    if not match:
        return None
    value = match.group(1).strip()
    for fmt in (
        "%Y-%m-%d %H:%M:%S.%f%z",
        "%Y-%m-%d %H:%M:%S%z",
        "%d/%b/%Y:%H:%M:%S %z",
    ):
        try:
            parsed = datetime.strptime(value, fmt)
            return parsed.astimezone(LOCAL_TZ)
        except ValueError:
            continue
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=LOCAL_TZ)
        return parsed.astimezone(LOCAL_TZ)
    except ValueError:
        return None


def safe_route_template(path: object) -> str:
    """Return only an allow-listed route family; never return caller input."""

    if not isinstance(path, str):
        return "OTHER"
    clean = path.split("?", 1)[0].split("#", 1)[0]
    clean = "/" + clean.lstrip("/")
    for prefix in SAFE_ROUTE_PREFIXES:
        if clean == prefix or clean.startswith(prefix + "/"):
            return prefix
    return "OTHER"


def nearest_rank(values: list[float], quantile: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, math.ceil(quantile * len(ordered)) - 1))
    return round(ordered[index], 3)


def parse_status(value: object) -> int | None:
    if isinstance(value, bool):
        return None
    try:
        status = int(value)
    except (TypeError, ValueError):
        return None
    return status if 100 <= status <= 599 else None


def parse_duration(value: object) -> float | None:
    try:
        duration = float(value)
    except (TypeError, ValueError):
        return None
    if not math.isfinite(duration) or duration < 0:
        return None
    return duration


def parse_json_context(line: str, marker: str) -> dict[str, object] | None:
    marker_index = line.find(marker)
    if marker_index < 0:
        return None
    brace = line.find("{", marker_index + len(marker))
    if brace < 0:
        return None
    try:
        value, _ = json.JSONDecoder().raw_decode(line[brace:])
    except json.JSONDecodeError:
        return None
    return value if isinstance(value, dict) else None


def iter_bounded_lines(
    stream: BinaryIO,
    budget: ReadBudget,
) -> Iterator[bytes]:
    """Read bounded lines without allowing a single unbounded line buffer."""

    buffer = bytearray()
    chunk_size = 8192
    dropping_long_line = False
    while not budget.timeout and not budget.truncated:
        request = budget.allow_chunk(chunk_size)
        if request <= 0:
            break
        chunk = stream.read(request)
        if not chunk:
            if buffer and not dropping_long_line:
                yield bytes(buffer)
            break
        budget.read_bytes += len(chunk)
        buffer.extend(chunk)
        while True:
            newline = buffer.find(b"\n")
            if newline < 0:
                if len(buffer) > budget.max_line_bytes:
                    budget.line_too_long += 1
                    buffer.clear()
                    dropping_long_line = True
                break
            line = bytes(buffer[:newline])
            del buffer[: newline + 1]
            if dropping_long_line:
                dropping_long_line = False
                continue
            if len(line) > budget.max_line_bytes:
                budget.line_too_long += 1
                continue
            yield line


def open_source(path: Path) -> BinaryIO:
    if path.name.endswith(".gz"):
        return gzip.open(path, "rb")
    if path.suffix in {".log", ".1", ".2", ".3", ".4", ".5", ".6", ".7", ".8", ".9"}:
        return path.open("rb")
    raise ValueError("unsupported_file_type")


def filename_date(path: Path) -> date | None:
    match = re.search(r"(20\d{2}-\d{2}-\d{2})", path.name)
    if not match:
        return None
    try:
        return date.fromisoformat(match.group(1))
    except ValueError:
        return None


def enumerate_sources(directory: Path, prefix: str, window: Window) -> tuple[list[Path], dict[str, object]]:
    inventory: list[Path] = []
    errors: list[str] = []
    permission_denied = False
    try:
        for entry in os.scandir(directory):
            if entry.is_file() and entry.name.startswith(prefix):
                inventory.append(Path(entry.path))
    except PermissionError:
        permission_denied = True
    except OSError as exc:
        errors.append(type(exc).__name__)

    inventory.sort(key=lambda item: item.name)
    candidate_limit_hit = len(inventory) > DEFAULT_MAX_CANDIDATE_FILES
    candidates = inventory[:DEFAULT_MAX_CANDIDATE_FILES]
    selected: list[Path] = []
    expanded_start = window.start.date() - timedelta(days=1)
    expanded_end = window.end.date() + timedelta(days=1)
    for path in candidates:
        stamped = filename_date(path)
        if stamped is not None and expanded_start <= stamped <= expanded_end:
            selected.append(path)
            continue
        try:
            mtime = datetime.fromtimestamp(path.stat().st_mtime, LOCAL_TZ)
        except OSError:
            continue
        if window.start - timedelta(days=1) <= mtime <= window.end + timedelta(days=1):
            selected.append(path)

    return selected, {
        "inventory_count": len(inventory),
        "candidate_files_truncated": candidate_limit_hit,
        "inventory_files": [path.name for path in candidates[:DEFAULT_MAX_LIST_ITEMS]],
        "enumeration_errors": errors,
        "permission_denied": permission_denied,
    }


def source_metadata(paths: Iterable[Path]) -> list[dict[str, object]]:
    output: list[dict[str, object]] = []
    for path in list(paths)[:DEFAULT_MAX_LIST_ITEMS]:
        try:
            stat = path.stat()
            output.append(
                {
                    "file": path.name,
                    "bytes": stat.st_size,
                    "mtime": datetime.fromtimestamp(stat.st_mtime, LOCAL_TZ).isoformat(),
                    "compressed": path.name.endswith(".gz"),
                }
            )
        except PermissionError:
            output.append({"file": path.name, "metadata": "permission_denied"})
        except OSError:
            output.append({"file": path.name, "metadata": "unavailable"})
    return output


def parse_perf(
    paths: list[Path],
    window: Window,
    budget: ReadBudget,
) -> dict[str, object]:
    route_values: defaultdict[tuple[str, str], list[float]] = defaultdict(list)
    route_status: defaultdict[tuple[str, str], Counter[str]] = defaultdict(Counter)
    status_counts: Counter[str] = Counter()
    hourly: Counter[str] = Counter()
    seen_trace_ids: set[str] = set()
    duplicate_events = 0
    permission_denied: list[str] = []
    unreadable_files: list[dict[str, str]] = []
    out_of_window = 0
    records_without_trace_id = 0
    observed_days: set[str] = set()

    for path in paths:
        if budget.timeout or budget.truncated:
            break
        try:
            with open_source(path) as stream:
                budget.read_compressed_bytes += path.stat().st_size if path.name.endswith(".gz") else 0
                for raw in iter_bounded_lines(stream, budget):
                    if budget.records_seen >= budget.max_records:
                        budget.memory_limit_hit = True
                        budget.truncated = True
                        break
                    try:
                        line = raw.decode("utf-8", "strict")
                    except UnicodeDecodeError:
                        budget.parse_errors += 1
                        continue
                    if "perf_metric" not in line:
                        continue
                    timestamp = parse_log_timestamp(line)
                    context = parse_json_context(line, "perf_metric")
                    if timestamp is None or context is None:
                        budget.parse_errors += 1
                        continue
                    if not window.start <= timestamp < window.end:
                        out_of_window += 1
                        continue
                    method = context.get("method")
                    status = parse_status(context.get("status"))
                    duration = parse_duration(context.get("duration_ms"))
                    if method not in METHODS or status is None or duration is None:
                        budget.parse_errors += 1
                        continue
                    trace_id = context.get("trace_id")
                    if isinstance(trace_id, str) and trace_id:
                        if trace_id in seen_trace_ids:
                            duplicate_events += 1
                            continue
                        seen_trace_ids.add(trace_id)
                    else:
                        records_without_trace_id += 1
                    budget.records_seen += 1
                    route = safe_route_template(context.get("path"))
                    observed_days.add(timestamp.date().isoformat())
                    key = (str(method), route)
                    route_values[key].append(duration)
                    route_status[key][str(status)] += 1
                    status_counts[str(status)] += 1
                    hourly[timestamp.strftime("%Y-%m-%dT%H:00:00%z")] += 1
        except PermissionError:
            permission_denied.append(path.name)
        except (FileNotFoundError, gzip.BadGzipFile, EOFError, ValueError, OSError) as exc:
            unreadable_files.append({"file": path.name, "reason": type(exc).__name__})

    routes = []
    for (method, route), values in route_values.items():
        routes.append(
            {
                "method": method,
                "route": route,
                "request_count": len(values),
                "p50_ms": nearest_rank(values, 0.50),
                "p95_ms": nearest_rank(values, 0.95),
                "p99_ms": nearest_rank(values, 0.99),
                "status": dict(route_status[(method, route)]),
            }
        )
    routes.sort(key=lambda row: (row["p95_ms"] or 0, row["request_count"]), reverse=True)

    return {
        "request_count": sum(len(values) for values in route_values.values()),
        "status": dict(status_counts),
        "routes": routes,
        "hourly_count": dict(hourly),
        "duplicate_events_removed": duplicate_events,
        "records_without_trace_id": records_without_trace_id,
        "records_out_of_window": out_of_window,
        "observed_record_days": sorted(observed_days),
        "permission_denied_files": permission_denied,
        "unreadable_files": unreadable_files,
    }


def parse_access(
    paths: list[Path],
    window: Window,
    budget: ReadBudget,
) -> dict[str, object]:
    status_counts: Counter[str] = Counter()
    route_counts: Counter[tuple[str, str]] = Counter()
    hourly: Counter[str] = Counter()
    permission_denied: list[str] = []
    unreadable_files: list[dict[str, str]] = []
    out_of_window = 0
    parse_errors = 0
    request_count = 0
    observed_days: set[str] = set()

    for path in paths:
        if budget.timeout or budget.truncated:
            break
        try:
            with open_source(path) as stream:
                budget.read_compressed_bytes += path.stat().st_size if path.name.endswith(".gz") else 0
                for raw in iter_bounded_lines(stream, budget):
                    try:
                        line = raw.decode("utf-8", "strict")
                    except UnicodeDecodeError:
                        parse_errors += 1
                        continue
                    timestamp = parse_log_timestamp(line)
                    status_match = re.search(r'"\s+(\d{3})\s+\S+', line)
                    request_match = re.search(
                        r'"(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(\S+)', line
                    )
                    if timestamp is None or status_match is None or request_match is None:
                        parse_errors += 1
                        continue
                    if not window.start <= timestamp < window.end:
                        out_of_window += 1
                        continue
                    method = request_match.group(1)
                    status = parse_status(status_match.group(1))
                    if status is None:
                        parse_errors += 1
                        continue
                    route = safe_route_template(request_match.group(2))
                    request_count += 1
                    observed_days.add(timestamp.date().isoformat())
                    status_counts[str(status)] += 1
                    route_counts[(method, route)] += 1
                    hourly[timestamp.strftime("%Y-%m-%dT%H:00:00%z")] += 1
        except PermissionError:
            permission_denied.append(path.name)
        except (FileNotFoundError, gzip.BadGzipFile, EOFError, ValueError, OSError) as exc:
            unreadable_files.append({"file": path.name, "reason": type(exc).__name__})

    return {
        "request_count": request_count,
        "status": dict(status_counts),
        "five_xx": sum(count for status, count in status_counts.items() if status.startswith("5")),
        "timeout_like_status": sum(
            count for status, count in status_counts.items() if status in {"408", "504"}
        ),
        "routes": [
            {"method": method, "route": route, "request_count": count}
            for (method, route), count in route_counts.most_common(DEFAULT_MAX_LIST_ITEMS)
        ],
        "hourly_count": dict(hourly),
        "records_out_of_window": out_of_window,
        "observed_record_days": sorted(observed_days),
        "parse_errors": parse_errors,
        "permission_denied_files": permission_denied,
        "unreadable_files": unreadable_files,
        "end_to_end_latency_available": False,
    }


def coverage_status(
    selected: list[Path],
    inventory: dict[str, object],
    parsed: dict[str, object],
    window: Window,
) -> str:
    if not selected and not inventory.get("permission_denied"):
        return "unavailable"
    if inventory.get("permission_denied"):
        return "partial"
    if inventory.get("candidate_files_truncated"):
        return "partial"
    if parsed.get("permission_denied_files") or parsed.get("unreadable_files"):
        return "partial"
    if parsed.get("parse_errors") or parsed.get("records_out_of_window"):
        return "partial"
    return "complete"


def bounded_report(report: dict[str, object], max_bytes: int) -> tuple[dict[str, object], bool]:
    def encode(value: dict[str, object]) -> bytes:
        return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")

    if len(encode(report)) <= max_bytes:
        return report, False

    reduced = json.loads(json.dumps(report))
    for limit in (32, 16, 8, 0):
        for section in ("perf", "apache_access"):
            if isinstance(reduced.get(section), dict):
                routes = reduced[section].get("routes")
                if isinstance(routes, list):
                    reduced[section]["routes_omitted"] = max(0, len(routes) - limit)
                    reduced[section]["routes"] = routes[:limit]
                files = reduced[section].get("files")
                if isinstance(files, list):
                    reduced[section]["files_omitted"] = max(0, len(files) - limit)
                    reduced[section]["files"] = files[:limit]
        reduced["output_truncated"] = True
        if len(encode(reduced)) <= max_bytes:
            return reduced, True

    minimal = {
        "schema": "alltrue.capacity-baseline.v1",
        "collection_status": "partial",
        "output_truncated": True,
        "reason": "aggregate_output_limit",
        "window": report.get("window"),
        "limits": report.get("limits"),
        "runtime": report.get("runtime"),
    }
    return minimal, True


def build_report(args: argparse.Namespace) -> dict[str, object]:
    window = parse_window(args.window_end, args.days)
    budget = ReadBudget(
        max_bytes=args.max_bytes,
        max_line_bytes=args.max_line_bytes,
        max_records=args.max_records,
        deadline=time.monotonic() + args.max_seconds,
    )
    perf_paths, perf_inventory = enumerate_sources(args.perf_dir, "perf", window)
    access_paths, access_inventory = enumerate_sources(args.access_dir, "alltrue_access", window)
    perf = parse_perf(perf_paths, window, budget)
    access = parse_access(access_paths, window, budget)

    status = "complete"
    if budget.timeout or budget.truncated or budget.memory_limit_hit:
        status = "partial"
    if not perf_paths and not access_paths:
        status = "unavailable"
    elif not perf_paths or not access_paths:
        status = "partial"
    if any(
        [
            perf_inventory.get("permission_denied"),
            access_inventory.get("permission_denied"),
            perf_inventory.get("candidate_files_truncated"),
            access_inventory.get("candidate_files_truncated"),
            perf.get("permission_denied_files"),
            access.get("permission_denied_files"),
            perf.get("unreadable_files"),
            access.get("unreadable_files"),
            perf.get("parse_errors"),
            access.get("parse_errors"),
        ]
    ):
        status = "partial" if status != "unavailable" else status

    denominator_seconds = (window.end - window.start).total_seconds()
    if status == "complete" and denominator_seconds > 0:
        perf["rps"] = (
            round(perf["request_count"] / denominator_seconds, 6)
            if perf["request_count"]
            else None
        )
        access["rps"] = (
            round(access["request_count"] / denominator_seconds, 6)
            if access["request_count"]
            else None
        )
        for route in perf.get("routes", []):
            route["rps"] = round(route["request_count"] / denominator_seconds, 6)
        for route in access.get("routes", []):
            route["rps"] = round(route["request_count"] / denominator_seconds, 6)
    else:
        perf["rps"] = None
        access["rps"] = None
        for route in perf.get("routes", []):
            route["rps"] = None
        for route in access.get("routes", []):
            route["rps"] = None

    return {
        "schema": "alltrue.capacity-baseline.v1",
        "collection_status": status,
        "generated_at": local_now().isoformat(),
        "window": {
            "timezone": "Asia/Taipei",
            "start": window.start.isoformat(),
            "end": window.end.isoformat(),
            "complete_days": sorted(window.expected_days),
            "filter": "per-record timestamp converted to Asia/Taipei",
        },
        "runtime": {
            "runtime_sha_observed": os.environ.get("RUNTIME_SHA_OBSERVED", "UNKNOWN"),
            "runtime_observed_at": os.environ.get("RUNTIME_OBSERVED_AT", "UNKNOWN"),
            "historical_version_attribution": "UNKNOWN; records may span deployments",
        },
        "limits": {
            "max_seconds": args.max_seconds,
            "max_decompressed_bytes_total": args.max_bytes,
            "max_line_bytes": args.max_line_bytes,
            "max_records_total": args.max_records,
            "max_output_bytes": args.max_output_bytes,
        },
        "read": {
            "decompressed_bytes": budget.read_bytes,
            "compressed_file_bytes_metadata_total": budget.read_compressed_bytes,
            "truncated": budget.truncated,
            "timeout": budget.timeout,
            "memory_limit_hit": budget.memory_limit_hit,
            "line_too_long": budget.line_too_long,
            "records_seen": budget.records_seen,
            "parse_errors": budget.parse_errors + int(access.get("parse_errors", 0)),
        },
        "perf": {
            **perf,
            "files": source_metadata(perf_paths),
            "inventory": perf_inventory,
            "coverage": {
                "window_days": sorted(window.expected_days),
                "observed_record_days": perf.get("observed_record_days", []),
                "days_without_observed_records": sorted(
                    window.expected_days - set(perf.get("observed_record_days", []))
                ),
                "interpretation": "absence of records is not proof of zero traffic",
            },
            "rps_denominator_seconds": (window.end - window.start).total_seconds()
            if status == "complete"
            else None,
            "rps_limit_note": "null when collection is partial; missing time is not zero traffic",
            "timing_boundary": "LogSlowRequests middleware around Laravel $next; not end-to-end",
        },
        "apache_access": {
            **access,
            "files": source_metadata(access_paths),
            "inventory": access_inventory,
            "coverage": {
                "window_days": sorted(window.expected_days),
                "observed_record_days": access.get("observed_record_days", []),
                "days_without_observed_records": sorted(
                    window.expected_days - set(access.get("observed_record_days", []))
                ),
                "interpretation": "absence of records is not proof of zero traffic",
            },
            "latency_note": "combined access format has no reliable end-to-end latency",
        },
        "safety": {
            "raw_logs_emitted": False,
            "query_strings_emitted": False,
            "identifiers_emitted": False,
            "safe_route_templates_only": True,
            "source_file_contents_emitted": False,
        },
    }


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    try:
        report = build_report(args)
        bounded, output_truncated = bounded_report(report, args.max_output_bytes)
        if output_truncated:
            bounded["output_truncated"] = True
        encoded = json.dumps(bounded, ensure_ascii=False, separators=(",", ":"))
        if len(encoded.encode("utf-8")) > args.max_output_bytes:
            raise RuntimeError("unable to fit safe aggregate within output limit")
        print(encoded)
        return 0
    except Exception as exc:  # pragma: no cover - CLI failure path
        print(
            json.dumps(
                {
                    "schema": "alltrue.capacity-baseline.v1",
                    "collection_status": "unavailable",
                    "error_type": type(exc).__name__,
                    "error": "collector_failed_without_log_output",
                },
                separators=(",", ":"),
            )
        )
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
