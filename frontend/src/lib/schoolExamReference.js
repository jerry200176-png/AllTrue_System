// Matches a school name typed in AllTrue to the reference exam-date rows (schoolExamReferenceData.generated.js).

export const CITIES = ['臺北市', '新北市', '基隆市', '桃園市', '新竹市', '新竹縣', '苗栗縣', '臺中市', '彰化縣', '南投縣', '雲林縣',
  '嘉義市', '嘉義縣', '臺南市', '高雄市', '屏東縣', '宜蘭縣', '花蓮縣', '臺東縣', '澎湖縣', '金門縣', '連江縣'];
const SHORT = new Set(CITIES.map((c) => c.slice(0, 2)));

/**
 * "臺北市立大安國民中學" becomes { city: '臺北市', name: '大安國中' }; "大安國中（臺北）" gives city '臺北' (two characters:
 * the writer did not say 市 or 縣); no city gives ''. Any other trailing bracket, such as （代用）, is dropped.
 */
export function normalizeSchool(raw) {
  let s = String(raw || '').replace(/\s+/g, '').replace(/台/g, '臺');
  let city = '';
  const tail = s.match(/[（(]([^（()）]*)[）)]$/);
  if (tail) {
    if (SHORT.has(tail[1])) city = tail[1];
    s = s.slice(0, tail.index);
  }
  const head = CITIES.find((c) => s.startsWith(c) && s.length > c.length + 2);
  if (head) {
    city = head;
    s = s.slice(head.length);
  }
  s = s.replace(/^(市立|縣立|國立|私立|立)/, '')
    .replace(/國民中學/g, '國中').replace(/國民小學/g, '國小')
    .replace(/高級中等學校|高級中學/g, '高中');
  return { city, name: s };
}

/** One pass over the data: normalized school name -> its rows. Build once when the data arrives. */
export function buildReferenceIndex(data) {
  const byName = new Map();
  for (const row of data?.rows || []) {
    const { name } = normalizeSchool(row[1]);
    if (!byName.has(name)) byName.set(name, []);
    byName.get(name).push(row);
  }
  return { year: data?.year, semester: data?.semester, byName };
}

const inCity = (rowCity, city) => rowCity === city || (city.length === 2 && rowCity.startsWith(city));

/**
 * Reference options for one school and term, by slot: { 1: [{ label, city, start_date, end_date }], ... }.
 * The city written in the school name decides; otherwise `city` (the one the director picked) does; with neither,
 * every school of that name is offered. Each option carries its city so the screen can always show it.
 * `sure` on the result says a city narrowed the match (only then is filling without a look acceptable).
 */
export function referenceFor(index, schoolName, year, semester, city = '') {
  const out = { sure: false, slots: {} };
  if (!index || index.year !== year || index.semester !== semester) return out;
  const want = normalizeSchool(schoolName);
  const use = want.city || city;
  let hits = index.byName.get(want.name) || [];
  if (use) hits = hits.filter((r) => inCity(r[0], use));
  out.sure = !!use && new Set(hits.map((r) => r[0])).size === 1;
  for (const [rowCity, , grade, exam, seq, start, end] of hits) {
    const variant = /[（(]/.test(exam) ? exam.slice(exam.search(/[（(]/)) : '';
    (out.slots[seq] ||= []).push({ label: `${grade}${variant}`, city: rowCity, start_date: start, end_date: end });
  }
  return out;
}
