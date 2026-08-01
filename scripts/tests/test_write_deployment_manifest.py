import json
import tempfile
import unittest
from pathlib import Path
import importlib.util


SCRIPT = Path(__file__).parents[1] / "write-deployment-manifest.py"
SPEC = importlib.util.spec_from_file_location("write_deployment_manifest", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class WriteDeploymentManifestTest(unittest.TestCase):
    def test_new_frontend_identity_is_preserved(self):
        manifest = MODULE.build_manifest(
            "backend-full-sha",
            {"hash": "frontend-short", "build_sha": "frontend-full", "built_at": "2026-08-01T01:02:03Z"},
            "2026-08-01T01:03:04Z",
        )
        self.assertEqual(manifest["backend_sha"], "backend-full-sha")
        self.assertEqual(manifest["frontend_build_sha"], "frontend-full")
        self.assertEqual(manifest["frontend_built_at"], "2026-08-01T01:02:03Z")
        self.assertEqual(manifest["deployed_at"], "2026-08-01T01:03:04Z")

    def test_legacy_version_falls_back_to_short_hash(self):
        manifest = MODULE.build_manifest("backend-sha", {"hash": "legacy-short"}, "now")
        self.assertEqual(manifest["frontend_sha"], "legacy-short")
        self.assertEqual(manifest["frontend_build_sha"], "legacy-short")

    def test_cli_writes_only_non_secret_identity_fields(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            version = root / "version.json"
            output = root / "public" / "deployment.json"
            version.write_text(json.dumps({"build_sha": "frontend-sha"}), encoding="utf-8")
            manifest = MODULE.build_manifest("backend-sha", MODULE.read_version(version), "now")
            output.parent.mkdir()
            output.write_text(json.dumps(manifest), encoding="utf-8")
            decoded = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(set(decoded), {
                "schema", "backend_sha", "frontend_sha", "frontend_build_sha",
                "frontend_built_at", "deployed_at", "source",
            })


if __name__ == "__main__":
    unittest.main()
