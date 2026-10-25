#!/usr/bin/env python3
"""Public projection of the private #4504 collector output; never export arbitrary keys/values."""
import argparse
from datetime import datetime
import hashlib
import json
import re
from pathlib import Path

CASE = "laravel_4504_metadata"
CANDIDATE = "7f9acf5ef8ed5ead47c948da58799b035993720e"
MIGRATION = "2019_12_14_000001_create_personal_access_tokens_table"
COLUMNS = ("id", "tokenable_type", "tokenable_id", "name", "token", "abilities",
           "last_used_at", "expires_at", "created_at", "updated_at")
EXTENSIONS = ("ctype", "curl", "dom", "fileinfo", "filter", "hash", "mbstring", "openssl",
              "pcre", "pdo", "pdo_mysql", "session", "tokenizer", "xml", "zip", "intl", "gd")
SHA = re.compile(r"[a-f0-9]{40}\Z")
VERSION = re.compile(r"[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,5}\Z")
TYPE = re.compile(r"(?:bigint|int|varchar|char|text|longtext|datetime|timestamp)(?:\([0-9]{1,5}\))?(?: unsigned)?\Z")


def integer(value):
    if type(value) is int or type(value) is str and re.fullmatch(r"[0-9]{1,12}", value):
        return int(value)
    raise ValueError("invalid integer")


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate key")
        result[key] = value
    return result


def reject_constant(_):
    raise ValueError("non-finite JSON")


def project(raw, backend, workflow, run_id, attempt, probe_exit, private_packaged):
    out = {"case": CASE, "metadata_collected": False, "production_acceptance": "UNKNOWN",
           "activation_decision": "HOLD", "encrypted_evidence_packaged": False, "reason": "invalid_context"}
    if not SHA.fullmatch(backend) or not SHA.fullmatch(workflow):
        return out
    try:
        run_id, attempt, probe_exit = integer(run_id), integer(attempt), integer(probe_exit)
        if min(run_id, attempt) < 1 or not 0 <= probe_exit <= 255:
            return out
        out.update(candidate_head=CANDIDATE, expected_backend_sha=backend, workflow_sha=workflow,
                   workflow_run_id=run_id, run_attempt=attempt, probe_exit=probe_exit, reason="capture_unavailable")
        if isinstance(raw, dict):
            stages = {"paths", "identity_before_configuration", "configuration_only",
                      "metadata_only_read_only_transaction", "identity_and_versions"}
            out["capture_stage"] = raw.get("stage") if raw.get("stage") in stages else "withheld"
        out["encrypted_evidence_packaged"] = private_packaged == "true"
        if not out["encrypted_evidence_packaged"]:
            out["reason"] = "encrypted_evidence_missing"
            return out
        if probe_exit != 0:
            out["reason"] = "transport_incomplete"
            return out
        if not isinstance(raw, dict) or raw.get("metadata_collected") is not True:
            return out
        out["reason"] = "identity_mismatch"
        if (raw.get("case") != CASE or raw.get("read_only") is not True
                or raw.get("candidate_head") != CANDIDATE or raw.get("identity_consistent") is not True
                or any(raw.get(k) != backend for k in ("checkout_sha_start", "checkout_sha_end", "manifest_backend_sha"))):
            return out
        out["reason"] = "metadata_incomplete"
        captured = raw["captured_at_utc"]
        if not isinstance(captured, str) or not re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", captured):
            raise ValueError("unknown capture time")
        datetime.strptime(captured, "%Y-%m-%dT%H:%M:%SZ")
        schema = raw["schema_metadata"]
        groups = ("tables", "columns", "indexes", "original_migration_records")
        if (not isinstance(schema, dict) or any(not isinstance(schema.get(k), list) for k in groups)
                or any(not isinstance(row, dict) for k in groups for row in schema[k])):
            raise ValueError("partial metadata shape")
        if type(schema["migration_ledger_present"]) is not bool:
            raise ValueError("unknown ledger")
        records = schema["original_migration_records"]
        if not isinstance(records, list) or any(r["migration"] != MIGRATION or integer(r["batch"]) < 1 for r in records):
            raise ValueError("invalid ledger")
        columns = {}
        for row in schema["columns"]:
            name = row["COLUMN_NAME"]
            if name not in COLUMNS:
                continue  # Unknown names, defaults, enum/set literals and arbitrary EXTRA never become public.
            if name in columns or row["IS_NULLABLE"] not in ("YES", "NO"):
                raise ValueError("ambiguous column")
            kind = row["COLUMN_TYPE"]
            columns[name] = {"type": kind if isinstance(kind, str) and TYPE.fullmatch(kind) else "withheld",
                             "nullable": row["IS_NULLABLE"] == "YES", "auto_increment": row.get("EXTRA") == "auto_increment"}
        groups = {}
        for row in schema["indexes"]:
            name, seq = row["INDEX_NAME"], integer(row["SEQ_IN_INDEX"])
            if not isinstance(name, str) or seq < 1 or seq in groups.setdefault(name, {}):
                raise ValueError("ambiguous index")
            groups[name][seq] = (row["COLUMN_NAME"], integer(row["NON_UNIQUE"]), row["SUB_PART"], row["INDEX_TYPE"])
        signatures = []
        for name, rows in groups.items():
            if sorted(rows) != list(range(1, len(rows) + 1)):
                raise ValueError("incomplete index")
            signatures.append((name == "PRIMARY", tuple(rows[i] for i in sorted(rows))))
        def has_index(names, unique=False, primary=False):
            target = tuple((n, 0 if unique else 1, None, "BTREE") for n in names)
            return any(rows == target and (not primary or is_primary) for is_primary, rows in signatures)
        version, laravel = raw["php_cli_version"], raw["laravel_version_fresh_cli"]
        if not VERSION.fullmatch(version) or not VERSION.fullmatch(laravel):
            raise ValueError("unknown version")
        extensions = raw["php_cli_extensions"]
        if not isinstance(extensions, list) or any(not isinstance(e, str) for e in extensions):
            raise ValueError("unknown extensions")
        extensions = {e.lower() for e in extensions}
        fresh = raw.get("process_observation", {}).get("fpm_fresh_binary_info", {})
        fresh_version = fresh.get("php_version")
        fresh_version = fresh_version if (fresh.get("state") == "fresh_binary_info_only"
            and isinstance(fresh_version, str) and VERSION.fullmatch(fresh_version)) else None
        result = dict(out, metadata_collected=True, reason="collected_not_activation", capture_stage="completed", captured_at_utc=captured,
                      php_cli_version=version, laravel_fresh_cli_version=laravel,
                      cli_extensions={e: e in extensions for e in EXTENSIONS},
                      fpm_fresh_binary_version=fresh_version,
                      token_table_present=any(r["TABLE_NAME"].endswith("personal_access_tokens") for r in schema["tables"]),
                      migration_ledger_present=schema["migration_ledger_present"], original_migration_record_count=len(records),
                      columns=columns, unknown_column_count=len(schema["columns"]) - len(columns),
                      indexes={"primary_id": has_index(("id",), True, True), "unique_token": has_index(("token",), True),
                               "tokenable_pair": has_index(("tokenable_type", "tokenable_id")), "expires_at": has_index(("expires_at",))},
                      loaded_fpm_worker_version_extensions_config_sha="UNKNOWN")
        return result
    except (KeyError, TypeError, ValueError, AttributeError, OverflowError):
        return out


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", type=Path)
    parser.add_argument("output", type=Path)
    for name in ("backend", "workflow", "run_id", "attempt", "probe_exit", "private_packaged"):
        parser.add_argument("--" + name, required=True)
    args = parser.parse_args()
    try:
        with args.input.open("rb") as stream:
            data = stream.read(262145)
        if len(data) > 262144:
            raise ValueError("too large")
        raw = json.loads(data, object_pairs_hook=unique_object, parse_constant=reject_constant)
    except (OSError, ValueError, UnicodeError):
        raw = None
    result = project(raw, args.backend, args.workflow, args.run_id, args.attempt, args.probe_exit, args.private_packaged)
    if raw is not None:
        result["collector_output_sha256"] = hashlib.sha256(data).hexdigest()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, sort_keys=True, indent=2) + "\n")
    print("metadata_summary_status=" + ("collected" if result["metadata_collected"] else "hold"))
    raise SystemExit(0 if result["metadata_collected"] else 1)
