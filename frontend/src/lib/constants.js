/**
 * @deprecated Use `branches` from `../lib/useBranches.js` instead.
 * Branches are now fetched dynamically from the backend API.
 * This constant is kept only as a legacy fallback reference.
 */
export const BRANCHES_LEGACY = [
    { id: 'daan', name: '大安分校 (Daan)' },
    { id: 'muzha', name: '木柵分校 (Muzha)' },
    { id: 'xinglong', name: '興隆分校 (Xinglong)' },
    { id: 'xindian', name: '新店分校 (Xindian)' }
];

export const GRADES = [
    { value: 'P1', label: '國小一年級 (P1)' },
    { value: 'P2', label: '國小二年級 (P2)' },
    { value: 'P3', label: '國小三年級 (P3)' },
    { value: 'P4', label: '國小四年級 (P4)' },
    { value: 'P5', label: '國小五年級 (P5)' },
    { value: 'P6', label: '國小六年級 (P6)' },
    { value: 'J1', label: '國中一年級 (J1)' },
    { value: 'J2', label: '國中二年級 (J2)' },
    { value: 'J3', label: '國中三年級 (J3)' },
    { value: 'H1', label: '高中一年級 (H1)' },
    { value: 'H2', label: '高中二年級 (H2)' },
    { value: 'H3', label: '高中三年級 (H3)' }
];

export const SUBJECTS = [
    { value: 'Chinese', label: '國文 (Chinese)' },
    { value: 'English', label: '英文 (English)' },
    { value: 'Math', label: '數學 (Math)' },
    { value: 'Physics', label: '物理 (Physics)' },
    { value: 'Chemistry', label: '化學 (Chemistry)' },
    { value: 'Science', label: '自然 (Science)' },
    { value: 'Social', label: '社會 (Social)' }
];
