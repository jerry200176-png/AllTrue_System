/**
 * StudentClass human-first display formatter (in-app #200).
 *
 * Directors must decide without understanding internal IDs.
 * Order: subject → teacher → start date / session counts → SC (tech only).
 * Pure functions — do not assemble ad-hoc strings in Vue templates.
 */

function trimStr(v) {
  return String(v ?? '').trim();
}

/** Format start_date / ISO to M/D for director UI. */
export function formatStudentClassOpenDate(startDate) {
  if (!startDate) return '';
  try {
    const d = new Date(startDate);
    if (Number.isNaN(d.getTime())) return '';
    return `${d.getMonth() + 1}/${d.getDate()}`;
  } catch {
    return '';
  }
}

/**
 * Build display parts for one StudentClass / duplicate-review side.
 *
 * @param {object} side
 * @param {object} [opts]
 * @param {boolean} [opts.forceOpenDate] include open date even if not needed for disambiguation
 * @param {boolean} [opts.forceSessionCount] include session count always
 * @returns {{
 *   subject: string,
 *   teacher: string,
 *   openDate: string,
 *   sessionCountLabel: string,
 *   remainingLabel: string,
 *   primary: string,
 *   techId: string,
 *   techIdVisible: boolean,
 * }}
 */
export function formatStudentClassDisplay(side, opts = {}) {
  const subject = trimStr(side?.subject_name || side?.subject || side?.Subject);
  const teacher = trimStr(side?.teacher_name || side?.teacher || side?.TeacherName);
  const openDate = formatStudentClassOpenDate(side?.start_date || side?.StartDate);
  const scId = Number(side?.student_class_id || side?.StudentClassID || side?.id || 0) || 0;

  const sessionCount = side?.session_count;
  const remaining = side?.remaining_sessions;
  const hasSessionCount = sessionCount != null && sessionCount !== '';
  const hasRemaining = remaining != null && remaining !== '';

  const sessionCountLabel = hasSessionCount ? `${Number(sessionCount)} 堂` : '';
  const remainingLabel = hasRemaining ? `剩 ${Number(remaining)}` : '';

  const parts = [];
  if (subject) parts.push(subject);
  if (teacher) parts.push(teacher);
  if (openDate && (opts.forceOpenDate !== false)) {
    // Always show open date when present — distinguishes renewal overlap pairs.
    parts.push(`開課 ${openDate}`);
  }
  if (sessionCountLabel && (opts.forceSessionCount || !subject || opts.includeSessionCount !== false)) {
    parts.push(sessionCountLabel);
  }
  if (remainingLabel && opts.includeRemaining) {
    parts.push(remainingLabel);
  }

  let primary = parts.join(' · ');
  if (!primary) {
    primary = scId > 0 ? '一門課程（詳見下方）' : '課程資料不足';
  }

  return {
    subject,
    teacher,
    openDate,
    sessionCountLabel,
    remainingLabel,
    primary,
    techId: scId > 0 ? `SC #${scId}` : '',
    techIdVisible: scId > 0,
  };
}

/**
 * Given multiple sides in one decision group, return display map keyed by student_class_id.
 * Ensures directors can tell sides apart using human fields (not SC).
 */
export function formatStudentClassSideDisplays(sides) {
  const list = Array.isArray(sides) ? sides : [];
  const displays = list.map((side) => ({
    side,
    display: formatStudentClassDisplay(side, { includeSessionCount: true }),
  }));

  // If two primaries collide, append remaining when available.
  const primaryCounts = displays.reduce((acc, row) => {
    acc[row.display.primary] = (acc[row.display.primary] || 0) + 1;
    return acc;
  }, {});

  return displays.map(({ side, display }) => {
    if (primaryCounts[display.primary] > 1 && display.remainingLabel) {
      return {
        ...display,
        primary: `${display.primary} · ${display.remainingLabel}`,
      };
    }
    if (primaryCounts[display.primary] > 1 && !display.openDate) {
      // Last resort human hint without promoting SC into primary.
      return {
        ...display,
        primary: display.primary === '課程資料不足'
          ? display.primary
          : `${display.primary} · 另一份合約`,
      };
    }
    return display;
  });
}

/** True if primary label depends on understanding internal IDs — forbidden for director decision UI. */
export function primaryLeaksInternalId(primary) {
  const s = String(primary || '');
  return (
    /\bSC\s*#?\d+/i.test(s)
    || /課程\s*#\s*\d+/i.test(s)
    || /COURSE-\d+/i.test(s)
    || /新課程\s*#\s*\d+/i.test(s)
  );
}

/**
 * Tuition settle dialog — secondary line under student/subject.
 * Display-only; does not invent API fields.
 */
export function formatTuitionSettleSummary(row) {
  const remaining = row?.remaining_sessions;
  const hasRemaining = remaining != null && remaining !== '';
  const openDate = formatStudentClassOpenDate(row?.start_date || row?.StartDate);
  const parts = [];
  if (openDate) parts.push(`開課 ${openDate}`);
  if (hasRemaining) parts.push(`剩餘 ${Number(remaining)} 堂`);
  const primary = parts.length ? parts.join(' · ') : '即將結案的舊課程';
  const scId = Number(row?.id || row?.student_class_id || 0) || 0;
  return {
    primary,
    techId: scId > 0 ? `SC #${scId}` : '',
  };
}

/**
 * "Has newer course" badge title / settle dialog hint.
 * Uses open date + remaining; never leads with course id.
 */
export function formatTuitionNewerCourseHint(row) {
  const openDate = formatStudentClassOpenDate(row?.newer_course_start_date);
  const rem = row?.newer_course_remaining;
  const hasRem = rem != null && rem !== '';
  const parts = [];
  if (openDate) parts.push(`開課 ${openDate}`);
  if (hasRem) parts.push(`剩 ${Number(rem)} 堂`);
  const detail = parts.length ? `（${parts.join('，')}）` : '';
  const primary = `已有後續同科目課程${detail}`;
  const newerId = Number(row?.newer_course_id || 0) || 0;
  return {
    primary,
    shortBadge: '已有新課程',
    techId: newerId > 0 ? `SC #${newerId}` : '',
  };
}

/** Trust / roster person label — never「學生 #id」. */
export function formatDirectorPersonName(person) {
  const name = trimStr(person?.student_name || person?.name);
  return name || '名單上的學生';
}

/**
 * User Task: 續報／加購完成後的成功說明（主任第一眼確認）。
 * kind: 'purchase' | 'monthly'
 */
export function formatRenewSuccessMessage({
  kind = 'purchase',
  studentName = '',
  subject = '',
  sessions = null,
  firstDate = '',
  lastDate = '',
} = {}) {
  const who = trimStr(studentName);
  const subj = trimStr(subject) || '課程';
  const head = who ? `${who}的${subj}` : subj;
  if (kind === 'monthly') {
    return `${head}已建立新一期。舊期已結算，請到新一期帳單核帳。`;
  }
  const n = sessions != null && sessions !== '' ? Number(sessions) : null;
  const range = firstDate && lastDate ? `，上課 ${firstDate} 至 ${lastDate}` : '';
  const countPart = Number.isFinite(n) ? ` ${n} 堂` : '';
  return `${head}已新增加購${countPart}${range}。請到新批次查看日期；原課程不會追加堂次。`;
}

/** Duplicate purchase error hint — no course id. */
export function formatDuplicatePurchaseHint({ subject = '' } = {}) {
  const subj = trimStr(subject);
  return subj
    ? `\n\n已有相同「${subj}」加購批次，請先確認是否已經續報過。`
    : '\n\n已有相同加購批次，請先確認是否已經續報過。';
}

/** Ledger / invoice course line — never COURSE-000123. */
export function formatLedgerCourseLabel(row) {
  const ref = trimStr(row?.course_ref);
  if (ref && !/^COURSE-\d+$/i.test(ref)) return ref;
  const subject = trimStr(row?.subject_name || row?.subject || row?.Subject);
  if (subject) return subject;
  return '本課程';
}

/** Ledger invoice title — prefer invoice_no, never Invoice #id alone. */
export function formatLedgerInvoiceLabel(inv) {
  const no = trimStr(inv?.invoice_no);
  if (no) return no;
  return '帳單';
}

/** Receipt row secondary line. */
export function formatLedgerReceiptBillLine(r) {
  const bill = r?.invoice_id ? '已套用帳單' : '尚未套用帳單';
  return `${bill} · ${formatLedgerCourseLabel(r)}`;
}

/** Anomaly detail — no Payment # / bare invoice id. */
export function formatLedgerAnomalyDetail(a) {
  const parts = [];
  if (a?.report_id) parts.push('相關收據可沖銷');
  if (a?.invoice_id) parts.push('已對應帳單');
  return parts.join(' · ');
}

/**
 * User Task: 請假 — 批次請假略過原因（後端偶發英文／SQL 訊息時改人話）。
 */
export function humanizeBulkLeaveSkipReason(reason) {
  const r = trimStr(reason);
  if (!r) return '無法批次請假，請改用單堂請假';
  if (/SQLSTATE|Exception|Error|Stack|Integrity constraint|QueryException/i.test(r)) {
    return '系統暫時無法處理此堂，請改用單堂請假';
  }
  // Keep short Chinese product reasons (e.g. 該堂已有核准評量).
  if (/[\u4e00-\u9fff]/.test(r) && r.length <= 80) return r;
  return '系統暫時無法處理此堂，請改用單堂請假';
}

/**
 * User Task: 請假 — 批次請假略過列。
 * Prefer student + subject from already-loaded course list; never lead with 課程 #id.
 *
 * @param {{ course_id?: number|string, session_date?: string, reason?: string }} skip
 * @param {object|null} course course row from CourseManagement list (optional)
 */
export function formatBulkLeaveSkippedLine(skip, course = null) {
  const date = trimStr(skip?.session_date);
  const reason = humanizeBulkLeaveSkipReason(skip?.reason);
  const student = trimStr(course?.student_name || course?.StudentName);
  const subject = trimStr(course?.subject_name || course?.subject || course?.Subject);
  const teacher = trimStr(course?.teacher_name || course?.teacher || course?.TeacherName);

  const whoParts = [];
  if (student) whoParts.push(student);
  if (subject) whoParts.push(subject);
  else if (teacher) whoParts.push(teacher);

  const who = whoParts.length ? whoParts.join(' · ') : '名單上的一門課';
  const when = date ? `${date}：` : '';
  return `${who} ${when}${reason}`.replace(/\s+/g, ' ').trim();
}
