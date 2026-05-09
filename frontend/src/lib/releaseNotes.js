export const releaseNotes = [
  {
    version: '2026-05-09',
    title: '評量填寫更直覺',
    audience: ['teacher', 'director'],
    items: [
      '老師工作台現在會直接列出「今天要填的評量」',
      '可以一眼看到本週完成進度和連續完成天數',
      '評量頁支援快速篩選，重要待辦會特別醒目',
    ],
  },
  {
    version: '2026-05-09',
    title: '代課提醒更清楚',
    audience: ['teacher', 'director'],
    items: [
      '若代課時段有衝突，系統會直接提示原因',
      '提示改為清楚的人話，不用再猜是哪裡有問題',
    ],
  },
  {
    version: '2026-05-09',
    title: '待辦提醒更容易發現',
    audience: ['teacher', 'director'],
    items: [
      '評量待辦在選單上會顯示提醒數量',
      '主任可以更快看到各老師的評量填寫進度',
    ],
  },
];

/**
 * Elevated campus roles should see whatever we ship to directors/teachers — they do not maintain
 * a separate curated list in `audience` today (super_admin would otherwise see zero notes).
 */
function roleMatchesNoteAudience(note, role) {
  if (!note.audience?.length) {
    return true;
  }
  if (note.audience.includes(role)) {
    return true;
  }
  const elevatedCampusStaff = ['super_admin', 'admin'];
  if (elevatedCampusStaff.includes(role)) {
    return note.audience.some((a) => a === 'director' || a === 'teacher');
  }
  return false;
}

export function notesForRole(role) {
  return releaseNotes.filter((note) => roleMatchesNoteAudience(note, role));
}

export function latestReleaseVersionForRole(role) {
  const notes = notesForRole(role);
  return notes.length > 0 ? notes[0].version : '';
}

