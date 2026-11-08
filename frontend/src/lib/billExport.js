import { authedFetch } from './authedFetch.js';

/**
 * 匯出 Excel (in-app #520): the server writes the .xlsx for exactly the rows the list shows now.
 * `items` = [{ id, package_id, status, period, due, owed }] from BillList.
 */
export async function exportBillsXlsx({ branchId, filter, search, items }) {
  const res = await authedFetch('/api/v1/accounting/bills/export', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ branch_id: Number(branchId), filter, search: search || '', rows: items }),
  });
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message || `匯出失敗（${res.status}）`);
  }
  const blob = await res.blob();
  const name = /filename\*=UTF-8''([^;]+)/i.exec(res.headers.get('Content-Disposition') || '')?.[1];
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name ? decodeURIComponent(name) : '帳務.xlsx';
  a.click();
  URL.revokeObjectURL(url);
}
