import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from xml.etree import ElementTree as ET
from xml.sax.saxutils import escape
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from student_bill_xlsx_acceptance import HEADERS, InvalidWorkbook, inspect_workbook

MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
RELS = 'http://schemas.openxmlformats.org/package/2006/relationships'
NAME = '=合成學生'


def workbook_parts():
    cell = lambda ref, text: '<c r="' + ref + '" t="inlineStr"><is><t>' + escape(text) + '</t></is></c>'
    return {
        '[Content_Types].xml': '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
        '_rels/.rels': '<Relationships xmlns="' + RELS + '"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml': '<workbook xmlns="' + MAIN + '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' + NAME + '" sheetId="1" r:id="r1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels': '<Relationships xmlns="' + RELS + '"><Relationship Id="r1" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/styles.xml': '<styleSheet xmlns="' + MAIN + '"><numFmts><numFmt numFmtId="164" formatCode="yyyy-mm-dd hh:mm"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml': '<worksheet xmlns="' + MAIN + '"><sheetData><row r="1">' + cell('A1', NAME) + '</row><row r="2"><c r="B2" s="1"><v>46335.5</v></c></row><row r="6">' + ''.join(cell(col + '6', text) for col, text in zip('ABCDEFGH', HEADERS)) + '</row><row r="7">' + cell('A7', NAME) + '<c r="F7"><v>100</v></c><c r="G7"><v>0</v></c><c r="H7"><v>100</v></c></row><row r="9">' + cell('A9', '帳單') + cell('B9', 'INV-SYNTHETIC') + cell('C9', '狀態') + '</row></sheetData></worksheet>',
    }


class StudentWorkbookAcceptanceTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='synthetic-xlsx-')
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / 'synthetic.xlsx'

    def write(self, parts):
        with zipfile.ZipFile(self.path, 'w', zipfile.ZIP_DEFLATED) as archive:
            for name, text in parts.items():
                archive.writestr(name, text)

    def test_native_zip_with_literal_name_and_numeric_date_and_amounts(self):
        self.write(workbook_parts())
        self.assertEqual(inspect_workbook(self.path, NAME, 1),
                         {'valid': True, 'sheets': 1, 'summary_rows': 1, 'numeric_amount_cells': 3})

    def test_wrong_student_or_source_count_does_not_pass(self):
        self.write(workbook_parts())
        for name, count in [('其他合成', 1), (NAME, 2)]:
            with self.assertRaises(InvalidWorkbook):
                inspect_workbook(self.path, name, count)

    def test_shared_string_cells_used_by_phpspreadsheet_are_supported(self):
        parts = workbook_parts()
        sheet = ET.fromstring(parts['xl/worksheets/sheet1.xml'])
        strings = ET.Element('{' + MAIN + '}sst')
        for cell in sheet.findall('.//{' + MAIN + '}c'):
            if cell.get('t') != 'inlineStr':
                continue
            text = ''.join(cell.find('{' + MAIN + '}is').itertext())
            index = len(strings)
            item = ET.SubElement(strings, '{' + MAIN + '}si')
            ET.SubElement(item, '{' + MAIN + '}t').text = text
            cell.remove(cell.find('{' + MAIN + '}is'))
            cell.set('t', 's')
            ET.SubElement(cell, '{' + MAIN + '}v').text = str(index)
        parts['xl/worksheets/sheet1.xml'] = ET.tostring(sheet)
        parts['xl/sharedStrings.xml'] = ET.tostring(strings)
        self.write(parts)
        self.assertTrue(inspect_workbook(self.path, NAME, 1)['valid'])

    def test_formulas_links_and_wrong_cell_type_are_rejected(self):
        for replacement in ['<c r="B2" t="str"><v>46335.5</v></c>', '<c r="B2"><f>1+1</f><v>2</v></c>']:
            parts = workbook_parts()
            parts['xl/worksheets/sheet1.xml'] = parts['xl/worksheets/sheet1.xml'].replace('<c r="B2" s="1"><v>46335.5</v></c>', replacement)
            self.write(parts)
            with self.assertRaises(InvalidWorkbook):
                inspect_workbook(self.path, NAME, 1)
        parts = workbook_parts()
        parts['xl/_rels/workbook.xml.rels'] = '<Relationships xmlns="' + RELS + '"><Relationship Id="r1" TargetMode="External" Target="https://example.test/"/></Relationships>'
        self.write(parts)
        with self.assertRaises(InvalidWorkbook):
            inspect_workbook(self.path, NAME, 1)

    def test_multiple_student_sheets_and_unsafe_entries_are_rejected(self):
        for entry in ['extra-sheet', '../outside.xml']:
            parts = workbook_parts()
            if entry == 'extra-sheet':
                parts['xl/workbook.xml'] = parts['xl/workbook.xml'].replace('</sheets>', '<sheet name="其他合成" sheetId="2"/></sheets>')
            else:
                parts[entry] = 'synthetic'
            self.write(parts)
            with self.assertRaises(InvalidWorkbook):
                inspect_workbook(self.path, NAME, 1)

    def test_cli_failure_never_prints_private_input_or_html_download(self):
        self.path.write_text('<html>PRIVATE_SYNTHETIC_MARKER</html>')
        result = subprocess.run([sys.executable, str(Path(__file__).resolve().parents[1] / 'student_bill_xlsx_acceptance.py')],
                                input=json.dumps({'path': str(self.path), 'student': 'PRIVATE_SYNTHETIC_MARKER', 'sources': 1}),
                                text=True, capture_output=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'valid': False, 'reason': 'invalid-xlsx'})
        self.assertEqual(result.stderr, '')


if __name__ == '__main__':
    unittest.main()
