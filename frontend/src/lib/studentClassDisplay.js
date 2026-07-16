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

/** True if primary label depends on understanding SC — forbidden for director decision UI. */
export function primaryLeaksInternalId(primary) {
  return /\bSC\s*#?\d+/i.test(String(primary || ''));
}
