"""#4504 transport/projection regressions. Synthetic data; no DB, host or network probe."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/laravel-4504-metadata-summary.py"
spec = importlib.util.spec_from_file_location("metadata_summary", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
BACKEND, WORKFLOW = "a" * 40, "b" * 40


def fixture():
    types = ("bigint unsigned", "varchar(255)", "bigint unsigned", "text", "varchar(64)",
             "text", "timestamp", "timestamp", "timestamp", "timestamp")
    columns = [{"COLUMN_NAME": n, "COLUMN_TYPE": t, "IS_NULLABLE": "YES" if i >= 5 else "NO",
                "EXTRA": "auto_increment" if i == 0 else "", "COLUMN_DEFAULT": "FAKE_PRIVATE_DEFAULT"}
               for i, (n, t) in enumerate(zip(module.COLUMNS, types))]
    indexes = []
    for name, unique, names in (("PRIMARY", 0, ["id"]), ("private_index_name", 0, ["token"]),
                                ("private_morph_index", 1, ["tokenable_type", "tokenable_id"]), ("expiry", 1, ["expires_at"])):
        indexes += [{"INDEX_NAME": name, "SEQ_IN_INDEX": i + 1, "COLUMN_NAME": n,
                     "NON_UNIQUE": unique, "SUB_PART": None, "INDEX_TYPE": "BTREE"} for i, n in enumerate(names)]
    return {"case": module.CASE, "read_only": True, "metadata_collected": True,
            "identity_consistent": True, "candidate_head": module.CANDIDATE,
            "checkout_sha_start": BACKEND, "checkout_sha_end": BACKEND, "manifest_backend_sha": BACKEND,
            "captured_at_utc": "2026-10-25T12:00:00Z", "php_cli_version": "8.2.30",
            "laravel_version_fresh_cli": "8.83.29", "php_cli_extensions": ["PDO", "pdo_mysql", "openssl"],
            "process_observation": {"loaded_fpm_worker_version_extensions_config_sha": "FAKE_PRIVATE_FPM"},
            "schema_metadata": {"tables": [{"TABLE_NAME": "private_prefix_personal_access_tokens"}],
                "columns": columns, "indexes": indexes, "migration_ledger_present": True,
                "original_migration_records": [{"migration": module.MIGRATION, "batch": 12}]}}


def project(raw):
    return module.project(raw, BACKEND, WORKFLOW, "123", "1", "0", "true")


class MetadataProjectionTest(unittest.TestCase):
    def test_complete_structure_is_readable_but_never_activation_or_loaded_fpm_evidence(self):
        out = project(fixture())
        self.assertTrue(out["metadata_collected"])
        self.assertEqual(out["captured_at_utc"], "2026-10-25T12:00:00Z")
        self.assertTrue(all(out["indexes"].values()))
        self.assertEqual(out["columns"]["name"]["type"], "text")  # Exact4504 migration uses text.
        self.assertEqual(out["activation_decision"], "HOLD")
        self.assertEqual(out["loaded_fpm_worker_version_extensions_config_sha"], "UNKNOWN")
        self.assertNotIn("FAKE_PRIVATE", json.dumps(out))
        self.assertNotIn("private_", json.dumps(out))

    def test_table_and_original_migration_are_independent_facts(self):
        for table in (True, False):
            for recorded in (True, False):
                raw = fixture()
                if not table:
                    raw["schema_metadata"].update(tables=[], columns=[], indexes=[])
                if not recorded:
                    raw["schema_metadata"]["original_migration_records"] = []
                out = project(raw)
                self.assertTrue(out["metadata_collected"])
                self.assertEqual(out["token_table_present"], table)
                self.assertEqual(out["original_migration_record_count"], int(recorded))
                self.assertEqual(out["activation_decision"], "HOLD")

    def test_arbitrary_metadata_and_enum_default_literals_never_leave_projection(self):
        raw = fixture()
        for kind in ("enum('FAKE_PRIVATE')", "set('FAKE_PRIVATE')", "unknown(FAKE_PRIVATE)", "varchar(0912345678)"):
            raw["schema_metadata"]["columns"][3]["COLUMN_TYPE"] = kind
            self.assertEqual(project(raw)["columns"]["name"]["type"], "withheld")
        raw["schema_metadata"]["columns"].append({"COLUMN_NAME": "FAKE_PRIVATE_UNKNOWN"})
        raw.update(error="FAKE_PRIVATE_ERROR", token="FAKE_PRIVATE_TOKEN")
        self.assertNotIn("FAKE_PRIVATE", json.dumps(project(raw)))

    def test_every_identity_mismatch_and_missing_capture_time_holds(self):
        for key in ("candidate_head", "checkout_sha_start", "checkout_sha_end", "manifest_backend_sha", "identity_consistent", "captured_at_utc"):
            raw = fixture(); raw.pop(key)
            self.assertFalse(project(raw)["metadata_collected"], key)
        self.assertNotIn("FAKE_PRIVATE", json.dumps(module.project(fixture(), "FAKE_PRIVATE", WORKFLOW, "123", "1", "0", "true")))
        for status in ("1", "124", "137", "255"):
            self.assertFalse(module.project(fixture(), BACKEND, WORKFLOW, "123", "1", status, "true")["metadata_collected"])
        failed = {"metadata_collected": False, "stage": "identity_before_configuration", "message": "FAKE_PRIVATE"}
        out = module.project(failed, BACKEND, WORKFLOW, "123", "1", "1", "true")
        self.assertEqual(out["capture_stage"], "identity_before_configuration")
        self.assertNotIn("FAKE_PRIVATE", json.dumps(out))

    def test_duplicate_columns_gapped_index_and_missing_ledger_fields_hold(self):
        raw = fixture(); raw["schema_metadata"]["columns"].append(copy.deepcopy(raw["schema_metadata"]["columns"][0]))
        self.assertFalse(project(raw)["metadata_collected"])
        raw = fixture(); raw["schema_metadata"]["indexes"][0]["SEQ_IN_INDEX"] = 2
        self.assertFalse(project(raw)["metadata_collected"])
        raw = fixture(); del raw["schema_metadata"]["migration_ledger_present"]
        self.assertFalse(project(raw)["metadata_collected"])

    def test_partial_collection_shapes_and_missing_private_package_hold(self):
        for key in ("tables", "columns", "indexes"):
            raw = fixture(); raw["schema_metadata"][key] = {}
            self.assertFalse(project(raw)["metadata_collected"], key)
        self.assertTrue(project(fixture())["encrypted_evidence_packaged"])
        out = module.project(fixture(), BACKEND, WORKFLOW, "123", "1", "0", "false")
        self.assertFalse(out["metadata_collected"])
        self.assertEqual(out["reason"], "encrypted_evidence_missing")

    def test_actual_cli_rejects_partial_duplicate_and_oversize_input_and_emits_only_safe_file(self):
        with tempfile.TemporaryDirectory() as directory:
            source, output = Path(directory) / "raw.json", Path(directory) / "public.json"
            samples = [json.dumps(fixture()), '{"FAKE_PRIVATE":', '{"metadata_collected":true,"metadata_collected":false}',
                       ' ' * 262145, json.dumps(dict(fixture(), unexpected=float('nan')))]
            for i, sample in enumerate(samples):
                source.write_text(sample)
                run = subprocess.run(["python3", str(SCRIPT), str(source), str(output), "--backend", BACKEND,
                                      "--workflow", WORKFLOW, "--run_id", "123", "--attempt", "1", "--probe_exit", "0", "--private_packaged", "true"], capture_output=True, text=True)
                self.assertEqual(run.returncode, 0 if i == 0 else 1)
                self.assertEqual(json.loads(output.read_text())["metadata_collected"], i == 0)
                self.assertNotIn("FAKE_PRIVATE", output.read_text() + run.stdout + run.stderr)

    def test_php_collector_binds_expected_revision_and_rejects_unapproved_runtime_path(self):
        php = os.environ.get("PHP_BINARY", "php")
        code = (ROOT / "scripts/laravel-4504-metadata.php").read_text().removeprefix("<?php")
        definitions = code.rsplit("AllTrue4504Metadata::run();", 1)[0]
        checks = "AllTrue4504Metadata::assertExpectedRevision(str_repeat('a',40),str_repeat('a',40));"
        checks += "foreach (['',str_repeat('b',40),'FAKE_PRIVATE'] as $sha) {try {AllTrue4504Metadata::assertExpectedRevision($sha,str_repeat('a',40)); exit(2);} catch (RuntimeException $e) {}} echo 'revision_tests_passed';"
        result = subprocess.run([php, "-r", definitions + checks], capture_output=True, text=True)
        self.assertEqual((result.returncode, result.stdout), (0, "revision_tests_passed"))
        result = subprocess.run([php, "-r", code], capture_output=True, text=True)
        self.assertEqual(result.returncode, 1)
        self.assertFalse(json.loads(result.stdout)["metadata_collected"])
        self.assertNotIn("FAKE_PRIVATE", result.stdout + result.stderr)


if __name__ == "__main__":
    program = unittest.main(exit=False)
    if not program.result.wasSuccessful():
        raise SystemExit(1)
    # Separate, unmistakably synthetic artifact exercises hosted upload/download without any production secrets.
    artifact = ROOT / "out/synthetic-metadata.summary.json"
    artifact.parent.mkdir(exist_ok=True)
    artifact.write_text(json.dumps({"synthetic": True, "summary": project(fixture())}, sort_keys=True, indent=2) + "\n")
