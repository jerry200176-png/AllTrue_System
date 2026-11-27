import test from 'node:test';
import assert from 'node:assert/strict';
import data from './schoolExamReferenceData.generated.js';
import { buildReferenceIndex, CITIES, normalizeSchool, referenceFor } from './schoolExamReference.js';

test('school names written different ways meet at one name', () => {
  assert.deepEqual(normalizeSchool('臺北市立大安國民中學'), { city: '臺北市', name: '大安國中' });
  assert.deepEqual(normalizeSchool('台北市大安國中'), { city: '臺北市', name: '大安國中' });
  assert.deepEqual(normalizeSchool(' 中正國中（臺北） '), { city: '臺北', name: '中正國中' });
  assert.deepEqual(normalizeSchool('南榮國中（代用）'), { city: '', name: '南榮國中' });
  assert.deepEqual(normalizeSchool('康橋國中（小）'), { city: '', name: '康橋國中' });
  assert.deepEqual(normalizeSchool('臺中市私立葳格高級中學附設小學（北屯校區）'), { city: '臺中市', name: '葳格高中附設小學（北屯校區）' });
  assert.deepEqual(normalizeSchool('中正國中（臺北市）'), { city: '臺北市', name: '中正國中' });
  assert.deepEqual(normalizeSchool('臺北市私立再興中學'), { city: '臺北市', name: '再興中學' });
  assert.deepEqual(normalizeSchool('國立師大附中'), { city: '', name: '師大附中' });
  assert.deepEqual(normalizeSchool('新竹縣立某某國民小學'), { city: '新竹縣', name: '某某國小' });
  assert.deepEqual(normalizeSchool(''), { city: '', name: '' });
});

test('reference rows are well formed and every city is a known one', () => {
  assert.ok(data.rows.length > 1000);
  assert.deepEqual(buildReferenceIndex(data).cities.sort(), ['新北市', '桃園市', '臺北市']); // the scope the Founder chose
  for (const [city, school, grade, exam, seq, start, end] of data.rows) {
    assert.ok(CITIES.includes(city), `${city} ${school}`);
    assert.ok(school && grade && exam, school);
    assert.ok([1, 2, 3].includes(seq), school);
    assert.match(start, /^202[67]-\d{2}-\d{2}$/);
    assert.match(end, /^202[67]-\d{2}-\d{2}$/);
    assert.ok(start <= end, school);
  }
});

const sample = buildReferenceIndex({ year: 115, semester: 1, rows: [
  ['臺北市', '測試高中', '高一二', '第一次期中', 1, '2026-10-13', '2026-10-14'],
  ['臺北市', '測試高中', '高三', '期末', 3, '2026-12-30', '2026-12-31'],
  ['臺北市', '測試高中', '高一二', '期末（英文）', 3, '2027-01-18', '2027-01-19'],
  ['臺北市', '中正國中（臺北）', '國中', '第一次期中', 1, '2026-10-08', '2026-10-09'],
  ['屏東縣', '中正國中（屏東）', '國中', '第一次期中', 1, '2026-10-15', '2026-10-16'],
  ['新竹市', '光華國中（新竹）', '國中', '期末', 3, '2027-01-18', '2027-01-19'],
  ['新竹縣', '光華國中', '國中', '期末', 3, '2027-01-14', '2027-01-15'],
  ['臺北市', '建國中學', '高一二', '期末', 3, '2027-01-18', '2027-01-20'],
  ['臺中市', '臺中市私立葳格高級中學附設小學（北屯校區）', '全校', '期末', 3, '2027-01-12', '2027-01-13'],
  ['臺中市', '臺中市私立葳格高級中學附設小學（西屯校區）', '全校', '期末', 3, '2027-01-14', '2027-01-15'],
] });

test('a school gets its options per slot, only for the matching term', () => {
  assert.deepEqual(referenceFor(sample, '臺北市立測試高級中學', 115, 1), { sure: true, slots: {
    1: [{ label: '高一二', city: '臺北市', whole: false, start_date: '2026-10-13', end_date: '2026-10-14' }],
    3: [{ label: '高三', city: '臺北市', whole: false, start_date: '2026-12-30', end_date: '2026-12-31' },
      { label: '高一二（英文）', city: '臺北市', whole: false, start_date: '2027-01-18', end_date: '2027-01-19' }],
  } });
  // Only a date for every grade of the school counts as whole: 國中 at a 國中, 全校 anywhere.
  assert.equal(referenceFor(sample, '臺北市立中正國民中學', 115, 1).slots[1][0].whole, true);
  assert.equal(referenceFor(sample, '臺中市私立葳格高級中學附設小學（西屯校區）', 115, 1).slots[3][0].whole, true);
  assert.deepEqual(referenceFor(sample, '測試高中', 115, 2), { sure: false, slots: {} });
  assert.deepEqual(referenceFor(sample, '沒有這間國中', 115, 1, '臺北市'), { sure: false, slots: {} });
  assert.deepEqual(referenceFor(null, '測試高中', 115, 1), { sure: false, slots: {} });
});

test('a name alone is never sure; the city in the name, or the chosen city, narrows it', () => {
  // No city anywhere: even a single match in the data is not sure (the data does not hold every school).
  assert.equal(referenceFor(sample, '測試高中', 115, 1).sure, false);
  const both = referenceFor(sample, '中正國中', 115, 1);
  assert.deepEqual([both.sure, both.slots[1].map((o) => o.city)], [false, ['臺北市', '屏東縣']]);
  const named = referenceFor(sample, '臺北市立中正國民中學', 115, 1, '屏東縣'); // the name's own city wins over the chosen one
  assert.deepEqual([named.sure, named.slots[1].map((o) => o.start_date)], [true, ['2026-10-08']]);
  const chosen = referenceFor(sample, '中正國中', 115, 1, '屏東縣');
  assert.deepEqual([chosen.sure, chosen.slots[1].map((o) => o.start_date)], [true, ['2026-10-15']]);
  // 新竹市 and 新竹縣 are different places.
  assert.deepEqual(referenceFor(sample, '新竹市立光華國民中學', 115, 1).slots[3].map((o) => o.start_date), ['2027-01-18']);
  assert.deepEqual(referenceFor(sample, '光華國中', 115, 1, '新竹縣').slots[3].map((o) => o.start_date), ['2027-01-14']);
  // A two-character city in brackets cannot tell 市 from 縣: both are offered and it is not sure.
  const loose = referenceFor(sample, '光華國中（新竹）', 115, 1);
  assert.deepEqual([loose.sure, loose.slots[3].length], [false, 2]);
});

test('other spellings of the school are tried too, and a campus in brackets is part of the name', () => {
  // The list shows the official name; the table uses the everyday one, which a student typed.
  assert.deepEqual(referenceFor(sample, '臺北市立建國高級中學', 115, 1).slots, {});
  // ...but nobody typed the city, so it is offered, not sure, until the director picks the city or someone typed it.
  const alias = referenceFor(sample, ['臺北市立建國高級中學', '建國中學', '建中'], 115, 1);
  assert.deepEqual([alias.sure, alias.slots[3].map((o) => o.start_date)], [false, ['2027-01-18']]);
  assert.equal(referenceFor(sample, ['臺北市立建國高級中學', '建國中學'], 115, 1, '臺北市').sure, true);
  assert.equal(referenceFor(sample, ['臺北市立建國高級中學', '台北市建國中學'], 115, 1).sure, true);
  // A bare 中正國中 typed at a campus elsewhere is listed under the Taipei school: the chosen city decides, not the listing.
  const elsewhere = referenceFor(sample, ['臺北市立中正國民中學', '中正國中'], 115, 1, '屏東縣');
  assert.deepEqual([elsewhere.sure, elsewhere.slots[1].map((o) => o.start_date)], [true, ['2026-10-15']]);
  const campus = referenceFor(sample, '臺中市私立葳格高級中學附設小學（西屯校區）', 115, 1);
  assert.deepEqual([campus.sure, campus.slots[3].map((o) => o.start_date)], [true, ['2027-01-14']]);
  assert.deepEqual(referenceFor(sample, '臺中市私立葳格高級中學附設小學', 115, 1).slots, {}); // which campus? not guessed
});
