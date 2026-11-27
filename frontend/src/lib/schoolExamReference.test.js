import test from 'node:test';
import assert from 'node:assert/strict';
import data from './schoolExamReferenceData.generated.js';
import { normalizeSchool, referenceFor } from './schoolExamReference.js';

test('school names written different ways meet at one name', () => {
  assert.deepEqual(normalizeSchool('臺北市立大安國民中學'), { city: '臺北', name: '大安國中' });
  assert.deepEqual(normalizeSchool('台北市大安國中'), { city: '臺北', name: '大安國中' });
  assert.deepEqual(normalizeSchool(' 中正國中（臺北） '), { city: '臺北', name: '中正國中' });
  assert.deepEqual(normalizeSchool('國立師大附中'), { city: '', name: '師大附中' });
  assert.deepEqual(normalizeSchool('新竹縣立某某國民小學'), { city: '新竹', name: '某某國小' });
  assert.deepEqual(normalizeSchool(''), { city: '', name: '' });
});

test('reference rows are well formed', () => {
  assert.ok(data.rows.length > 1000);
  for (const [city, school, grade, exam, seq, start, end] of data.rows) {
    assert.ok(city && school && grade && exam, school);
    assert.ok([1, 2, 3].includes(seq), school);
    assert.match(start, /^202[67]-\d{2}-\d{2}$/);
    assert.match(end, /^202[67]-\d{2}-\d{2}$/);
    assert.ok(start <= end, school);
  }
});

test('a school gets its options per slot, only for the matching term', () => {
  const sample = { year: 115, semester: 1, rows: [
    ['臺北市', '測試高中', '高一二', '第一次期中', 1, '2026-10-13', '2026-10-14'],
    ['臺北市', '測試高中', '高三', '期末', 3, '2026-12-30', '2026-12-31'],
    ['臺北市', '測試高中', '高一二', '期末（英文）', 3, '2027-01-18', '2027-01-19'],
    ['臺北市', '中正國中（臺北）', '國中', '第一次期中', 1, '2026-10-08', '2026-10-09'],
    ['屏東縣', '中正國中（屏東）', '國中', '第一次期中', 1, '2026-10-15', '2026-10-16'],
  ] };
  assert.deepEqual(referenceFor(sample, '臺北市立測試高級中學', 115, 1), {
    1: [{ label: '高一二', start_date: '2026-10-13', end_date: '2026-10-14' }],
    3: [{ label: '高三', start_date: '2026-12-30', end_date: '2026-12-31' }, { label: '高一二（英文）', start_date: '2027-01-18', end_date: '2027-01-19' }],
  });
  assert.deepEqual(referenceFor(sample, '測試高中', 115, 2), {});
  assert.deepEqual(referenceFor(sample, '沒有這間國中', 115, 1), {});
  // A name used in two cities: the city in the typed name decides; without one, both are offered with the city shown.
  assert.deepEqual(referenceFor(sample, '臺北市立中正國民中學', 115, 1)[1], [{ label: '國中', start_date: '2026-10-08', end_date: '2026-10-09' }]);
  assert.deepEqual(referenceFor(sample, '中正國中', 115, 1)[1].map((o) => o.label), ['臺北市 國中', '屏東縣 國中']);
});
