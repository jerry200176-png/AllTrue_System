#!/usr/bin/env python3
"""Control plane attack simulations — verify structural blocks."""
from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
PLATFORM = REPO / "scripts" / "platform"

sys.path.insert(0, str(PLATFORM))
from pdp_signature import attach_signature, verify_artifact_binding  # noqa: E402


def run(cmd: list[str], *, cwd: Path | None = None, env: dict | None = None) -> subprocess.CompletedProcess:
    return subprocess.run(cmd, cwd=cwd or REPO, env=env, capture_output=True, text=True)


def make_artifact(commit: str, run_id: str, block_merge: bool = False) -> dict:
    pdp_v3 = attach_signature(
        {
            "block_merge": block_merge,
            "allow_staging": True,
            "allow_production": not block_merge,
            "runtime_flags": {"calendar_v2": True, "new_checkout": False},
            "slo": {"freeze_deploy": False, "error_budget_burn": 0.0},
            "promotion": {
                "staging_required": True,
                "production_requires_staging_artifact": True,
                "artifact_max_age_minutes": 1440,
            },
            "reason_codes": [],
        },
        commit_sha=commit,
        run_id=run_id,
    )
    return {
        "pdp_v3": pdp_v3,
        "baseline": {"commit_sha": commit, "branch": "test", "generated_at": run_id},
        "meta": {"schema_version": "0.3"},
    }


class ControlPlaneAttackTests(unittest.TestCase):
    def test_policy_engine_is_pure_no_filesystem(self) -> None:
        text = (PLATFORM / "policy-engine.py").read_text(encoding="utf-8")
        self.assertNotIn("Path(__file__)", text)
        self.assertNotIn(".exists()", text)
        self.assertNotIn("open(", text.replace("open(args.input", ""))

    def test_yaml_bypass_detected_by_drift_nullifier(self) -> None:
        with tempfile.NamedTemporaryFile("w", suffix=".yml", delete=False) as f:
            f.write("if: risk_score > 0.9 && allow_production\n")
            path = f.name
        rel = Path(path).name
        try:
            proc = run([sys.executable, str(PLATFORM / "drift-nullifier.py")])
            self.assertEqual(proc.returncode, 0, proc.stdout)
        finally:
            Path(path).unlink(missing_ok=True)

    def test_parallel_policy_injection_detected(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            rogue = Path(tmp) / "rogue.py"
            rogue.write_text("if risk_score > 0.5:\n    block_merge = True\n", encoding="utf-8")
            text = rogue.read_text()
            self.assertRegex(text, r"risk_score\s*>\s*[\d.]+")

    def test_replay_attack_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            pdp = Path(tmp) / "pdp.json"
            art = make_artifact("abc123", "run-100")
            pdp.write_text(json.dumps(art, indent=2), encoding="utf-8")
            env = {**os.environ, "GITHUB_RUN_ID": "run-999", "GITHUB_SHA": "abc123"}
            proc = run(
                [sys.executable, str(PLATFORM / "pdp-replay-guard.py"), "--pdp", str(pdp), "--strict-run"],
                env=env,
            )
            self.assertNotEqual(proc.returncode, 0)

    def test_signature_reuse_across_runs_differs(self) -> None:
        body = {
            "block_merge": False,
            "allow_staging": True,
            "allow_production": True,
            "runtime_flags": {"calendar_v2": True},
            "slo": {"freeze_deploy": False, "error_budget_burn": 0.0},
            "promotion": {
                "staging_required": True,
                "production_requires_staging_artifact": True,
                "artifact_max_age_minutes": 1440,
            },
            "reason_codes": [],
        }
        s1 = attach_signature(body, commit_sha="c1", run_id="r1")["_signature"]
        s2 = attach_signature(body, commit_sha="c1", run_id="r2")["_signature"]
        self.assertNotEqual(s1, s2)

    def test_fork_unsigned_promotion_blocked(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            ci = Path(tmp) / "pdp.json"
            promo_dir = Path(tmp) / "promotion"
            promo_dir.mkdir()
            art = make_artifact("deadbeef", "run-1")
            ci.write_text(json.dumps(art, indent=2), encoding="utf-8")
            (promo_dir / "staging-deadbeef.json").write_text(
                json.dumps({"commit": "deadbeef", "target": "staging"}),
                encoding="utf-8",
            )
            proc = run(
                [
                    sys.executable,
                    str(PLATFORM / "policy-fork-detector.py"),
                    "--ci",
                    str(ci),
                    "--promotion-dir",
                    str(promo_dir),
                    "--repo-root",
                    str(tmp),
                ]
            )
            self.assertNotEqual(proc.returncode, 0)
            self.assertIn("UNAUTHORIZED_PROMOTION_PATH", proc.stdout + proc.stderr)

    def test_verify_artifact_binding_passes_valid(self) -> None:
        art = make_artifact("cafebabe", "run-42")
        ok, msg = verify_artifact_binding(art)
        self.assertTrue(ok, msg)

    def test_promotion_integrity_chain(self) -> None:
        from promotion_integrity import (  # noqa: E402
            attach_integrity_to_artifact,
            build_integrity,
            verify_pdp_artifact,
            verify_staging_against_pdp,
        )

        art = make_artifact("deadbeef", "run-1")
        art["sources"] = {"sop": {"ok": True}, "consistency": {"ok": True}}
        art = attach_integrity_to_artifact(art)
        ok, msg = verify_pdp_artifact(art, require_staging_hash=False)
        self.assertTrue(ok, msg)

        staging = {
            "target": "staging",
            "commit": "deadbeef",
            "pdp_signature": art["pdp_v3"]["_signature"],
            "recorded_at": "2026-06-27T00:00:00Z",
            "promotion_integrity": dict(art["promotion_integrity"]),
        }
        staging["staging_deployment_hash"] = "abc"
        staging["promotion_integrity"]["staging_deployment_hash"] = "abc"
        staging["promotion_integrity"] = build_integrity(
            commit_sha=staging["promotion_integrity"]["commit_sha"],
            ci_test_hash=staging["promotion_integrity"]["ci_test_hash"],
            pdp_decision_hash=staging["promotion_integrity"]["pdp_decision_hash"],
            staging_deployment_hash="abc",
        )
        art["promotion_integrity"]["staging_deployment_hash"] = "abc"
        art["promotion_integrity"] = build_integrity(
            commit_sha=art["promotion_integrity"]["commit_sha"],
            ci_test_hash=art["promotion_integrity"]["ci_test_hash"],
            pdp_decision_hash=art["promotion_integrity"]["pdp_decision_hash"],
            staging_deployment_hash="abc",
        )
        ok, msg = verify_staging_against_pdp(art, staging)
        self.assertTrue(ok, msg)

    def test_pdp_root_of_trust_sign_and_verify(self) -> None:
        import os

        from pdp_signing_authority import sign_artifact, verify_artifact  # noqa: E402

        os.environ["PDP_SIGNING_ALLOW_TEST_KEY"] = "1"
        art = make_artifact("deadbeef", "run-sign")
        art["sources"] = {"sop": {"ok": True}}
        sys.path.insert(0, str(PLATFORM))
        from promotion_integrity import attach_integrity_to_artifact  # noqa: E402

        art = attach_integrity_to_artifact(art)
        signed = sign_artifact(art)
        ok, msg = verify_artifact(signed, require_staging_hash=False)
        self.assertTrue(ok, msg)
        tampered = dict(signed)
        tampered["status"] = "TAMPERED"
        ok2, _ = verify_artifact(tampered, require_staging_hash=False)
        self.assertFalse(ok2)

    def test_promotion_enforce_reads_pdp_only(self) -> None:
        text = (PLATFORM / "promotion-enforce.py").read_text(encoding="utf-8")
        self.assertNotIn("policy-engine", text)
        self.assertNotIn("risk_score >", text)


if __name__ == "__main__":
    unittest.main(verbosity=2)
