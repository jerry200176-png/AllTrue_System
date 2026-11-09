// @ts-check
import { installReadOnlyAppGuard } from './readOnlyAppGuard.js';

export const BILL_EXPORT_PATH = '/api/v1/accounting/bills/export';
const CAP = 2000;
const positive = value => Number.isSafeInteger(value) && value > 0;

/** Compare identifiers only; neither names nor request bodies enter reports. */
export function matchesVisibleBillExport(appOrigin, method, url, body, keys, search) {
  try {
    const target = new URL(String(url));
    if (method !== 'POST' || target.origin !== appOrigin || target.pathname !== BILL_EXPORT_PATH
      || target.search || target.hash || target.username || target.password) return false;
    if (!body || Object.keys(body).some(key => !['branch_id', 'filter', 'search', 'rows'].includes(key))
      || !positive(body.branch_id) || body.filter !== 'all' || body.search !== search
      || !search || search.length > 60 || !Array.isArray(body.rows)
      || !keys.length || keys.length > CAP || body.rows.length !== keys.length) return false;
    const actual = body.rows.map(row => {
      if (!row || Object.keys(row).some(key => !['id', 'package_id'].includes(key))
        || (row.id != null && !positive(row.id)) || (row.package_id != null && !positive(row.package_id))) return '';
      return positive(row.id) ? 'c' + row.id : positive(row.package_id) ? 'p' + row.package_id : '';
    });
    actual.sort();
    return new Set(keys).size === keys.length && keys.every(key => /^[cp][1-9][0-9]*$/.test(key))
      && new Set(actual).size === actual.length && [...keys].sort().every((key, index) => key === actual[index]);
  } catch { return false; }
}

/** Install only immediately before this test's single UI export. Every other write retains the existing guard. */
export async function installSingleStudentExportGuard(context, appOrigin, keys, search) {
  const blocked = await installReadOnlyAppGuard(context, appOrigin, { allowTransactionTelemetry: true });
  let allowed = 0;
  let attempted = false;
  await context.route(url => url.origin === appOrigin && url.pathname === BILL_EXPORT_PATH, async route => {
    const request = route.request();
    let body;
    try { body = request.postDataJSON(); } catch { body = null; }
    const block = async () => {
      const url = new URL(request.url());
      blocked.push({ method: request.method(), origin: url.origin, path: url.pathname });
      await route.abort('blockedbyclient');
    };
    if (attempted || !matchesVisibleBillExport(appOrigin, request.method(), request.url(), body, keys, search)) {
      await block();
      return;
    }
    attempted = true; // A failed download must not authorize a second audited export.
    let response;
    try {
      // Browser redirects bypass routing after the first hop. Fetch without following
      // any redirect, then fulfill only the directly returned native download.
      response = await route.fetch({ maxRedirects: 0 });
      const headers = response.headers();
      if (response.status() !== 200 || response.url() !== request.url() || headers.location
        || headers['content-type']?.split(';')[0].trim().toLowerCase() !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        || !/^attachment(?:;|$)/i.test(headers['content-disposition'] || '')) {
        await block();
        return;
      }
      const bytes = await response.body();
      if (bytes.length > 32 * 1024 * 1024 || !bytes.subarray(0, 4).equals(Buffer.from([0x50, 0x4b, 0x03, 0x04]))) {
        await block();
        return;
      }
      await route.fulfill({ response, body: bytes });
      allowed += 1;
    } catch {
      await block();
    } finally {
      await response?.dispose();
    }
  });
  return { blocked, exportCount: () => allowed };
}
