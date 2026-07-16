/** Trust decision card display helpers (in-app #200). Pure functions for testability. */

export function trustPeopleSlice(item, limit = 8) {
  const list = Array.isArray(item?.people) ? item.people : [];
  return list.slice(0, limit);
}

/** Unique student names for card headline. */
export function trustPeopleSummary(item) {
  const names = trustPeopleSlice(item)
    .map((p) => String(p?.student_name || '').trim())
    .filter(Boolean);
  return [...new Set(names)].slice(0, 5).join('、');
}

/** When one student dominates, put name in title so mobile directors see it without scrolling. */
export function trustDecisionTitle(item) {
  const summary = trustPeopleSummary(item);
  const people = trustPeopleSlice(item);
  if (!summary || !people.length) return item?.title || '';
  const uniqueNames = summary.split('、').filter(Boolean);
  if (uniqueNames.length !== 1) return item?.title || '';
  const name = uniqueNames[0];
  const title = String(item?.title || '');
  if (title.includes(name)) return title;
  if (item?.key === 'calendar_duplicate') {
    const n = Number(item?.people_total || people.length) || people.length;
    return `${name}：${n} 組跨約重複堂`;
  }
  if (item?.key === 'dormant_hold') return `${name}：已付休眠要聯繫`;
  if (item?.key === 'ledger_divergent') return `${name}：剩餘堂數對不起來`;
  if (item?.key === 'stranded_paid') return `${name}：已付還沒排進課表`;
  return title;
}

/** Parse sessionStorage focus payload for duplicate-review deep-link. */
export function parseTrustFocus(raw, nowMs = Date.now(), maxAgeMs = 5 * 60 * 1000) {
  try {
    const focus = JSON.parse(raw);
    const ageMs = nowMs - Number(focus?.at || 0);
    if (ageMs < 0 || ageMs >= maxAgeMs) return null;
    if (focus?.decision_key && focus.decision_key !== 'calendar_duplicate') return null;
    const name = String(focus?.student_name || '').trim();
    const sid = Number(focus?.student_id) || 0;
    if (!name && sid <= 0) return null;
    return { studentName: name, studentId: sid, decisionKey: focus.decision_key || '' };
  } catch {
    return null;
  }
}

/** Filter duplicate-review groups by trust focus. */
export function filterGroupsByTrustFocus(groups, focus) {
  if (!focus) return groups;
  const name = String(focus.studentName || '').trim();
  const sid = Number(focus.studentId) || 0;
  if (!name && sid <= 0) return groups;
  return groups.filter((g) => {
    if (sid > 0 && Number(g.student_id) === sid) return true;
    if (name && String(g.student_name || '').includes(name)) return true;
    return false;
  });
}
