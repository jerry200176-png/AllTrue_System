// Matches a school name typed in AllTrue to the reference exam-date rows (schoolExamReferenceData.generated.js).

const CITY = /^(臺北市|新北市|桃園市|臺中市|臺南市|高雄市|基隆市|新竹市|嘉義市|[一-鿿]{2}縣)/;

/** "臺北市立大安國民中學", "台北市大安國中" and "大安國中（臺北）" all become { city: '臺北', name: '大安國中' } (city '' when not given). */
export function normalizeSchool(raw) {
  let s = String(raw || '').replace(/\s+/g, '').replace(/台/g, '臺');
  let city = '';
  const tail = s.match(/[（(]([一-鿿]{2,3})[）)]$/);
  if (tail) {
    city = tail[1];
    s = s.slice(0, tail.index);
  }
  const head = s.match(CITY);
  if (head && s.length > head[0].length + 2) {
    city = head[0].slice(0, 2);
    s = s.slice(head[0].length);
  }
  s = s.replace(/^(市立|縣立|國立|私立|立)/, '')
    .replace(/國民中學/g, '國中').replace(/國民小學/g, '國小')
    .replace(/高級中等學校|高級中學/g, '高中');
  return { city, name: s };
}

/**
 * Reference options for one school and term, by slot: { 1: [{ label, start_date, end_date }], 2: [...], 3: [...] }.
 * Empty object when the term differs or no school matches. A name shared by schools in several cities matches
 * only when the typed name says which city; otherwise every candidate is offered with its city in the label.
 */
export function referenceFor(data, schoolName, year, semester) {
  if (!data || data.year !== year || data.semester !== semester) return {};
  const want = normalizeSchool(schoolName);
  if (!want.name) return {};
  let hits = data.rows.filter((r) => normalizeSchool(r[1]).name === want.name);
  if (want.city) hits = hits.filter((r) => r[0].startsWith(want.city));
  const cities = new Set(hits.map((r) => r[0]));
  const out = {};
  for (const [city, , grade, exam, seq, start, end] of hits) {
    const label = `${cities.size > 1 ? `${city} ` : ''}${grade}${/[（(]/.test(exam) ? exam.slice(exam.search(/[（(]/)) : ''}`;
    (out[seq] ||= []).push({ label, start_date: start, end_date: end });
  }
  return out;
}
