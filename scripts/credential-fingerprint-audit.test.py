#!/usr/bin/env python3

import contextlib
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path


MODULE_PATH = Path(__file__).with_name("credential-fingerprint-audit.py")
SPEC = importlib.util.spec_from_file_location("credential_fingerprint_audit", MODULE_PATH)
AUDIT = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(AUDIT)


class CredentialFingerprintAuditTest(unittest.TestCase):
    def test_extracts_supported_values_without_returning_raw_values(self):
        telegram = "123456789:AAabcdefghijklmnopqrstuvwxyzABCDEFGH"
        app_key = "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
        bearer = "sample-bearer-token-abcdefghijklmnopqrstuvwxyz"
        with tempfile.TemporaryDirectory() as directory:
            sample = Path(directory) / "transcript.jsonl"
            sample.write_text(
                f'APP_KEY={app_key}\nAuthorization: Bearer {bearer}\nbot={telegram}\n',
                encoding="utf-8",
            )
            findings = AUDIT.extract([Path(directory)])

        self.assertEqual({kind for kind, _ in findings}, {"APP_KEY", "BEARER", "TELEGRAM"})
        serialized = repr(findings)
        self.assertNotIn(app_key, serialized)
        self.assertNotIn(bearer, serialized)
        self.assertNotIn(telegram, serialized)

    def test_compare_reports_match_without_printing_fingerprints(self):
        digest = hashlib.sha256(b"secret-value").hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            leaked = Path(directory) / "leaked.tsv"
            production = Path(directory) / "production.tsv"
            leaked.write_text(
                f"APP_KEY\t{digest}\nBEARER\t{digest}\nTELEGRAM\t{digest}\n",
                encoding="utf-8",
            )
            production.write_text(leaked.read_text(encoding="utf-8"), encoding="utf-8")
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                result = AUDIT.compare(leaked, production)

        self.assertEqual(result, 2)
        self.assertIn("MATCH_ROTATION_REQUIRED", output.getvalue())
        self.assertNotIn(digest, output.getvalue())

    def test_selects_only_gitguardian_linked_blobs(self):
        linked = ".cursor/projects/example/transcript.jsonl"
        anchor = hashlib.sha256(linked.encode("utf-8")).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            metadata = Path(directory) / "commit.json"
            metadata.write_text(
                json.dumps(
                    {
                        "files": [
                            {"filename": linked, "sha": "a" * 40},
                            {"filename": "unrelated.txt", "sha": "b" * 40},
                        ]
                    }
                ),
                encoding="utf-8",
            )
            selected = AUDIT.select_blobs([metadata], {anchor}, set())

        self.assertEqual(selected, {"a" * 40})


if __name__ == "__main__":
    unittest.main()
