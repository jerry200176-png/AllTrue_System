"""EVIDENCE_CONTRACT reporter-verify timeouts must match the PHP constants (#3918).

The source of truth is the code: BugReportService and CloseStaleResolvedBugsCommand.
The doc names each constant next to its value; this test reads both sides and fails
when they disagree, so a constant change without a doc change cannot merge.

Run: python3 scripts/tests/test_evidence_contract_timeouts.py
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).parents[2]
DOC = ROOT / "docs/governance/EVIDENCE_CONTRACT.md"
SOURCES = {
    "BugReportService": ROOT / "backend/app/Services/BugReportService.php",
    "CloseStaleResolvedBugsCommand": ROOT / "backend/app/Console/Commands/CloseStaleResolvedBugsCommand.php",
}
REQUIRED = {
    "BugReportService": ["REPORTER_TIMEOUT_MIN_DAYS", "AWAITING_REPORTER_TIMEOUT_DAYS"],
    "CloseStaleResolvedBugsCommand": ["AUTO_DAYS", "AUTO_MAX_PER_RUN"],
}
SECTION_HEADING = "## Reporter-verify timeout (in-app)"


def php_int_constants(source: str) -> dict[str, int]:
    return {
        name: int(value)
        for name, value in re.findall(r"public\s+const\s+([A-Z0-9_]+)\s*=\s*([0-9]+)\s*;", source)
    }


def timeout_section(doc: str) -> str:
    start = doc.index(SECTION_HEADING)
    nxt = re.search(r"^## ", doc[start + len(SECTION_HEADING):], re.M)
    return doc[start: start + len(SECTION_HEADING) + nxt.start()] if nxt else doc[start:]


def doc_constant_values(section: str) -> dict[str, int]:
    """`Class::CONST` (= or :) **N** -> {"Class::CONST": N}."""
    return {
        ref: int(value)
        for ref, value in re.findall(r"`([A-Za-z]+::[A-Z0-9_]+)`\s*[=:]\s*\*\*([0-9]+)\*\*", section)
    }


def mismatches(doc: str, sources: dict[str, str]) -> list[str]:
    section = timeout_section(doc)
    documented = doc_constant_values(section)
    problems = []
    for cls, names in REQUIRED.items():
        values = php_int_constants(sources[cls])
        for name in names:
            ref = f"{cls}::{name}"
            if name not in values:
                problems.append(f"{ref} not found in PHP source")
            elif ref not in documented:
                problems.append(f"{ref} not named with a value in {SECTION_HEADING}")
            elif documented[ref] != values[name]:
                problems.append(f"{ref}: doc says {documented[ref]}, code says {values[name]}")
    for ref, value in documented.items():
        cls, name = ref.split("::")
        actual = php_int_constants(sources[cls]).get(name) if cls in sources else None
        if actual is not None and actual != value:
            problems.append(f"{ref}: doc says {value}, code says {actual}")
    if re.search(r"not auto-scheduled", section, re.I):
        problems.append("section still says the timeout is not auto-scheduled")
    return problems


def read_all() -> tuple[str, dict[str, str]]:
    return DOC.read_text(encoding="utf-8"), {k: p.read_text(encoding="utf-8") for k, p in SOURCES.items()}


class EvidenceContractTimeoutsTest(unittest.TestCase):
    def test_doc_matches_php_constants(self):
        doc, sources = read_all()
        self.assertEqual(mismatches(doc, sources), [])

    def test_changing_a_php_constant_breaks_the_check(self):
        doc, sources = read_all()
        values = php_int_constants(sources["CloseStaleResolvedBugsCommand"])
        bumped = dict(sources)
        bumped["CloseStaleResolvedBugsCommand"] = re.sub(
            r"(public\s+const\s+AUTO_DAYS\s*=\s*)[0-9]+",
            lambda m: m.group(1) + str(values["AUTO_DAYS"] + 1),
            sources["CloseStaleResolvedBugsCommand"],
        )
        self.assertTrue(any("AUTO_DAYS" in p for p in mismatches(doc, bumped)))

    def test_changing_a_doc_value_breaks_the_check(self):
        doc, sources = read_all()
        minimum = php_int_constants(sources["BugReportService"])["REPORTER_TIMEOUT_MIN_DAYS"]
        stale = doc.replace(
            f"`BugReportService::REPORTER_TIMEOUT_MIN_DAYS` = **{minimum}**",
            f"`BugReportService::REPORTER_TIMEOUT_MIN_DAYS` = **{minimum + 3}**",
        )
        self.assertNotEqual(stale, doc)
        self.assertTrue(any("REPORTER_TIMEOUT_MIN_DAYS" in p for p in mismatches(stale, sources)))


if __name__ == "__main__":
    unittest.main()
