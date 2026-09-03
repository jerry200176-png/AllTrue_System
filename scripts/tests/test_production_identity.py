import unittest
from pathlib import Path


SCRIPT = (Path(__file__).resolve().parents[1] / "production-identity.sh").read_text()


class ProductionIdentityContractTest(unittest.TestCase):
    def test_large_evidence_is_transferred_via_private_temp_files(self):
        self.assertIn('IDENTITY_TMP_DIR="$(mktemp -d)"', SCRIPT)
        self.assertIn("trap 'rm -rf -- \"$IDENTITY_TMP_DIR\"' EXIT", SCRIPT)
        self.assertIn("umask 077", SCRIPT)
        self.assertIn('"$IDENTITY_TMP_DIR/deploy-runs.json"', SCRIPT)
        self.assertIn('"$IDENTITY_TMP_DIR/matching-deploy-runs.json"', SCRIPT)
        self.assertNotIn('python3 - "$REMOTE_MAIN" "$HEALTH_RAW"', SCRIPT)
        self.assertNotIn('python3 - "$REMOTE_MAIN" "$DEPLOYMENT_RAW"', SCRIPT)

    def test_deploy_evidence_is_bound_to_the_manifest_sha(self):
        self.assertIn(
            '--workflow=deploy.yml --commit "$DEPLOYMENT_BACKEND_SHA"',
            SCRIPT,
        )
        self.assertIn("matching_deploy_runs = parse(matching_deploy_json, []) or []", SCRIPT)
        self.assertIn("run.get('status') == 'completed'", SCRIPT)
        self.assertIn("run.get('conclusion') == 'success'", SCRIPT)
        self.assertIn("run.get('headSha') == deployment_backend_sha", SCRIPT)


if __name__ == "__main__":
    unittest.main()
