#!/usr/bin/env python3
"""Parse diagnose-xindian-capacity.sh output → demand-capacity model + Founder brief.

No fabricated production numbers: missing sections become explicit data_gaps.
"""
from __future__ import annotations

import json
import math
import re
import sys
from collections import defaultdict
from pathlib import Path

TARGET_REVENUE = 1_000_000
CAMPUS_ID = 9
# Assumed peak teaching window hours/week per FTE when preferred availability is unknown.
# Documented assumption — not observed preference data.
ASSUMED_PEAK_HOURS_PER_FTE_WEEK = {
    "conservative": 12.0,  # ~3 evenings × 4h
    "base": 16.0,
    "growth": 20.0,
}
# Same-day cross-campus travel buffer (minutes). No DB field — assumption.
TRAVEL_BUFFER_MIN = 60
# Peak utilization cap before quality/conflict risk (fraction of peak teacher-slots).
UTIL_CAP = {"conservative": 0.70, "base": 0.80, "growth": 0.90}


def sections(text: str) -> dict[str, list[str]]:
    out: dict[str, list[str]] = {}
    cur = None
    for line in text.splitlines():
        if line.startswith("--- ") and line.endswith(" ---"):
            cur = line[4:-4].strip()
            out[cur] = []
            continue
        if cur is not None and line.strip() and not line.startswith("==="):
            out[cur].append(line.strip())
    return out


def pipe_rows(lines: list[str]) -> list[list[str]]:
    return [ln.split("|") for ln in lines if "|" in ln]


def fnum(x: str) -> float:
    try:
        return float(x)
    except Exception:
        return 0.0


def build(sec: dict[str, list[str]]) -> dict:
    gaps = []
    for ln in sec.get("data_gaps_declared", []):
        gaps.append(ln)

    campus = pipe_rows(sec.get("campus_identity", []))
    if not campus or campus[0][0] != str(CAMPUS_ID):
        gaps.append("campus_identity_mismatch_or_missing")

    monthly_cash = {}
    for r in pipe_rows(sec.get("monthly_invoice_cash", [])):
        if len(r) >= 3:
            monthly_cash[r[0]] = {
                "invoices": int(fnum(r[1])),
                "paid_amount": int(fnum(r[2])),
                "total_amount": int(fnum(r[3])) if len(r) > 3 else None,
            }

    monthly_sessions = {}
    for r in pipe_rows(sec.get("monthly_sessions_taught", [])):
        if len(r) >= 6:
            monthly_sessions[r[0]] = {
                "sessions": int(fnum(r[1])),
                "teachers": int(fnum(r[2])),
                "students": int(fnum(r[3])),
                "session_charge_sum": int(fnum(r[4])),
                "minutes": int(fnum(r[5])),
            }

    class_type = defaultdict(lambda: defaultdict(int))
    for r in pipe_rows(sec.get("class_type_mix_sessions", [])):
        if len(r) >= 3:
            class_type[r[0]][r[1]] += int(fnum(r[2]))

    concurrent = {}
    for r in pipe_rows(sec.get("concurrent_slot_headcount", [])):
        if len(r) >= 2:
            concurrent[r[0]] = {
                "slots": int(fnum(r[1])),
                "avg_head": fnum(r[2]) if len(r) > 2 else None,
                "max_head": int(fnum(r[3])) if len(r) > 3 else None,
            }

    peaks = []
    for r in pipe_rows(sec.get("peak_demand_weekday_hour", [])):
        if len(r) >= 4:
            peaks.append(
                {
                    "dow": int(fnum(r[0])),  # MySQL DAYOFWEEK 1=Sun
                    "hour": int(fnum(r[1])),
                    "sessions": int(fnum(r[2])),
                    "teachers": int(fnum(r[3])),
                }
            )

    subj_peaks = []
    for r in pipe_rows(sec.get("peak_demand_subject_weekday_hour", [])):
        if len(r) >= 5:
            subj_peaks.append(
                {
                    "subject": r[0],
                    "dow": int(fnum(r[1])),
                    "hour": int(fnum(r[2])),
                    "sessions": int(fnum(r[3])),
                    "teachers": int(fnum(r[4])),
                }
            )

    teachers = []
    for r in pipe_rows(sec.get("teachers_active_at_xindian", [])):
        if len(r) >= 5:
            teachers.append(
                {
                    "teacher_id": int(fnum(r[0])),
                    "sessions": int(fnum(r[1])),
                    "days": int(fnum(r[2])),
                    "subjects": int(fnum(r[3])),
                    "minutes": int(fnum(r[4])),
                    "earliest": r[5] if len(r) > 5 else None,
                    "latest": r[6] if len(r) > 6 else None,
                }
            )

    cross = []
    for r in pipe_rows(sec.get("teacher_home_campus_and_cross", [])):
        if len(r) >= 5:
            cross.append(
                {
                    "teacher_id": int(fnum(r[0])),
                    "home_campus": r[1],
                    "xindian_sessions": int(fnum(r[2])),
                    "other_sessions": int(fnum(r[3])),
                    "other_campus_count": int(fnum(r[4])),
                }
            )

    same_day = pipe_rows(sec.get("same_day_cross_campus_events", []))
    same_day_cross = None
    if same_day and len(same_day[0]) >= 3:
        same_day_cross = {
            "teacher_day_events": int(fnum(same_day[0][0])),
            "teachers": int(fnum(same_day[0][1])),
            "distinct_days": int(fnum(same_day[0][2])),
        }

    rev_proxy = pipe_rows(sec.get("revenue_per_delivered_session_proxy", []))
    revenue_per_session = None
    if rev_proxy and len(rev_proxy[0]) >= 3:
        # Prefer Charge/SessionCount avg when session_charge sparse
        charge_per = fnum(rev_proxy[0][1])
        rate = fnum(rev_proxy[0][2])
        sess_charge = fnum(rev_proxy[0][3])
        revenue_per_session = charge_per or rate or sess_charge or None
        if not charge_per and not rate and not sess_charge:
            gaps.append("revenue_per_session_unobserved")

    if not monthly_cash:
        gaps.append("monthly_invoice_cash_missing")
    if not monthly_sessions:
        gaps.append("monthly_sessions_missing")
    if not teachers:
        gaps.append("no_active_xindian_teachers_in_window")

    # Calibration
    paid_vals = [v["paid_amount"] for v in monthly_cash.values()]
    avg_paid = sum(paid_vals) / len(paid_vals) if paid_vals else None
    sess_vals = [v["sessions"] for v in monthly_sessions.values()]
    avg_sessions = sum(sess_vals) / len(sess_vals) if sess_vals else None
    avg_teachers = (
        sum(v["teachers"] for v in monthly_sessions.values()) / len(monthly_sessions)
        if monthly_sessions
        else None
    )
    avg_minutes = (
        sum(v["minutes"] for v in monthly_sessions.values()) / len(monthly_sessions)
        if monthly_sessions
        else None
    )

    # Implied revenue/session from cash÷delivered-ish sessions (attended+scheduled proxy).
    # Prefer attended-only if we had it; probe mixes scheduled — flag assumption.
    gaps.append("sessions_metric_includes_scheduled_not_only_attended")
    cash_per_session = None
    if avg_paid and avg_sessions:
        cash_per_session = avg_paid / avg_sessions

    rps = revenue_per_session or cash_per_session
    if rps is None:
        gaps.append("cannot_compute_revenue_per_session")

    # Peak teacher-slot demand: top N weekday-hour cells' teacher counts (avg across months ≈ /3)
    months_n = max(len(monthly_sessions), 1)
    peak_cells = sorted(peaks, key=lambda x: -x["sessions"])[:15]
    # Concurrent teacher demand at a peak cell ≈ teachers field (already distinct teachers in that cell over window)
    # Convert to monthly peak concurrent approx: sessions/months / typical load — use max teachers in top cell / months? 
    # Better: max over cells of (sessions/months) as session-slots/month at that hour, demand teachers ≈ ceil(sessions_per_week_at_slot)
    # Approximate weekly peak concurrent demand from top cell:
    weekly_peak_teacher_demand = 0.0
    if peak_cells:
        top = peak_cells[0]
        # sessions in 3 months at that dow+hour → weekly ≈ sessions / (weeks in window)
        weeks = 13.0  # ~3 months
        weekly_sessions_at_peak = top["sessions"] / weeks
        # Each session needs 1 teacher; concurrent within hour bucket ≈ weekly_sessions if all same week occurrence
        # For a single weekday-hour, occurrences per week ≈ sessions/(weeks) 
        weekly_peak_teacher_demand = weekly_sessions_at_peak  # 1 teacher per session slot

    headcount = len(teachers)
    # FTE proxy from minutes: 1 FTE ≈ ASSUMED peak hours * 60 * 4.33 weeks
    def hours_to_fte(hours_per_week: float, minutes_month: float) -> float:
        if hours_per_week <= 0:
            return float("nan")
        return (minutes_month / 60.0) / (hours_per_week * 4.33)

    observed_fte = None
    if avg_minutes is not None:
        observed_fte = hours_to_fte(ASSUMED_PEAK_HOURS_PER_FTE_WEEK["base"], avg_minutes)

    # Transfer candidates: teach at Xindian some, but have other campus load OR home != 9
    transfer_candidates = [
        c
        for c in cross
        if c["other_sessions"] > 0 or (c["home_campus"] not in ("none", str(CAMPUS_ID), "9"))
    ]
    # Pure Xindian-capable (active there); transferable FROM elsewhere = other_sessions>0 with xindian already
    # Residual transfer supply: teachers with other campus primary who already appear at Xindian (proven transferable)
    proven_transfer = [c for c in cross if c["other_sessions"] > 0]
    # Additional transfer NOT in data: teachers who never taught Xindian — NOT inventing; gap listed
    gaps.append("no_inventory_of_never_xindian_teachers_with_matching_subjects_availability")

    # Subject×dow×hour gap ranking: demand sessions vs distinct teachers in cell
    gap_rank = []
    for sp in subj_peaks[:40]:
        # scarcity = sessions / max(teachers,1) over window — high means overloaded cell
        scarcity = sp["sessions"] / max(sp["teachers"], 1)
        gap_rank.append({**sp, "scarcity": round(scarcity, 2)})
    gap_rank.sort(key=lambda x: (-x["scarcity"], -x["sessions"]))

    scenarios = {}
    for name in ("conservative", "base", "growth"):
        util = UTIL_CAP[name]
        hrs = ASSUMED_PEAK_HOURS_PER_FTE_WEEK[name]
        if rps is None or avg_sessions is None or weekly_peak_teacher_demand <= 0:
            scenarios[name] = {"error": "insufficient_calibration"}
            continue
        # Current max sustainable revenue ≈ current paid * (util / current_util_proxy)
        # Current util proxy: weekly_peak_teacher_demand / headcount (capped)
        cur_util = min(1.0, weekly_peak_teacher_demand / max(headcount, 1))
        # Max sustainable at same mix: scale sessions until peak util hits cap
        if cur_util <= 0:
            scale = 1.0
        else:
            scale = util / cur_util
        max_rev = (avg_paid or 0) * scale
        # Sessions needed for 1M
        sessions_needed = TARGET_REVENUE / rps
        # Peak demand scales linearly with sessions
        peak_demand_at_1m = weekly_peak_teacher_demand * (sessions_needed / max(avg_sessions, 1))
        # Teachers needed at util cap: peak_demand / util
        teachers_needed_peak = math.ceil(peak_demand_at_1m / util)
        # FTE from hours: total teaching hours/month at 1M
        minutes_per_session = (avg_minutes / avg_sessions) if avg_sessions else 120
        hours_month_1m = sessions_needed * (minutes_per_session / 60.0)
        fte_needed = hours_month_1m / (hrs * 4.33)
        # Headcount: max(peak concurrent teachers needed, ceil(fte / part_time_factor))
        # part_time_factor: observed sessions distribution — use headcount/fte if available
        pt_factor = 0.65 if name != "growth" else 0.75  # assumption: many part-timers
        gaps.append(f"part_time_factor_assumed_{name}={pt_factor}")
        headcount_needed = max(teachers_needed_peak, math.ceil(fte_needed / pt_factor))
        available = headcount
        # Transfer: count proven_transfer as already in available; additional transfer unknown
        hire = max(0, headcount_needed - available)
        residual_after_unknown_transfer = hire  # cannot reduce without inventing pool
        scenarios[name] = {
            "assumed_peak_hours_per_fte_week": hrs,
            "util_cap": util,
            "current_util_proxy": round(cur_util, 3),
            "max_sustainable_monthly_revenue_ntd": int(round(max_rev)),
            "sessions_needed_for_1m": int(round(sessions_needed)),
            "fte_needed": round(fte_needed, 2),
            "headcount_needed": headcount_needed,
            "available_headcount_observed": available,
            "hires_needed_if_no_new_transfers": hire,
            "proven_cross_campus_teachers": len(proven_transfer),
            "residual_gap_after_unknown_transfer_pool": residual_after_unknown_transfer,
            "note": "Cannot shrink hire count using unobserved other-campus free capacity",
        }

    # Priority hires from gap_rank top subjects/slots
    priority_subjects = []
    seen = set()
    for g in gap_rank:
        if g["subject"] not in seen:
            priority_subjects.append(g["subject"])
            seen.add(g["subject"])
        if len(priority_subjects) >= 5:
            break

    priority_slots = []
    dow_map = {1: "日", 2: "一", 3: "二", 4: "三", 5: "四", 6: "五", 7: "六"}
    for g in gap_rank[:8]:
        priority_slots.append(
            f"{g['subject']} 週{dow_map.get(g['dow'], g['dow'])} {g['hour']:02d}:00 (scarcity={g['scarcity']})"
        )

    base = scenarios.get("base") or {}
    founder = {
        "recommended_teacher_headcount": base.get("headcount_needed"),
        "available_headcount_observed_jun_aug": headcount,
        "recommended_new_hires": base.get("hires_needed_if_no_new_transfers"),
        "priority_subjects": priority_subjects,
        "priority_slots": priority_slots,
        "estimated_full_load_revenue_ntd": base.get("max_sustainable_monthly_revenue_ntd"),
        "max_bottleneck": (
            priority_slots[0] if priority_slots else "insufficient_peak_data"
        ),
        "target_revenue_ntd": TARGET_REVENUE,
        "scenarios": scenarios,
        "calibration": {
            "months": sorted(monthly_cash.keys()),
            "avg_monthly_paid_ntd": int(round(avg_paid)) if avg_paid else None,
            "avg_monthly_sessions": int(round(avg_sessions)) if avg_sessions else None,
            "avg_monthly_active_teachers": round(avg_teachers, 2) if avg_teachers else None,
            "revenue_per_session_ntd": int(round(rps)) if rps else None,
            "revenue_per_session_source": (
                "studentclass_charge_over_sessioncount_or_rate"
                if revenue_per_session
                else "paid_cash_div_sessions"
                if cash_per_session
                else None
            ),
            "class_type_mix": {m: dict(v) for m, v in class_type.items()},
            "concurrent_slot_buckets": concurrent,
            "same_day_cross_campus": same_day_cross,
            "travel_buffer_min_assumed": TRAVEL_BUFFER_MIN,
            "observed_fte_proxy_base_hours": round(observed_fte, 2) if observed_fte else None,
        },
        "evidence": {
            "campus_id": CAMPUS_ID,
            "branches_api": "GET https://daan.lifenet.com.tw/api/v1/branches → id=9 xindian",
            "probe_script": "scripts/diagnose-xindian-capacity.sh",
            "workflow": "Xindian capacity diagnose (read-only)",
            "window": "2026-06-01 .. 2026-08-31",
        },
        "data_gaps": sorted(set(gaps)),
        "assumptions": [
            "No teacher preferred-availability table; busy-only API — open capacity inferred from non-busy peak hours assumption",
            f"Peak hours/FTE/week assumed: {ASSUMED_PEAK_HOURS_PER_FTE_WEEK}",
            f"Utilization caps: {UTIL_CAP}",
            f"Travel buffer {TRAVEL_BUFFER_MIN}m assumed; same-day cross-campus events used as stress signal only",
            "1M scaling assumes same subject×slot mix and class-type mix as Jun–Aug",
            "Transfer pool limited to teachers already observed teaching at Xindian; others not invented",
            "Session counts include scheduled+attended family statuses as listed in probe",
        ],
        "transferable_teachers_summary": {
            "proven_cross_campus_count": len(proven_transfer),
            "sample_teacher_ids": [c["teacher_id"] for c in proven_transfer[:15]],
            "cannot_quantify_additional_transfer_without_availability": True,
        },
        "gap_rank_subject_dow_hour_top": gap_rank[:15],
    }
    return founder


def render_markdown(d: dict) -> str:
    lines = []
    lines.append("# Founder one-pager — 新店月營收 100 萬教師產能")
    lines.append("")
    lines.append(f"- **建議教師總數 (Base headcount)**：{d.get('recommended_teacher_headcount')}")
    lines.append(f"- **現有人力可用數 (Jun–Aug 有授課)**：{d.get('available_headcount_observed_jun_aug')}")
    lines.append(f"- **建議新增人數**：{d.get('recommended_new_hires')}")
    lines.append(f"- **優先招聘科目**：{', '.join(d.get('priority_subjects') or []) or '—'}")
    lines.append("- **優先招聘可上班時段**：")
    for s in d.get("priority_slots") or []:
        lines.append(f"  - {s}")
    lines.append(f"- **預估滿載營收 (Base max sustainable)**：NT$ {d.get('estimated_full_load_revenue_ntd')}")
    lines.append(f"- **最大 bottleneck**：{d.get('max_bottleneck')}")
    lines.append("")
    lines.append("## Scenarios")
    for name, sc in (d.get("scenarios") or {}).items():
        lines.append(f"### {name}")
        if sc.get("error"):
            lines.append(f"- error: {sc['error']}")
            continue
        lines.append(f"- max sustainable monthly revenue: NT$ {sc['max_sustainable_monthly_revenue_ntd']}")
        lines.append(f"- FTE needed @1M: {sc['fte_needed']}")
        lines.append(f"- headcount needed: {sc['headcount_needed']}")
        lines.append(f"- hires if no new transfers: {sc['hires_needed_if_no_new_transfers']}")
        lines.append(f"- proven cross-campus teachers: {sc['proven_cross_campus_teachers']}")
        lines.append(f"- residual gap (unobserved transfer pool): {sc['residual_gap_after_unknown_transfer_pool']}")
    lines.append("")
    lines.append("## Calibration")
    cal = d.get("calibration") or {}
    lines.append(f"- months: {cal.get('months')}")
    lines.append(f"- avg monthly paid: {cal.get('avg_monthly_paid_ntd')}")
    lines.append(f"- avg monthly sessions: {cal.get('avg_monthly_sessions')}")
    lines.append(f"- avg active teachers: {cal.get('avg_monthly_active_teachers')}")
    lines.append(f"- revenue/session: {cal.get('revenue_per_session_ntd')} ({cal.get('revenue_per_session_source')})")
    lines.append(f"- concurrent buckets: {cal.get('concurrent_slot_buckets')}")
    lines.append(f"- same-day cross-campus: {cal.get('same_day_cross_campus')}")
    lines.append("")
    lines.append("## Evidence")
    for k, v in (d.get("evidence") or {}).items():
        lines.append(f"- {k}: `{v}`")
    lines.append("")
    lines.append("## Assumptions")
    for a in d.get("assumptions") or []:
        lines.append(f"- {a}")
    lines.append("")
    lines.append("## Data gaps (not fabricated)")
    for g in d.get("data_gaps") or []:
        lines.append(f"- {g}")
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    path = Path(sys.argv[1] if len(sys.argv) > 1 else "out/xindian-capacity.txt")
    text = path.read_text(encoding="utf-8", errors="replace")
    if "read_only=1" not in text:
        print("ERROR: probe output missing read_only=1", file=sys.stderr)
        return 2
    sec = sections(text)
    model = build(sec)
    out_json = path.with_suffix(".model.json")
    out_md = path.with_suffix(".founder.md")
    out_json.write_text(json.dumps(model, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    out_md.write_text(render_markdown(model), encoding="utf-8")
    print(render_markdown(model))
    print(f"\nWrote {out_json} and {out_md}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
