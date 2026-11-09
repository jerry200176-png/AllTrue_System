"""Inspect a private UI download without extracting or printing workbook content."""
import json
import math
import posixpath
import re
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
      "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}
HEADERS = ["學生", "科目", "期間／剩幾堂", "狀態", "到期／逾期", "應收", "已收", "未收"]


class InvalidWorkbook(Exception):
    pass


def require(condition, code):
    if not condition:
        raise InvalidWorkbook(code)


def inspect_workbook(path, expected_name, expected_sources):
    require(isinstance(expected_name, str) and bool(expected_name.strip())
            and isinstance(expected_sources, int) and 0 < expected_sources <= 2000, "invalid-expectation")
    require(Path(path).stat().st_size <= 32 * 1024 * 1024, "archive-too-large")
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        require(len(entries) <= 1000 and sum(entry.file_size for entry in entries) <= 64 * 1024 * 1024
                and all(entry.file_size <= 16 * 1024 * 1024 for entry in entries), "archive-too-large")
        require(len({entry.filename for entry in entries}) == len(entries), "duplicate-entry")
        require(all(not entry.filename.startswith("/") and ".." not in entry.filename.split("/")
                    and not entry.flag_bits & 1 for entry in entries), "unsafe-entry")
        require(archive.testzip() is None, "invalid-crc")
        read = lambda name: ET.fromstring(archive.read(name))
        content_types = read("[Content_Types].xml")
        require(any(item.get("PartName") == "/xl/workbook.xml" and item.get("ContentType") ==
                    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"
                    for item in content_types), "not-xlsx")
        for entry in entries:
            if entry.filename.endswith(".rels"):
                require(all(item.get("TargetMode") != "External" for item in read(entry.filename)), "external-link")
        require(any(item.get("Type", "").endswith("/officeDocument") and item.get("Target", "").lstrip("/") == "xl/workbook.xml"
                    for item in read("_rels/.rels")), "missing-workbook-root")
        workbook = read("xl/workbook.xml")
        sheets = workbook.findall("m:sheets/m:sheet", NS)
        require(len(sheets) == 1, "student-sheet-count")
        title = re.sub(r"[/\\?*:\[\]]", "", expected_name).strip(" '\t\n\r\0\x0b") or "學生"
        require(sheets[0].get("name") == title[:31], "student-sheet-title")
        relationships = {item.get("Id"): item.get("Target", "") for item in read("xl/_rels/workbook.xml.rels")}
        target = relationships.get(sheets[0].get("{" + NS["r"] + "}id"), "")
        sheet_path = posixpath.normpath(target.lstrip("/") if target.startswith("/") else posixpath.join("xl", target))
        require(sheet_path.startswith("xl/worksheets/") and ":" not in sheet_path, "invalid-sheet-target")
        sheet = read(sheet_path)
        require(not sheet.findall(".//m:f", NS) and not sheet.findall(".//m:hyperlink", NS), "executable-cell")
        strings = []
        if "xl/sharedStrings.xml" in archive.namelist():
            strings = ["".join(node.text or "" for node in item.findall(".//m:t", NS)) for item in read("xl/sharedStrings.xml")]
        cells = {cell.get("r"): cell for cell in sheet.findall("m:sheetData/m:row/m:c", NS)}

        def value(cell):
            if cell is None:
                return None
            if cell.get("t") == "inlineStr":
                return "".join(node.text or "" for node in cell.findall("m:is//m:t", NS))
            raw = cell.findtext("m:v", default="", namespaces=NS)
            return strings[int(raw)] if cell.get("t") == "s" else raw

        require(value(cells.get("A1")) == expected_name and cells["A1"].get("t") in ["s", "inlineStr", "str"], "student-literal")
        generated = cells.get("B2")
        require(generated is not None and generated.get("t", "n") == "n"
                and math.isfinite(float(value(generated))), "typed-generated-date")
        styles = read("xl/styles.xml")
        formats = {int(item.get("numFmtId")): item.get("formatCode", "") for item in styles.findall("m:numFmts/m:numFmt", NS)}
        xfs = styles.findall("m:cellXfs/m:xf", NS)
        format_id = int(xfs[int(generated.get("s", "0"))].get("numFmtId", "0"))
        require(14 <= format_id <= 22 or all(char in formats.get(format_id, "").lower() for char in ["y", "d"]), "generated-date-format")
        summaries = 0
        numeric_amounts = 0
        invoices = 0
        for row in sheet.findall("m:sheetData/m:row", NS):
            number = int(row.get("r"))
            if value(cells.get("A" + str(number))) == "帳單" and value(cells.get("C" + str(number))) == "狀態" \
                    and bool(value(cells.get("B" + str(number)))):
                invoices += 1
            if [value(cells.get(column + str(number))) for column in "ABCDEFGH"] != HEADERS:
                continue
            summaries += 1
            require(value(cells.get("A" + str(number + 1))) == expected_name, "summary-student")
            for column in "FGH":
                cell = cells.get(column + str(number + 1))
                if cell is not None and cell.get("t", "n") == "n" and value(cell) != "":
                    require(math.isfinite(float(value(cell))), "invalid-amount-type")
                    numeric_amounts += 1
        require(summaries == expected_sources and invoices > 0 and numeric_amounts > 0, "bill-structure")
        return {"valid": True, "sheets": 1, "summary_rows": summaries, "numeric_amount_cells": numeric_amounts}


if __name__ == "__main__":
    try:
        request = json.loads(sys.stdin.read(4096))
        result = inspect_workbook(request["path"], request["student"], request["sources"])
    except InvalidWorkbook as error:
        result = {"valid": False, "reason": str(error)}
    except Exception:
        result = {"valid": False, "reason": "invalid-xlsx"}
    print(json.dumps(result))
    sys.exit(0 if result["valid"] else 1)
