// Matches a school name typed in AllTrue to the reference exam-date rows (schoolExamReferenceData.generated.js).

export const CITIES = ['臺北市', '新北市', '基隆市', '桃園市', '新竹市', '新竹縣', '苗栗縣', '臺中市', '彰化縣', '南投縣', '雲林縣',
  '嘉義市', '嘉義縣', '臺南市', '高雄市', '屏東縣', '宜蘭縣', '花蓮縣', '臺東縣', '澎湖縣', '金門縣', '連江縣'];
const SHORT = new Set(CITIES.map((c) => c.slice(0, 2)));
const NOISE = new Set(['代用', '小']);

/**
 * "臺北市立大安國民中學" becomes { city: '臺北市', name: '大安國中' }; "大安國中（臺北）" gives city '臺北' (two characters:
 * the writer did not say 市 or 縣); no city gives ''.
 */
export function normalizeSchool(raw) {
  let s = String(raw || '').replace(/\s+/g, '').replace(/台/g, '臺');
  let city = '';
  const tail = s.match(/[（(]([^（()）]*)[）)]$/);
  // Only a city, （代用） or （小） is dropped; anything else, such as （北屯校區）, says which school it is and stays.
  if (tail && (SHORT.has(tail[1]) || CITIES.includes(tail[1]) || NOISE.has(tail[1]))) {
    if (!NOISE.has(tail[1])) city = tail[1];
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

/** One pass over the data: normalized school name -> its rows, and the cities the table covers. Build once. */
export function buildReferenceIndex(data) {
  const byName = new Map();
  for (const row of data?.rows || []) {
    const { name } = normalizeSchool(row[1]);
    if (!byName.has(name)) byName.set(name, []);
    byName.get(name).push(row);
  }
  return { year: data?.year, semester: data?.semester, byName, cities: [...new Set((data?.rows || []).map((r) => r[0]))] };
}

const inCity = (rowCity, city) => rowCity === city || (city.length === 2 && rowCity.startsWith(city));

/**
 * Reference options for one school and term, by slot: { 1: [{ label, city, whole, start_date, end_date }], ... }.
 * `names` is the school's listed name, then the spellings people typed for it (the list shows a catalogued school under
 * its official name, e.g. 臺北市立建國高級中學, while the table says 建國中學). The city written in a name decides;
 * otherwise `city` (the one the director picked) does; with neither, every school of that name is offered.
 * `sure` says the match is one school in one city (only then is filling without a look acceptable).
 */
export function referenceFor(index, names, year, semester, city = '') {
  const out = { sure: false, slots: {} };
  if (!index || index.year !== year || index.semester !== semester) return out;
  const wants = [names].flat().map(normalizeSchool).filter((w) => w.name);
  // The official name of a catalogued school carries a city that nobody typed (a bare 中正國中 is listed as the Taipei
  // one, wherever the campus is), so when typed spellings are given, only they may say the city.
  const use = (wants.length > 1 ? wants.slice(1) : wants).find((w) => w.city)?.city || city;
  let hits = [...new Set(wants.map((w) => w.name))].flatMap((name) => index.byName.get(name) || []);
  if (use) hits = hits.filter((r) => inCity(r[0], use));
  out.sure = !!use && new Set(hits.map((r) => `${r[0]}|${r[1]}`)).size === 1;
  // Two schools of one city under one name (大同高中 and 私立大同高中): the option says which school it is.
  const named = new Set(hits.map((r) => `${r[0]}|${r[1]}`)).size > new Set(hits.map((r) => r[0])).size;
  for (const [rowCity, school, grade, exam, seq, start, end] of hits) {
    const variant = /[（(]/.test(exam) ? exam.slice(exam.search(/[（(]/)) : '';
    // `whole`: the date is for every grade of that school. The screen saves an all-grade date, so a date for some
    // grades or one subject (二~六年級, 高三, （英文）) is only ever offered, never filled without a look.
    const kind = normalizeSchool(school).name;
    const whole = !variant && (grade === '全校' || (grade === '一~六年級' && kind.endsWith('國小')) || (grade === '國中' && kind.endsWith('國中')));
    (out.slots[seq] ||= []).push({ label: `${named ? `${school} ` : ''}${grade}${variant}`, city: rowCity, whole, start_date: start, end_date: end });
  }
  return out;
}
