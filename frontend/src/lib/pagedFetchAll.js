/**
 * Fetch all pages of a Laravel paginated API endpoint in parallel.
 * First request gets page 1 + metadata (total, last_page).
 * Remaining pages are fetched concurrently, then all data arrays are merged.
 *
 * @param {string} url      - Base URL (without page param), e.g. "/api/v1/student-classes?branch_id=1&per_page=200"
 * @param {string} token    - Bearer token
 * @param {object} [opts]
 * @param {number} [opts.perPage=200]       - Override per_page in URL
 * @param {number} [opts.maxPages=50]       - Safety cap
 * @param {number} [opts.concurrency=4]     - Max parallel requests
 * @param {function} [opts.onProgress]      - Called with (loadedSoFar, total)
 * @returns {Promise<{ data: any[], total: number }>}
 */
export async function fetchAllPages(url, token, opts = {}) {
  const { perPage = 200, maxPages = 50, concurrency = 4, onProgress } = opts;

  const headers = { Accept: 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const sep = url.includes('?') ? '&' : '?';
  const buildUrl = (page) => `${url}${sep}per_page=${perPage}&page=${page}`;

  const res1 = await fetch(buildUrl(1), { headers });
  if (!res1.ok) throw new Error(`API error ${res1.status}`);
  const json1 = await res1.json();

  const firstData = Array.isArray(json1?.data) ? json1.data : (Array.isArray(json1) ? json1 : []);
  const total = Number(json1?.total ?? json1?.meta?.total ?? firstData.length);
  const lastPage = Number(json1?.last_page ?? json1?.meta?.last_page ?? 1);
  const pagesToFetch = Math.min(lastPage, maxPages);

  if (onProgress) onProgress(firstData.length, total);

  if (pagesToFetch <= 1) {
    return { data: firstData, total };
  }

  const allData = [...firstData];
  const remaining = [];
  for (let p = 2; p <= pagesToFetch; p++) remaining.push(p);

  const fetchPage = async (page) => {
    const r = await fetch(buildUrl(page), { headers });
    if (!r.ok) return [];
    const j = await r.json();
    return Array.isArray(j?.data) ? j.data : (Array.isArray(j) ? j : []);
  };

  for (let i = 0; i < remaining.length; i += concurrency) {
    const batch = remaining.slice(i, i + concurrency);
    const results = await Promise.all(batch.map(fetchPage));
    for (const arr of results) allData.push(...arr);
    if (onProgress) onProgress(allData.length, total);
  }

  return { data: allData, total };
}
