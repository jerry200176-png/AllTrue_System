#!/usr/bin/env python3
"""Guardrails for Daan staging paths vs production activation classifier."""

from __future__ import annotations

import os
import stat
import unittest
from pathlib import Path

from scripts.governance.autonomy_gate import (
    is_application_runtime_path,
    is_deployable_path,
)


ROOT = Path(__file__).resolve().parents[2]
LIFECYCLE = ROOT / "infra" / "daan-staging" / "lifecycle"

STAGING_PATHS = [
    "infra/daan-staging/docker-compose.yml",
    "infra/daan-staging/Dockerfile",
    "infra/daan-staging/docker-entrypoint-staging.sh",
    "infra/daan-staging/nginx.conf",
    "infra/daan-staging/lifecycle/assert-host-checkout-clean.sh",
    "infra/daan-staging/lifecycle/health.sh",
    "infra/daan-staging/lifecycle/health_contract.py",
    "infra/daan-staging/lifecycle/validate-cycle.sh",
    "infra/daan-staging/lifecycle/rehearse-inapp-296.sh",
    "infra/daan-staging/lifecycle/rollback.sh",
    "infra/daan-staging/README.md",
    "docs/ops/DAAN_STAGING_V1.md",
    ".dockerignore",
]


class DaanStagingPathClassificationTest(unittest.TestCase):
    def test_staging_tree_is_not_application_runtime(self) -> None:
        for path in STAGING_PATHS:
            with self.subTest(path=path):
                self.assertFalse(is_application_runtime_path(path), path)
                self.assertFalse(is_deployable_path(path), path)

    def test_root_compose_still_not_deployable_but_unsafe_on_daan(self) -> None:
        # Documented conflict surface — must remain non-Pi-deployable.
        self.assertFalse(is_deployable_path("docker-compose.yml"))
        self.assertFalse(is_application_runtime_path("docker-compose.yml"))

    def test_backend_dockerfile_would_still_be_application_runtime(self) -> None:
        # Why staging uses infra/daan-staging/Dockerfile instead of editing this.
        self.assertTrue(is_application_runtime_path("backend/Dockerfile"))
        self.assertTrue(is_deployable_path("backend/Dockerfile"))


class DaanStagingImmutableCheckoutTest(unittest.TestCase):
    def test_compose_does_not_bind_mount_host_backend(self) -> None:
        text = (ROOT / "infra" / "daan-staging" / "docker-compose.yml").read_text(encoding="utf-8")
        self.assertNotIn("../../backend:/var/www", text)
        self.assertNotIn("../backend:/var/www", text)
        self.assertIn("stage-app-code:/var/www", text)
        self.assertIn("stage-app-storage:/var/www/storage", text)
        self.assertIn("stage-bootstrap-cache:/var/www/bootstrap/cache", text)
        self.assertIn("context: ../..", text)
        self.assertIn("dockerfile: infra/daan-staging/Dockerfile", text)

    def test_staging_entrypoint_syncs_from_image_not_host(self) -> None:
        text = (ROOT / "infra" / "daan-staging" / "docker-entrypoint-staging.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn("/opt/alltrue-src", text)
        self.assertIn("rsync", text)
        self.assertIn("named volume", text.lower() + text)  # comment or path intent
        # Must not invoke production backend entrypoint sync onto bind mounts.
        self.assertNotIn("cp -rf /app-src/. /var/www/", text)

    def test_dockerfile_uses_staging_entrypoint(self) -> None:
        text = (ROOT / "infra" / "daan-staging" / "Dockerfile").read_text(encoding="utf-8")
        self.assertIn("docker-entrypoint-staging.sh", text)
        self.assertIn("/opt/alltrue-src", text)
        self.assertNotIn("backend/docker-entrypoint.sh", text)

    def test_validate_cycle_asserts_host_checkout_clean(self) -> None:
        text = (LIFECYCLE / "validate-cycle.sh").read_text(encoding="utf-8")
        self.assertIn("assert-host-checkout-clean.sh", text)
        clean = (LIFECYCLE / "assert-host-checkout-clean.sh").read_text(encoding="utf-8")
        self.assertIn("git status --short", clean)
        self.assertIn("STAGING_HOST_CHECKOUT_MUTATION", clean)
        self.assertIn("HOST_CHECKOUT_CLEAN", clean)


class DaanStagingLifecycleContractTest(unittest.TestCase):
    def test_lifecycle_scripts_are_executable_in_git_tree(self) -> None:
        scripts = sorted(LIFECYCLE.glob("*.sh"))
        self.assertGreaterEqual(len(scripts), 11)
        for path in scripts:
            mode = path.stat().st_mode
            self.assertTrue(mode & stat.S_IXUSR, f"{path.name} must be user-executable")

    def test_validate_cycle_requires_exact_shas_and_names_evidence(self) -> None:
        text = (LIFECYCLE / "validate-cycle.sh").read_text(encoding="utf-8")
        self.assertIn("require_sha STAGING_INFRA_SHA", text)
        self.assertIn("require_sha MAIN_SHA", text)
        self.assertIn("PRODUCT_REHEARSAL_MAIN_SHA", text)
        self.assertIn("phase1-infra-", text)
        self.assertIn("phase2-product-", text)
        self.assertIn("same-sha-redeploy-not-rollback", text)
        self.assertIn("^[0-9a-f]{40}$", text)
        self.assertNotIn("phase=1-rollback", text)

    def test_rehearse_propagates_main_sha_env(self) -> None:
        text = (LIFECYCLE / "rehearse-inapp-296.sh").read_text(encoding="utf-8")
        self.assertIn("PRODUCT_REHEARSAL_MAIN_SHA", text)

    def test_rollback_is_redeploy_of_prior_sha_not_production(self) -> None:
        text = (LIFECYCLE / "rollback.sh").read_text(encoding="utf-8")
        self.assertIn("redeploy.sh", text)
        self.assertIn("not production", text.lower())
        self.assertIn("ROLLBACK_OK", text)


if __name__ == "__main__":
    unittest.main()
