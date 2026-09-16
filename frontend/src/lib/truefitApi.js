/**
 * TrueFit read-only API client (Slice 0).
 */

export async function fetchTrueFitTodaySessions({ token, branchId } = {}) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', String(branchId));

  const res = await fetch(`/api/v1/truefit/today-sessions?${params.toString()}`, {
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (res.status === 404) {
    const err = new Error('TrueFit 尚未啟用');
    err.code = 'truefit_disabled';
    throw err;
  }
  if (res.status === 403) {
    const err = new Error('您目前無法使用 TrueFit 工作台');
    err.code = 'truefit_forbidden';
    throw err;
  }
  if (!res.ok) {
    throw new Error(`TrueFit 載入失敗（${res.status}）`);
  }

  return res.json();
}
