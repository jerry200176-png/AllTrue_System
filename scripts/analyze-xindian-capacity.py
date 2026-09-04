#!/usr/bin/env python3
"""Parse diagnose-xindian-capacity.sh TSV output → demand-capacity model + Founder brief.

Never fabricates production numbers; missing inputs become data_gaps.
"""
from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from pathlib import Path

TARGET_REVENUE = 1_000_000
CAMPUS_ID = 9
WEEKS_IN_WINDOW = 13.0  # Jun–Aug ~13 weeks
ASSUMED_PEAK_HOURS_PER_FTE_WEEK = {
    "conservative": 12.0,
    "base": 16.0,
    "growth": 20.0,
}
TRAVEL_BUFFER_MIN = 60
UTIL_CAP = {"conservative": 0.70, "base": 0.80, "growth": 0.90}
# Explicit scenario knobs for never-xindian transfer pool (NOT claiming they can come —
# shows residual IF Founder assumes this yield). Default residual uses yield=0.
TRANSFER_YIELD_ASSUMPTION = {
    "conservative": 0.00,
    "base": 0.10,
    "growth": 0.25,
}


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


def rows(lines: list[str]) -> list[list[str]]:
    out: list[list[str]] = []
    for ln in lines:
        if "\t" in ln:
            out.append(ln.split("\t"))
        elif "|" in ln:
            out.append(ln.split("|"))
        elif ln.strip():
            out.append([ln.strip()])
    return out


def fnum(x: str) -> float:
    try:
        return float(x)
    except Exception:
        return 0.0


def build(sec: dict[str, list[str]]) -> dict:
    gaps: list[str] = []
    for ln in sec.get("data_gaps_declared", []):
        gaps.append(ln.split("\t")[0] if "\t" in ln else ln)

    campus = rows(sec.get("campus_identity", []))
    if not campus or campus[0][0] != str(CAMPUS_ID):
        gaps.append("campus_identity_mismatch_or_missing")

    monthly_cash = {}
    for r in rows(sec.get("monthly_invoice_cash", [])):
        if len(r) >= 3:
            monthly_cash[r[0]] = {
                "invoices": int(fnum(r[1])),
                "paid_amount": int(fnum(r[2])),
                "total_amount": int(fnum(r[3])) if len(r) > 3 else None,
            }

    # Prefer attended-only for teaching load / utilization
    monthly_attended = {}
    for r in rows(sec.get("monthly_sessions_attended_only", [])):
        if len(r) >= 4:
            monthly_attended[r[0]] = {
                "sessions": int(fnum(r[1])),
                "teachers": int(fnum(r[2])),
                "minutes": int(fnum(r[3])),
            }
    monthly_sessions = {}
    for r in rows(sec.get("monthly_sessions_taught", [])):
        if len(r) >= 6:
            monthly_sessions[r[0]] = {
                "sessions": int(fnum(r[1])),
                "teachers": int(fnum(r[2])),
                "students": int(fnum(r[3])),
                "session_charge_sum": int(fnum(r[4])),
                "minutes": int(fnum(r[5])),
            }
    if not monthly_attended:
        gaps.append("attended_only_section_missing_falling_back_to_scheduled_mix")
        monthly_attended = {
            k: {"sessions": v["sessions"], "teachers": v["teachers"], "minutes": v["minutes"]}
            for k, v in monthly_sessions.items()
        }

    class_type: dict[str, dict[str, int]] = defaultdict(lambda: defaultdict(int))
    for r in rows(sec.get("class_type_mix_sessions", [])):
        if len(r) >= 3:
            class_type[r[0]][r[1]] += int(fnum(r[2]))

    concurrent = {}
    for r in rows(sec.get("concurrent_slot_headcount", [])):
        if len(r) >= 2:
            concurrent[r[0]] = {
                "slots": int(fnum(r[1])),
                "avg_head": fnum(r[2]) if len(r) > 2 else None,
                "max_head": int(fnum(r[3])) if len(r) > 3 else None,
            }

    peaks = []
    for r in rows(sec.get("peak_demand_weekday_hour", [])):
        if len(r) >= 4:
            peaks.append(
                {
                    "dow": int(fnum(r[0])),
                    "hour": int(fnum(r[1])),
                    "sessions": int(fnum(r[2])),
                    "teachers": int(fnum(r[3])),
                }
            )

    subj_peaks = []
    for r in rows(sec.get("peak_demand_subject_weekday_hour", [])):
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
    for r in rows(sec.get("teachers_active_at_xindian", [])):
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
    for r in rows(sec.get("teacher_home_campus_and_cross", [])):
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

    same_day = rows(sec.get("same_day_cross_campus_events", []))
    same_day_cross = None
    if same_day and len(same_day[0]) >= 3:
        same_day_cross = {
            "teacher_day_events": int(fnum(same_day[0][0])),
            "teachers": int(fnum(same_day[0][1])),
            "distinct_days": int(fnum(same_day[0][2])),
        }

    transfer_pool = []
    for r in rows(sec.get("transfer_pool_other_campus_subject_overlap", [])):
        if len(r) >= 4:
            transfer_pool.append(
                {
                    "teacher_id": int(fnum(r[0])),
                    "other_sessions": int(fnum(r[1])),
                    "home_like_campus": r[2],
                    "overlapping_subjects": int(fnum(r[3])),
                }
            )

    usercampus_n = None
    uc = rows(sec.get("usercampus_xindian_teacher_count", []))
    if uc:
        usercampus_n = int(fnum(uc[0][0]))

    stock = rows(sec.get("active_contracts_stock", []))
    stock_info = None
    if stock and len(stock[0]) >= 6:
        stock_info = {
            "active_courses": int(fnum(stock[0][0])),
            "charge_sum": int(fnum(stock[0][1])),
            "pay_sum": int(fnum(stock[0][2])),
            "remaining_sessions": int(fnum(stock[0][3])),
            "session_count_sum": int(fnum(stock[0][4])),
            "avg_rate": fnum(stock[0][5]),
        }
        if stock_info["pay_sum"] == 0:
            gaps.append("studentclass_pay_stock_is_zero_use_invoice_paidamount_for_cash")

    rev_proxy = rows(sec.get("revenue_per_delivered_session_proxy", []))
    charge_per_session = None
    if rev_proxy and len(rev_proxy[0]) >= 2:
        charge_per_session = fnum(rev_proxy[0][1]) or fnum(rev_proxy[0][2]) or None

    if not monthly_cash:
        gaps.append("monthly_invoice_cash_missing")
    if not monthly_attended:
        gaps.append("monthly_sessions_missing")
    if not teachers:
        gaps.append("no_active_xindian_teachers_in_window")

    paid_vals = [v["paid_amount"] for v in monthly_cash.values()]
    avg_paid = sum(paid_vals) / len(paid_vals) if paid_vals else None
    sess_vals = [v["sessions"] for v in monthly_attended.values()]
    avg_sessions = sum(sess_vals) / len(sess_vals) if sess_vals else None
    avg_teachers_m = (
        sum(v["teachers"] for v in monthly_attended.values()) / len(monthly_attended)
        if monthly_attended
        else None
    )
    avg_minutes = (
        sum(v["minutes"] for v in monthly_attended.values()) / len(monthly_attended)
        if monthly_attended
        else None
    )

    cash_per_session = (avg_paid / avg_sessions) if (avg_paid and avg_sessions) else None
    # Prefer contract charge/session for capacity economics; report both.
    rps = charge_per_session or cash_per_session
    rps_source = (
        "avg_studentclass_charge_per_sessioncount"
        if charge_per_session
        else "invoice_paid_div_attended_sessions"
        if cash_per_session
        else None
    )
    if rps is None:
        gaps.append("cannot_compute_revenue_per_session")

    # Peak concurrent teacher demand (weekly): max over weekday-hour of sessions/weeks
    # (each session needs one teacher in that hour bucket).
    weekly_peak_teacher_demand = 0.0
    peak_cell = None
    if peaks:
        peak_cell = max(peaks, key=lambda x: x["sessions"])
        weekly_peak_teacher_demand = peak_cell["sessions"] / WEEKS_IN_WINDOW

    headcount_active = len(teachers)
    proven_transfer = [c for c in cross if c["other_sessions"] > 0]

    gap_rank = []
    for sp in subj_peaks:
        scarcity = sp["sessions"] / max(sp["teachers"], 1)
        gap_rank.append({**sp, "scarcity": round(scarcity, 2)})
    gap_rank.sort(key=lambda x: (-x["scarcity"], -x["sessions"]))

    scenarios = {}
    for name in ("conservative", "base", "growth"):
        util = UTIL_CAP[name]
        hrs = ASSUMED_PEAK_HOURS_PER_FTE_WEEK[name]
        yield_frac = TRANSFER_YIELD_ASSUMPTION[name]
        if rps is None or avg_sessions is None or weekly_peak_teacher_demand <= 0 or avg_paid is None:
            scenarios[name] = {"error": "insufficient_calibration"}
            continue

        # Current peak utilization vs active headcount
        cur_util = min(1.0, weekly_peak_teacher_demand / max(headcount_active, 1))
        scale_to_cap = (util / cur_util) if cur_util > 0 else 1.0
        max_rev = avg_paid * scale_to_cap

        sessions_needed = TARGET_REVENUE / rps
        scale_1m = sessions_needed / max(avg_sessions, 1)
        peak_demand_1m = weekly_peak_teacher_demand * scale_1m
        teachers_needed_peak = int(math.ceil(peak_demand_1m / util))

        minutes_per_session = (avg_minutes / avg_sessions) if avg_sessions else 120.0
        hours_month_1m = sessions_needed * (minutes_per_session / 60.0)
        fte_needed = hours_month_1m / (hrs * 4.33)

        # Observed part-time factor: active headcount / FTE at current load
        cur_fte = (avg_minutes / 60.0) / (hrs * 4.33) if avg_minutes else None
        pt_factor = (
            min(0.95, max(0.35, headcount_active / cur_fte))
            if cur_fte and cur_fte > 0
            else 0.65
        )
        headcount_needed = max(teachers_needed_peak, int(math.ceil(fte_needed / pt_factor)))

        available = headcount_active
        hire_if_no_transfer = max(0, headcount_needed - available)
        # Transfer yield applies ONLY to never-xindian subject-overlap pool; explicit assumption.
        assumed_transfer_hires = int(math.floor(len(transfer_pool) * yield_frac))
        hire_after_assumed_transfer = max(0, hire_if_no_transfer - assumed_transfer_hires)

        scenarios[name] = {
            "assumed_peak_hours_per_fte_week": hrs,
            "util_cap": util,
            "current_peak_util_proxy": round(cur_util, 3),
            "max_sustainable_monthly_revenue_ntd": int(round(max_rev)),
            "sessions_needed_for_1m": int(round(sessions_needed)),
            "scale_vs_current_sessions": round(scale_1m, 2),
            "weekly_peak_teacher_demand_at_1m": round(peak_demand_1m, 2),
            "fte_needed": round(fte_needed, 2),
            "observed_pt_factor": round(pt_factor, 3),
            "headcount_needed": headcount_needed,
            "available_headcount_active_in_window": available,
            "hires_needed_if_transfer_yield_0": hire_if_no_transfer,
            "transfer_yield_assumption": yield_frac,
            "assumed_transfer_from_never_xindian_pool": assumed_transfer_hires,
            "hires_needed_after_assumed_transfer_yield": hire_after_assumed_transfer,
            "proven_already_cross_campus_teachers": len(proven_transfer),
            "never_xindian_subject_overlap_pool": len(transfer_pool),
            "note": (
                "Transfer yield is an explicit Founder scenario knob, not observed free capacity. "
                "Availability/travel not stored — pool cannot be scheduled-verified."
            ),
        }

    dow_map = {1: "日", 2: "一", 3: "二", 4: "三", 5: "四", 6: "五", 7: "六"}
    priority_subjects = []
    seen = set()
    for g in gap_rank:
        if g["subject"] not in seen:
            priority_subjects.append(g["subject"])
            seen.add(g["subject"])
        if len(priority_subjects) >= 5:
            break
    priority_slots = []
    for g in gap_rank[:8]:
        priority_slots.append(
            f"{g['subject']} 週{dow_map.get(g['dow'], g['dow'])} {g['hour']:02d}:00 "
            f"(sessions={g['sessions']}, teachers={g['teachers']}, scarcity={g['scarcity']})"
        )

    # Class-type mix share (window)
    mix_totals: dict[str, int] = defaultdict(int)
    for m in class_type.values():
        for k, v in m.items():
            mix_totals[k] += v
    mix_sum = sum(mix_totals.values()) or 1

    base = scenarios.get("base") or {}
    founder = {
        "recommended_teacher_headcount": base.get("headcount_needed"),
        "available_headcount_active_jun_aug": headcount_active,
        "usercampus_xindian_enrolled": usercampus_n,
        "recommended_new_hires_base_yield_10pct": base.get(
            "hires_needed_after_assumed_transfer_yield"
        ),
        "recommended_new_hires_if_no_new_transfers": base.get(
            "hires_needed_if_transfer_yield_0"
        ),
        "priority_subjects": priority_subjects,
        "priority_slots": priority_slots,
        "estimated_full_load_revenue_ntd": base.get("max_sustainable_monthly_revenue_ntd"),
        "max_bottleneck": priority_slots[0] if priority_slots else (
            f"dow={peak_cell['dow']} hour={peak_cell['hour']}" if peak_cell else "unknown"
        ),
        "target_revenue_ntd": TARGET_REVENUE,
        "scenarios": scenarios,
        "calibration": {
            "months": sorted(monthly_cash.keys()),
            "monthly_paid_ntd": {k: v["paid_amount"] for k, v in monthly_cash.items()},
            "avg_monthly_paid_ntd": int(round(avg_paid)) if avg_paid else None,
            "monthly_attended_sessions": {
                k: v["sessions"] for k, v in monthly_attended.items()
            },
            "avg_monthly_attended_sessions": int(round(avg_sessions)) if avg_sessions else None,
            "avg_monthly_active_teachers": round(avg_teachers_m, 2) if avg_teachers_m else None,
            "revenue_per_session_ntd": int(round(rps)) if rps else None,
            "revenue_per_session_source": rps_source,
            "cash_per_attended_session_ntd": int(round(cash_per_session))
            if cash_per_session
            else None,
            "class_type_mix_share": {
                k: round(v / mix_sum, 3) for k, v in sorted(mix_totals.items(), key=lambda x: -x[1])
            },
            "concurrent_slot_buckets": concurrent,
            "peak_cell": peak_cell,
            "weekly_peak_teacher_demand": round(weekly_peak_teacher_demand, 2),
            "same_day_cross_campus": same_day_cross,
            "travel_buffer_min_assumed": TRAVEL_BUFFER_MIN,
            "active_contracts_stock": stock_info,
            "one_on_n_note": (
                "ClassType mix is majority one_on_three/two; concurrent headcount buckets "
                "confirm real 1v2/1v3plus slots — capacity is teacher-time not student-seat only."
            ),
        },
        "evidence": {
            "campus_id": CAMPUS_ID,
            "campus_name": campus[0][1] if campus else "新店分校",
            "branches_api": "GET https://daan.lifenet.com.tw/api/v1/branches → id=9 code=xindian",
            "probe_script": "scripts/diagnose-xindian-capacity.sh",
            "workflow": "Xindian capacity diagnose (read-only)",
            "workflow_run": "33897312980",
            "window": "2026-06-01 .. 2026-08-31",
            "artifact": "out/xindian-capacity.txt",
        },
        "data_gaps": sorted(set(gaps)),
        "assumptions": [
            "No teacher preferred-availability table; open hours inferred only via assumed peak-hours/FTE and util caps",
            f"Peak hours/FTE/week: {ASSUMED_PEAK_HOURS_PER_FTE_WEEK}",
            f"Peak util caps: {UTIL_CAP}",
            f"Travel buffer {TRAVEL_BUFFER_MIN}m assumed; same-day cross-campus used as stress signal only",
            "1M path assumes same subject×weekday×hour mix and class-type mix as Jun–Aug",
            f"Never-xindian transfer yield scenario knobs: {TRANSFER_YIELD_ASSUMPTION} (not observed free slots)",
            "Cash calibration uses Invoice.PaidAmount by billing_period; StudentClass.Pay stock ignored when zero",
            "Revenue/session prefers StudentClass Charge/SessionCount on attended sessions",
            "Weekly peak teacher demand = max weekday-hour sessions / 13 weeks",
        ],
        "transferable_teachers_summary": {
            "proven_cross_campus_already_teaching_xindian": len(proven_transfer),
            "proven_sample_ids": [c["teacher_id"] for c in proven_transfer[:20]],
            "never_xindian_subject_overlap_candidates": len(transfer_pool),
            "never_xindian_sample_ids": [t["teacher_id"] for t in transfer_pool[:20]],
            "cannot_verify_slot_fit_without_availability": True,
        },
        "gap_rank_subject_dow_hour_top": gap_rank[:20],
    }
    return founder


def render_markdown(d: dict) -> str:
    lines = [
        "# Founder one-pager — 新店月營收 NT$100 萬教師產能",
        "",
        f"- **建議教師總數 (Base headcount)**：{d.get('recommended_teacher_headcount')}",
        f"- **現有人力可用數 (Jun–Aug 有授課堂次)**：{d.get('available_headcount_active_jun_aug')}"
        + (f"（UserCampus 登錄 {d.get('usercampus_xindian_enrolled')}，含未在窗內授課）"
           if d.get("usercampus_xindian_enrolled") is not None
           else ""),
        f"- **建議新增人數（主建議：不假設新跨校調度）**：{d.get('recommended_new_hires_if_no_new_transfers')}",
        f"- **建議新增人數（Base 情景：跨校 never-Xindian pool yield 10%）**：{d.get('recommended_new_hires_base_yield_10pct')} — 未驗證空檔，僅情景",
        f"- **優先招聘科目**：{', '.join(d.get('priority_subjects') or []) or '—'}",
        "- **優先招聘可上班時段**：",
    ]
    for s in d.get("priority_slots") or []:
        lines.append(f"  - {s}")
    lines += [
        f"- **預估滿載營收 (Base max sustainable @ util cap)**：NT$ {d.get('estimated_full_load_revenue_ntd')}",
        f"- **最大 bottleneck**：{d.get('max_bottleneck')}",
        "",
        "## Scenarios",
    ]
    for name, sc in (d.get("scenarios") or {}).items():
        lines.append(f"### {name}")
        if sc.get("error"):
            lines.append(f"- error: {sc['error']}")
            continue
        lines.append(
            f"- max sustainable monthly revenue: NT$ {sc['max_sustainable_monthly_revenue_ntd']}"
        )
        lines.append(f"- FTE needed @1M: {sc['fte_needed']}")
        lines.append(f"- headcount needed: {sc['headcount_needed']}")
        lines.append(
            f"- hires if transfer yield=0: {sc['hires_needed_if_transfer_yield_0']}"
        )
        lines.append(
            f"- transfer yield assumption: {sc['transfer_yield_assumption']} "
            f"→ assumed transfers {sc['assumed_transfer_from_never_xindian_pool']} "
            f"→ hires after: {sc['hires_needed_after_assumed_transfer_yield']}"
        )
        lines.append(
            f"- proven cross-campus (already at Xindian): {sc['proven_already_cross_campus_teachers']}"
        )
        lines.append(
            f"- never-xindian subject-overlap pool size: {sc['never_xindian_subject_overlap_pool']}"
        )
        lines.append(f"- note: {sc['note']}")
    cal = d.get("calibration") or {}
    lines += [
        "",
        "## Calibration (production read-only)",
        f"- months: {cal.get('months')}",
        f"- monthly paid (Invoice): {cal.get('monthly_paid_ntd')}",
        f"- avg monthly paid: NT$ {cal.get('avg_monthly_paid_ntd')}",
        f"- monthly attended sessions: {cal.get('monthly_attended_sessions')}",
        f"- avg attended sessions/mo: {cal.get('avg_monthly_attended_sessions')}",
        f"- avg active teachers/mo: {cal.get('avg_monthly_active_teachers')}",
        f"- revenue/session: NT$ {cal.get('revenue_per_session_ntd')} ({cal.get('revenue_per_session_source')})",
        f"- cash/attended session: NT$ {cal.get('cash_per_attended_session_ntd')}",
        f"- class_type mix share: {cal.get('class_type_mix_share')}",
        f"- concurrent buckets: {cal.get('concurrent_slot_buckets')}",
        f"- peak cell: {cal.get('peak_cell')} → weekly peak teacher demand {cal.get('weekly_peak_teacher_demand')}",
        f"- same-day cross-campus: {cal.get('same_day_cross_campus')}",
        f"- active contract stock: {cal.get('active_contracts_stock')}",
        f"- {cal.get('one_on_n_note')}",
        "",
        "## Evidence",
    ]
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
    xfer = d.get("transferable_teachers_summary") or {}
    lines += [
        "",
        "## Cross-campus transfer",
        f"- already teaching Xindian + other campuses: {xfer.get('proven_cross_campus_already_teaching_xindian')} "
        f"(sample IDs {xfer.get('proven_sample_ids')})",
        f"- never taught Xindian but subject-overlap candidates: {xfer.get('never_xindian_subject_overlap_candidates')} "
        f"(sample IDs {xfer.get('never_xindian_sample_ids')})",
        "- cannot confirm free peak slots or travel-feasible windows without preferred availability data",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    path = Path(sys.argv[1] if len(sys.argv) > 1 else "out/xindian-capacity.txt")
    text = path.read_text(encoding="utf-8", errors="replace")
    if "read_only=1" not in text:
        print("ERROR: probe output missing read_only=1", file=sys.stderr)
        return 2
    model = build(sections(text))
    out_json = path.with_suffix(".model.json")
    out_md = path.with_suffix(".founder.md")
    out_json.write_text(json.dumps(model, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    md = render_markdown(model)
    out_md.write_text(md, encoding="utf-8")
    print(md)
    print(f"\nWrote {out_json} and {out_md}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
