// In-app #420: filter the 段考 score-entry rows by student name or number so a
// director can find one student without scrolling the whole round.
export function filterExamStudents(rows, query) {
  const list = Array.isArray(rows) ? rows : [];
  const needle = String(query ?? '').trim().toLowerCase();
  if (!needle) return list;
  return list.filter((row) => {
    const name = String(row?.student_name ?? '').toLowerCase();
    const number = String(row?.student_id ?? '');
    return name.includes(needle) || number === needle;
  });
}

// Directors may enter scores on published and closed rounds (the API rejects
// drafts); teachers only while the round is published.
export function canEditExamRound(round, isDirector) {
  const status = round?.status;
  if (status === 'published') return true;
  return Boolean(isDirector) && status === 'closed';
}

export function examRoundActionLabel(round, isDirector) {
  if (round?.status === 'published') return '登分';
  return canEditExamRound(round, isDirector) ? '查看／登分' : '查看';
}
