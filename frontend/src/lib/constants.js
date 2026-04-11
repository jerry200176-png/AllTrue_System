/**
 * @deprecated Use `branches` from `../lib/useBranches.js` instead.
 * Branches are now fetched dynamically from the backend API.
 * This constant is kept only as a legacy fallback reference.
 */
export const BRANCHES_LEGACY = [
    { id: 'daan', name: '大安分校 (Daan)' },
    { id: 'dazhi', name: '大直分校 (Dazhi)' },
    { id: 'donghu', name: '東湖分校 (Donghu)' },
    { id: 'muzha', name: '木柵分校 (Muzha)' },
    { id: 'neihu', name: '內湖分校 (Neihu)' },
    { id: 'xinglong', name: '興隆分校 (Xinglong)' },
    { id: 'xindian', name: '新店分校 (Xindian)' },
    { id: 'xizhi', name: '汐止分校 (Xizhi)' },
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
    { value: 'Chinese', label: '國文' },
    { value: 'English', label: '英文' },
    { value: 'Math', label: '數學' },
    { value: 'Science', label: '理化' },
    { value: 'Chemistry', label: '化學' },
    { value: 'Physics', label: '物理' },
    { value: 'Biology', label: '生物' },
    { value: 'Social', label: '社會' }
];

const GRADE_TO_LEVEL = {
    P1: 'elementary', P2: 'elementary', P3: 'elementary',
    P4: 'elementary', P5: 'elementary', P6: 'elementary',
    J1: 'junior', J2: 'junior', J3: 'junior',
    H1: 'high', H2: 'high', H3: 'high',
};

const GRADE_ID_TO_LEVEL = {
    1: 'elementary', 2: 'elementary', 3: 'elementary',
    4: 'elementary', 5: 'elementary', 6: 'elementary',
    7: 'junior', 8: 'junior', 9: 'junior',
    10: 'high', 11: 'high', 12: 'high',
};

const LEVEL_LABELS = { elementary: '國小', junior: '國中', high: '高中' };

const SUBJECT_NAME_MAP = {
    Chinese: '國文', 國文: '國文',
    English: '英文', 英文: '英文',
    Math: '數學', Mathematics: '數學', 數學: '數學',
    Social: '社會', 社會: '社會',
    Science: '理化', 理化: '理化', 自然: '理化',
    Physics: '物理', 物理: '物理',
    Chemistry: '化學', 化學: '化學',
    Biology: '生物', 生物: '生物',
};

export function getSubjectLabel(value) {
    return SUBJECT_NAME_MAP[value] || value || '';
}

export function resolveGradeLevel(grade) {
    if (!grade) return null;
    if (GRADE_TO_LEVEL[grade]) return GRADE_TO_LEVEL[grade];
    const num = Number(grade);
    if (Number.isFinite(num) && GRADE_ID_TO_LEVEL[num]) return GRADE_ID_TO_LEVEL[num];
    return null;
}

/** Same idea as backend TeacherScopeService: group Subject rows by display name. */
function subjectEquivalenceKey(s) {
    if (!s || typeof s !== 'object') return '';
    const v = s.value;
    const lbl = s.label ?? s.Subject_Name ?? s.name ?? '';
    return getSubjectLabel(v) || getSubjectLabel(lbl) || String(lbl || '').trim() || String(v || '').trim();
}

function resolveSelectedSubjectKey(subjectValue, subjectsList) {
    const strVal = String(subjectValue ?? '').trim();
    const numVal = Number(subjectValue);
    if (Number.isFinite(numVal) && numVal > 0) {
        const byId = subjectsList.find((s) => Number(s?.id) === numVal);
        if (byId) return subjectEquivalenceKey(byId);
    }
    const byField = subjectsList.find(
        (s) => s?.value === subjectValue || s?.id === subjectValue
            || String(s?.label) === strVal || String(s?.Subject_Name) === strVal || String(s?.name) === strVal
    );
    if (byField) return subjectEquivalenceKey(byField);
    return getSubjectLabel(strVal) || strVal;
}

/**
 * Check teacher scope against subject + student grade.
 * Returns a warning string or null.
 * @param {object} teacher - teacher object with subject_level_scopes[]
 * @param {string} subjectValue - e.g. 'Math'
 * @param {string|number|null} studentGrade - e.g. 'P3' or 7
 * @param {object[]} subjectsList - subjects list with {id, value, label / Subject_Name}
 */
export function checkTeacherScope(teacher, subjectValue, studentGrade, subjectsList = []) {
    if (!teacher || subjectValue == null || subjectValue === '') return null;
    const scopes = Array.isArray(teacher.subject_level_scopes) ? teacher.subject_level_scopes : [];
    if (scopes.length === 0) return null;

    const list = Array.isArray(subjectsList) ? subjectsList : [];
    const selectedKey = resolveSelectedSubjectKey(subjectValue, list);
    let matchIds = list
        .filter((s) => s?.id != null && s?.id !== '' && selectedKey && subjectEquivalenceKey(s) === selectedKey)
        .map((s) => Number(s.id))
        .filter((id) => Number.isFinite(id) && id > 0);

    if (matchIds.length === 0) {
        const entry = list.find(
            (s) => s?.value === subjectValue || s?.id === subjectValue
                || String(s?.label) === String(subjectValue) || String(s?.Subject_Name) === String(subjectValue)
        );
        if (entry?.id != null && entry.id !== '') {
            const one = Number(entry.id);
            if (Number.isFinite(one) && one > 0) matchIds = [one];
        }
    }

    if (matchIds.length === 0) return null;

    const level = resolveGradeLevel(studentGrade);
    const teacherName = teacher.username || teacher.name || teacher.Name || '';
    const subjectLabel = SUBJECT_NAME_MAP[subjectValue] || getSubjectLabel(String(subjectValue)) || String(subjectValue);
    const levelLabel = level ? (LEVEL_LABELS[level] || level) : null;

    const hasSubject = scopes.some((s) => matchIds.includes(Number(s.subject_id)));
    if (!hasSubject) {
        return `${teacherName} 的授課設定中沒有「${subjectLabel}」科目`;
    }

    if (level) {
        const hasMatch = scopes.some(
            (s) => matchIds.includes(Number(s.subject_id)) && s.level === level
        );
        if (!hasMatch) {
            return `${teacherName} 可教「${subjectLabel}」但未包含「${levelLabel}」學段`;
        }
    }

    return null;
}
