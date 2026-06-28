#!/usr/bin/env python3
"""
metrics-collector.py — Runtime metrics for PDP feedback loop.

Reads health endpoint, optional fixture, or log-derived signals.
Outputs: error_rate, latency_p95, slo_breach
"""
from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# SLO thresholds (single-node Pi)
SLO_ERROR_RATE_MAX = float(os.environ.get("SLO_ERROR_RATE_MAX", "0.05"))
SLO_LATENCY_P95_MAX_MS = float(os.environ.get("SLO_LATENCY_P95_MAX_MS", "2000"))


def load_fixture() -> dict | None:
    fixture = os.environ.get("RUNTIME_METRICS_FIXTURE", "")
    if fixture and Path(fixture).exists():
        return json.loads(Path(fixture).read_text(encoding="utf-8"))
    repo_fixture = REPO_ROOT / "ci-artifacts" / "runtime" / "metrics-fixture.json"
    if repo_fixture.exists():
        return json.loads(repo_fixture.read_text(encoding="utf-8"))
    return None


def probe_health(url: str) -> tuple[float, bool]:
    samples: list[float] = []
    errors = 0
    for _ in range(3):
        start = time.perf_counter()
        try:
            req = urllib.request.Request(url, method="GET")
            with urllib.request.urlopen(req, timeout=10) as resp:
                resp.read(256)
                if resp.status >= 400:
                    errors += 1
        except (urllib.error.URLError, TimeoutError, OSError):
            errors += 1
        elapsed_ms = (time.perf_counter() - start) * 1000
        samples.append(elapsed_ms)
        time.sleep(0.1)
    error_rate = errors / max(len(samples), 1)
    samples.sort()
    p95_idx = min(len(samples) - 1, int(len(samples) * 0.95))
    latency_p95 = samples[p95_idx] if samples else 0.0
    return error_rate, latency_p95


def collect() -> dict:
    fixture = load_fixture()
    if fixture:
        metrics = {
            "error_rate": float(fixture.get("error_rate", 0.0)),
            "latency_p95": float(fixture.get("latency_p95", 0)),
            "slo_breach": bool(fixture.get("slo_breach", False)),
            "source": "fixture",
        }
    else:
        health_url = os.environ.get(
            "ALLTRUE_HEALTH_URL",
            "https://daan.lifenet.com.tw/api/v1/health",
        )
        error_rate, latency_p95 = probe_health(health_url)
        slo_breach = error_rate > SLO_ERROR_RATE_MAX or latency_p95 > SLO_LATENCY_P95_MAX_MS
        metrics = {
            "error_rate": round(error_rate, 4),
            "latency_p95": round(latency_p95, 2),
            "slo_breach": slo_breach,
            "source": "health_probe",
            "health_url": health_url,
        }
    metrics["collected_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    return metrics


def main() -> None:
    out_path = os.environ.get("METRICS_OUT", "")
    metrics = collect()
    text = json.dumps(metrics, indent=2, ensure_ascii=False)
    print(text)
    if out_path:
        p = Path(out_path)
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text + "\n", encoding="utf-8")
    sys.exit(0)


if __name__ == "__main__":
    main()
