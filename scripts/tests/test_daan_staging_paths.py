#!/usr/bin/env python3
"""Guardrails for Daan staging paths vs production activation classifier."""

from __future__ import annotations

import unittest

from scripts.governance.autonomy_gate import (
    is_application_runtime_path,
    is_control_plane_path,
    is_deployable_path,
)


STAGING_PATHS = [
    "infra/daan-staging/docker-compose.yml",
    "infra/daan-staging/Dockerfile",
    "infra/daan-staging/nginx.conf",
    "infra/daan-staging/lifecycle/up.sh",
    "infra/daan-staging/README.md",
    "docs/ops/DAAN_STAGING_V1.md",
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


if __name__ == "__main__":
    unittest.main()
