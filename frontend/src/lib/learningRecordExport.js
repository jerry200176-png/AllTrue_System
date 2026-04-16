const DPR = 2;
const CARD_W = 800;
const PADDING = 40;
const CONTENT_W = CARD_W - PADDING * 2;

const COLORS = {
  bg: '#ffffff',
  headerBg: '#1e40af',
  headerText: '#ffffff',
  sectionTitle: '#1e40af',
  sectionBorder: '#dbeafe',
  text: '#1e293b',
  textLight: '#64748b',
  border: '#e2e8f0',
  chipGood: '#16a34a',
  chipGoodBg: '#dcfce7',
  chipAverage: '#d97706',
  chipAverageBg: '#fef3c7',
  chipBad: '#dc2626',
  chipBadBg: '#fee2e2',
  hwCompleted: '#16a34a',
  hwPartial: '#d97706',
  hwIncomplete: '#dc2626',
  hwMissing: '#6b7280',
  approved: '#16a34a',
  approvedBg: '#dcfce7',
  pending: '#d97706',
  pendingBg: '#fef3c7',
  rejected: '#dc2626',
  rejectedBg: '#fee2e2',
};

function wrapText(ctx, text, maxWidth, fontSize) {
  ctx.font = `${fontSize}px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  const lines = [];
  const paragraphs = String(text || '').split('\n');
  for (const para of paragraphs) {
    if (!para.trim()) { lines.push(''); continue; }
    let remaining = para;
    while (remaining.length > 0) {
      let end = remaining.length;
      while (ctx.measureText(remaining.substring(0, end)).width > maxWidth && end > 1) {
        end--;
      }
      lines.push(remaining.substring(0, end));
      remaining = remaining.substring(end);
    }
  }
  return lines;
}

function drawRoundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + w - r, y);
  ctx.arcTo(x + w, y, x + w, y + r, r);
  ctx.lineTo(x + w, y + h - r);
  ctx.arcTo(x + w, y + h, x + w - r, y + h, r);
  ctx.lineTo(x + r, y + h);
  ctx.arcTo(x, y + h, x, y + h - r, r);
  ctx.lineTo(x, y + r);
  ctx.arcTo(x, y, x + r, y, r);
  ctx.closePath();
}

function drawChip(ctx, x, y, label, bgColor, textColor, fontSize = 12) {
  ctx.font = `bold ${fontSize}px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  const tw = ctx.measureText(label).width;
  const pw = 12;
  const chipW = tw + pw * 2;
  const chipH = fontSize + 10;
  drawRoundRect(ctx, x, y, chipW, chipH, chipH / 2);
  ctx.fillStyle = bgColor;
  ctx.fill();
  ctx.fillStyle = textColor;
  ctx.fillText(label, x + pw, y + fontSize + 3);
  return chipW;
}

const HW_LABELS = {
  completed: '已完成',
  partial: '部分完成',
  incomplete: '未完成',
  missing: '未攜帶',
};

const HW_COLORS = {
  completed: 'hwCompleted',
  partial: 'hwPartial',
  incomplete: 'hwIncomplete',
  missing: 'hwMissing',
};

const PERF_LABELS = { good: '良好', average: '普通', bad: '不良' };
const PERF_COLORS = {
  good: { bg: 'chipGoodBg', text: 'chipGood' },
  average: { bg: 'chipAverageBg', text: 'chipAverage' },
  bad: { bg: 'chipBadBg', text: 'chipBad' },
};

const STATUS_LABELS = {
  approved: '已核准', pending: '待審核',
  rejected: '已退回', changes_requested: '需修改',
};

function statusColors(status) {
  if (status === 'approved') return { bg: COLORS.approvedBg, text: COLORS.approved };
  if (status === 'rejected' || status === 'changes_requested') return { bg: COLORS.rejectedBg, text: COLORS.rejected };
  return { bg: COLORS.pendingBg, text: COLORS.pending };
}

function renderStudentCard(ctx, studentName, records, teacherName, dateRange, branchName) {
  let y = 0;

  // --- Header ---
  const headerH = 100;
  drawRoundRect(ctx, 0, 0, CARD_W, headerH, 0);
  ctx.fillStyle = COLORS.headerBg;
  ctx.fill();

  ctx.fillStyle = COLORS.headerText;
  ctx.font = `bold 28px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  ctx.fillText('學習評量報告', PADDING, 42);

  ctx.font = `16px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  const subtitle = `${branchName || '台北全真一對一補習班'} · ${dateRange}`;
  ctx.fillText(subtitle, PADDING, 70);

  // Student name + teacher on right
  ctx.textAlign = 'right';
  ctx.font = `bold 22px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  ctx.fillText(studentName, CARD_W - PADDING, 42);
  ctx.font = `14px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  ctx.fillText(`授課老師：${teacherName}`, CARD_W - PADDING, 70);
  ctx.textAlign = 'left';

  y = headerH + 20;

  // --- Records ---
  for (let i = 0; i < records.length; i++) {
    const rec = records[i];
    y = drawRecordSection(ctx, y, rec, i + 1);
  }

  // --- Footer ---
  y += 10;
  ctx.fillStyle = COLORS.border;
  ctx.fillRect(PADDING, y, CONTENT_W, 1);
  y += 16;
  ctx.fillStyle = COLORS.textLight;
  ctx.font = `12px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  const now = new Date();
  const ts = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  ctx.fillText(`匯出時間：${ts}    此報告由 台北全真一對一補習班管理系統自動產生`, PADDING, y);
  y += 30;

  return y;
}

function drawRecordSection(ctx, startY, record, index) {
  let y = startY;

  // Section title bar
  ctx.fillStyle = COLORS.sectionBorder;
  ctx.fillRect(PADDING, y, CONTENT_W, 36);
  ctx.fillStyle = COLORS.sectionTitle;
  ctx.font = `bold 14px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
  const dateStr = String(record.SessionDate || '').slice(0, 10);
  const timeStr = record.StartTime ? `${record.StartTime}~${record.EndTime || ''}` : '';
  const subjectStr = record.Subject || '';
  const sessionTag = record.session_number ? `第${record.session_number}堂` : `#${index}`;
  const sectionLabel = `${sessionTag}  ${dateStr}  ${timeStr}  ${subjectStr}`;
  ctx.fillText(sectionLabel, PADDING + 12, y + 24);

  // Status chip on the right
  const statusLabel = STATUS_LABELS[record.Status] || record.Status || '';
  if (statusLabel) {
    const sc = statusColors(record.Status);
    ctx.textAlign = 'right';
    const chipX = CARD_W - PADDING - 80;
    drawChip(ctx, chipX, y + 6, statusLabel, sc.bg, sc.text, 11);
    ctx.textAlign = 'left';
  }

  y += 48;

  // Row helper
  const drawLabelValue = (label, value, opts = {}) => {
    if (!value && !opts.alwaysShow) return y;
    ctx.fillStyle = COLORS.textLight;
    ctx.font = `bold 13px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
    ctx.fillText(label, PADDING + 8, y);

    if (opts.chip) {
      drawChip(ctx, PADDING + 100, y - 14, opts.chipLabel, opts.chipBg, opts.chipColor, 12);
      y += 26;
      return y;
    }

    const lines = wrapText(ctx, value, CONTENT_W - 110, 14);
    ctx.fillStyle = COLORS.text;
    ctx.font = `14px "Noto Sans TC", "Microsoft JhengHei", sans-serif`;
    for (const line of lines) {
      ctx.fillText(line, PADDING + 100, y);
      y += 20;
    }
    if (lines.length === 0) y += 20;
    y += 4;
    return y;
  };

  // Homework status
  const hwKey = record.HomeworkStatus;
  if (hwKey && HW_LABELS[hwKey]) {
    const colorKey = HW_COLORS[hwKey];
    y = drawLabelValue('上次作業', '', {
      chip: true,
      chipLabel: HW_LABELS[hwKey],
      chipBg: hwKey === 'completed' ? COLORS.chipGoodBg : hwKey === 'partial' ? COLORS.chipAverageBg : COLORS.chipBadBg,
      chipColor: COLORS[colorKey],
    });
  }

  // Quiz score
  y = drawLabelValue('週考成績', record.QuizScore);

  // Progress
  y = drawLabelValue('授課進度', record.Progress);

  // Next homework
  y = drawLabelValue('下次作業', record.NextHomework);

  // Next week test scope
  y = drawLabelValue('下次週考範圍', record.NextWeekTestScope);

  // Performance
  const perfKey = record.Performance;
  if (perfKey && PERF_LABELS[perfKey]) {
    const pc = PERF_COLORS[perfKey];
    y = drawLabelValue('上課狀況', '', {
      chip: true,
      chipLabel: PERF_LABELS[perfKey],
      chipBg: COLORS[pc.bg],
      chipColor: COLORS[pc.text],
    });
  }

  // Comment
  y = drawLabelValue('老師評語', record.Comment);

  // Separator
  ctx.fillStyle = COLORS.border;
  ctx.fillRect(PADDING + 8, y, CONTENT_W - 16, 1);
  y += 16;

  return y;
}

function measureCardHeight(studentName, records, teacherName, dateRange, branchName) {
  const offscreen = document.createElement('canvas');
  offscreen.width = CARD_W * DPR;
  offscreen.height = 10000 * DPR;
  const ctx = offscreen.getContext('2d');
  ctx.scale(DPR, DPR);
  return renderStudentCard(ctx, studentName, records, teacherName, dateRange, branchName);
}

export function generateStudentCardPng(studentName, records, teacherName, dateRange, branchName) {
  const height = measureCardHeight(studentName, records, teacherName, dateRange, branchName);
  const canvas = document.createElement('canvas');
  canvas.width = CARD_W * DPR;
  canvas.height = height * DPR;
  const ctx = canvas.getContext('2d');
  ctx.scale(DPR, DPR);

  // White background
  ctx.fillStyle = COLORS.bg;
  ctx.fillRect(0, 0, CARD_W, height);

  renderStudentCard(ctx, studentName, records, teacherName, dateRange, branchName);

  return new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob), 'image/png');
  });
}

export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 5000);
}

export async function exportStudentCards({
  groupedRecords,
  dateRange,
  branchName,
  onProgress,
}) {
  const total = groupedRecords.length;
  let completed = 0;
  const errors = [];

  for (const group of groupedRecords) {
    try {
      const teacherNames = [...new Set(
        group.records.map((r) => r.teacher_name).filter(Boolean)
      )];
      const teacherLabel = teacherNames.join('、') || '未指派';

      const blob = await generateStudentCardPng(
        group.student_name,
        group.records,
        teacherLabel,
        dateRange,
        branchName,
      );

      const safeName = group.student_name.replace(/[\\/:*?"<>|]/g, '_');
      downloadBlob(blob, `評量報告_${safeName}_${dateRange.replace(/\s/g, '')}.png`);

      completed++;
      if (onProgress) onProgress({ completed, total, current: group.student_name });

      if (completed < total) {
        await new Promise((r) => setTimeout(r, 400));
      }
    } catch (err) {
      console.error(`Export failed for ${group.student_name}:`, err);
      errors.push(group.student_name);
      completed++;
      if (onProgress) onProgress({ completed, total, current: group.student_name, error: true });
    }
  }

  return { completed: completed - errors.length, errors };
}
