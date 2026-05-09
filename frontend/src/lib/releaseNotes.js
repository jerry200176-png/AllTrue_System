export const releaseNotes = [
  {
    version: '2026-05-09',
    title: '老師評量待辦與回饋強化',
    audience: ['teacher', 'director'],
    items: [
      '老師工作台新增每日任務清單（今日待填、逾期待補、需修改）',
      '新增本週評量進度（完成率、連續完成天數、7 日趨勢）',
      '評量頁新增老師優先篩選（未填優先 / 需修改 / 逾期）與急件高亮',
    ],
  },
  {
    version: '2026-05-09',
    title: '代課衝堂錯誤體驗修正',
    audience: ['teacher', 'director'],
    items: [
      '代課衝堂等預期業務拒絕改為表單內提示，不再視為前端例外',
      '老師與主任在代課操作時可直接看到可理解的錯誤訊息',
    ],
  },
  {
    version: '2026-05-09',
    title: '主任評量視角與老師待填角標',
    audience: ['teacher', 'director'],
    items: [
      '老師側欄與底部導覽新增評量待辦角標',
      '主任報表新增老師評量填寫率 API 與對應儀表資訊',
    ],
  },
];

export function notesForRole(role) {
  return releaseNotes.filter((note) => !note.audience?.length || note.audience.includes(role));
}

