"""Successor pin integrity, using the actual verifier with an offline fetcher."""
import hashlib
import importlib.util
from pathlib import Path
import unittest

PATH = Path(__file__).resolve().parents[1] / "governance/validate-sunrise-rulebook-pin.py"
SPEC = importlib.util.spec_from_file_location("sunrise_pin", PATH)
PIN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PIN)
CONTENT = b"# Rulebook fixture\n"
REF = "a" * 40
LOCK = (f"source_repo={PIN.SOURCE_REPO}\nsource_ref={REF}\n"
        f"sha256={hashlib.sha256(CONTENT).hexdigest()}\n").encode()


class SunrisePinTest(unittest.TestCase):
    def verify(self, lock=LOCK, vendor=CONTENT, source=CONTENT):
        fixtures = {
            PIN.SUNRISE + "RULEBOOK.lock": lock,
            PIN.SUNRISE + "RULEBOOK.md": vendor,
            f"https://raw.githubusercontent.com/{PIN.SOURCE_REPO}/{REF}/governance/RULEBOOK.md": source,
        }
        return PIN.verify(fixtures.__getitem__)

    def test_matching_vendor_lock_and_immutable_source_pass(self):
        self.assertEqual(self.verify(), REF)

    def test_vendored_drift_fails(self):
        with self.assertRaisesRegex(ValueError, "vendored rulebook sha256 mismatch"):
            self.verify(vendor=CONTENT + b"changed")

    def test_pinned_source_drift_fails(self):
        with self.assertRaisesRegex(ValueError, "pinned source rulebook sha256 mismatch"):
            self.verify(source=CONTENT + b"changed")

    def test_rejects_wrong_source_mutable_ref_and_bad_hash(self):
        for old, new in [(PIN.SOURCE_REPO.encode(), b"other/repo"),
                         (REF.encode(), b"main"),
                         (hashlib.sha256(CONTENT).hexdigest().encode(), b"not-a-hash")]:
            with self.subTest(new=new), self.assertRaises(ValueError):
                self.verify(lock=LOCK.replace(old, new))

    def test_missing_duplicate_and_malformed_fields_fail(self):
        for lock in [b"", LOCK + b"source_ref=" + REF.encode(), LOCK + b"invalid line"]:
            with self.subTest(lock=lock), self.assertRaises(ValueError):
                self.verify(lock=lock)

    def test_network_failure_propagates_without_cached_pin(self):
        def unavailable(_url):
            raise OSError("offline fixture")
        with self.assertRaises(OSError):
            PIN.verify(unavailable)


if __name__ == "__main__":
    unittest.main()
