<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal slip-modal">
      <div class="slip-header">
        <h3>繳費通知單預覽</h3>
        <button class="ghost icon-btn" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div v-if="loading" class="slip-loading">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <span>載入中…</span>
      </div>

      <div v-else-if="error" class="slip-error">
        <p>{{ error }}</p>
        <button class="ghost" @click="$emit('close')">關閉</button>
      </div>

      <template v-else-if="slip">
        <div class="slip-preview-wrap">
          <canvas ref="canvasRef" class="slip-canvas"></canvas>
        </div>
        <div class="slip-actions">
          <button class="primary" @click="downloadPng">
            <span class="material-symbols-outlined">download</span>
            下載圖片
          </button>
          <button class="ghost" @click="copyToClipboard" :disabled="copying">
            <span class="material-symbols-outlined">content_copy</span>
            {{ copying ? '已複製' : '複製到剪貼簿' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
  show: Boolean,
  invoiceId: { type: Number, default: null },
  studentClassId: { type: Number, default: null },
});
defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const slip = ref(null);
const canvasRef = ref(null);
const copying = ref(false);

function formatAmount(n) {
  return 'NT$ ' + Number(n || 0).toLocaleString('zh-TW');
}
function formatDate(d) {
  if (!d) return '—';
  return d.replace(/-/g, '/');
}

const STATUS_ZH = {
  attended: '已到課', completed: '已完課', late: '遲到', absent: '缺席',
  excused: '事假', scheduled: '排定', leave: '請假', leave_adjusted: '調課',
};
const STATUS_COLOR = {
  attended: '#2E7D32', completed: '#2E7D32', late: '#EF6C00', absent: '#C62828',
  excused: '#5C6BC0', scheduled: '#607D8B', leave: '#8D6E63', leave_adjusted: '#8D6E63',
};
const STATUS_BG = {
  attended: '#E8F5E9', completed: '#E8F5E9', late: '#FFF3E0', absent: '#FFEBEE',
  excused: '#E8EAF6', scheduled: '#ECEFF1', leave: '#EFEBE9', leave_adjusted: '#EFEBE9',
};

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

async function fetchInvoiceSlip(id) {
  const resp = await fetch(`/api/v1/invoices/${id}/slip-data`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (!resp.ok) throw new Error('無法載入帳單資料');
  return resp.json();
}

async function fetchTuitionSlip(scId) {
  const resp = await fetch(`/api/v1/alerts/tuition-slip/${scId}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (!resp.ok) {
    const body = await resp.json().catch(() => ({}));
    throw new Error(body.message || '無法載入催繳資料');
  }
  return resp.json();
}

function normalizeSlipData(raw) {
  if (raw.invoice_id) {
    return {
      type: 'invoice',
      student_name: raw.student_name,
      campus_name: raw.campus_name,
      title: '繳費通知單',
      amount_label: raw.status === 'partial' ? '尚欠金額' : '應繳金額',
      amount: raw.remaining,
      sub_amounts: raw.status === 'partial'
        ? `應繳總額 ${formatAmount(raw.total_amount)}　已繳 ${formatAmount(raw.paid_amount)}`
        : null,
      ref_label: `帳單編號 #${raw.invoice_id}`,
      date_label: raw.due_date
        ? `繳費期限　${formatDate(raw.due_date)}`
        : `開立日期　${formatDate(raw.issue_date)}`,
      items: (raw.items || []).map(i => ({
        description: i.description || '—',
        period: i.period_start && i.period_end
          ? `${formatDate(i.period_start)} ~ ${formatDate(i.period_end)}`
          : '—',
        amount: formatAmount(i.amount),
      })),
      note: raw.note,
      sessions: raw.sessions || [],
      filename: `繳費單_${raw.student_name}_${raw.invoice_id}.png`,
    };
  }
  const modeLabel = raw.schedule_mode === 'date' ? '月結制' : '堂數制';
  const items = [];
  items.push({
    description: `${raw.subject}（${modeLabel}）`,
    period: raw.remaining_sessions != null ? `剩餘 ${raw.remaining_sessions} 堂` : '—',
    amount: raw.charge ? formatAmount(raw.charge) : '—',
  });

  let dateLabel = '';
  if (raw.due_date) {
    const days = raw.days_until_settlement;
    if (days != null && days < 0) {
      dateLabel = `繳費期限　${formatDate(raw.due_date)}（已逾期 ${Math.abs(days)} 天）`;
    } else {
      dateLabel = `繳費期限　${formatDate(raw.due_date)}`;
    }
  }

  return {
    type: 'tuition',
    student_name: raw.student_name,
    campus_name: raw.campus_name,
    title: '繳費通知',
    amount_label: '應繳費用',
    amount: raw.charge || 0,
    sub_amounts: null,
    ref_label: `${raw.subject}`,
    date_label: dateLabel || `產生日期　${new Date().toLocaleDateString('zh-TW')}`,
    items,
    note: raw.note,
    sessions: raw.sessions || [],
    filename: `繳費通知_${raw.student_name}_${raw.student_class_id}.png`,
  };
}

// ─── Canvas constants ────────────────────────────────────────────
const DPR = 2;
const W = 580;
const PAD = 32;
const INNER = W - PAD * 2;

// ─── Drawing helpers ─────────────────────────────────────────────
function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + w - r, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + r);
  ctx.lineTo(x + w, y + h - r);
  ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
  ctx.lineTo(x + r, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - r);
  ctx.lineTo(x, y + r);
  ctx.quadraticCurveTo(x, y, x + r, y);
  ctx.closePath();
}
function roundRectTop(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + w - r, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + r);
  ctx.lineTo(x + w, y + h);
  ctx.lineTo(x, y + h);
  ctx.lineTo(x, y + r);
  ctx.quadraticCurveTo(x, y, x + r, y);
  ctx.closePath();
}
function drawDivider(ctx, y) {
  ctx.strokeStyle = '#E0E0E0';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(PAD, y);
  ctx.lineTo(W - PAD, y);
  ctx.stroke();
}
function truncateText(ctx, text, maxW) {
  if (!text) return '—';
  if (ctx.measureText(text).width <= maxW) return text;
  let t = text;
  while (t.length > 0 && ctx.measureText(t + '…').width > maxW) t = t.slice(0, -1);
  return t + '…';
}

// ─── Main draw ───────────────────────────────────────────────────
function drawSlip(canvas, data) {
  const sessions = data.sessions || [];
  const todayStr = new Date().toISOString().slice(0, 10);
  const attendedCount = sessions.filter(s =>
    ['attended', 'completed', 'late', 'absent', 'excused'].includes(s.status)
  ).length;
  const hasSubject = sessions.some(s => s.subject);

  // Sizing
  const headerH = 130;
  const amountBlockH = data.sub_amounts ? 150 : 130;
  const itemRowH = 44;
  const itemHeaderH = 40;
  const itemCount = data.items.length;
  const itemsBlockH = itemCount > 0 ? itemHeaderH + itemCount * itemRowH + 20 : 0;

  const sessRowH = 30;
  const sessHeaderH = 38;
  const sessColHeaderH = 28;
  const sessPadTop = 20;
  const sessPadBot = 16;
  const sessBlockH = sessions.length > 0
    ? sessPadTop + sessHeaderH + sessColHeaderH + sessions.length * sessRowH + sessPadBot
    : 0;

  const footerH = data.note ? 100 : 76;
  const totalH = headerH + amountBlockH + itemsBlockH + sessBlockH + footerH + 40;

  const ctx = canvas.getContext('2d');
  canvas.width = W * DPR;
  canvas.height = totalH * DPR;
  canvas.style.width = W + 'px';
  canvas.style.height = totalH + 'px';
  ctx.scale(DPR, DPR);

  // White card
  ctx.fillStyle = '#FFFFFF';
  roundRect(ctx, 0, 0, W, totalH, 16);
  ctx.fill();

  // ── Brand bar ──
  const g = ctx.createLinearGradient(0, 0, W, 0);
  if (data.type === 'tuition') {
    g.addColorStop(0, '#AD1457');
    g.addColorStop(1, '#E91E63');
  } else {
    g.addColorStop(0, '#BF360C');
    g.addColorStop(1, '#F4511E');
  }
  ctx.fillStyle = g;
  roundRectTop(ctx, 0, 0, W, headerH, 16);
  ctx.fill();

  ctx.fillStyle = '#FFFFFF';
  ctx.font = 'bold 28px "Noto Sans TC", "Inter", sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText(data.title, W / 2, 50);

  ctx.font = '500 14px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillStyle = 'rgba(255,255,255,0.85)';
  if (data.campus_name) {
    ctx.fillText(data.campus_name, W / 2, 80);
    ctx.fillText(`學生：${data.student_name}`, W / 2, 104);
  } else {
    ctx.fillText(`學生：${data.student_name}`, W / 2, 90);
  }

  // ── Amount block ──
  let y = headerH + 22;
  ctx.textAlign = 'center';
  ctx.fillStyle = '#546E7A';
  ctx.font = '600 13px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillText(data.amount_label, W / 2, y + 10);

  ctx.fillStyle = data.type === 'tuition' ? '#AD1457' : '#BF360C';
  ctx.font = 'bold 40px "Inter", "Noto Sans TC", sans-serif';
  ctx.fillText(formatAmount(data.amount), W / 2, y + 60);

  if (data.sub_amounts) {
    ctx.font = '400 13px "Noto Sans TC", "Inter", sans-serif';
    ctx.fillStyle = '#90A4AE';
    ctx.fillText(data.sub_amounts, W / 2, y + 86);
  }
  y += amountBlockH - 10;

  // ── Reference pill ──
  ctx.fillStyle = '#F5F5F5';
  roundRect(ctx, PAD, y - 16, INNER, 34, 8);
  ctx.fill();
  ctx.font = '500 11.5px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillStyle = '#78909C';
  ctx.textAlign = 'left';
  ctx.fillText(data.ref_label, PAD + 14, y + 4);
  if (data.date_label) {
    ctx.textAlign = 'right';
    ctx.fillText(data.date_label, W - PAD - 14, y + 4);
  }
  y += 30;

  // ── Items table ──
  if (data.items.length > 0) {
    y += 10;
    ctx.textAlign = 'left';
    ctx.fillStyle = '#ECEFF1';
    roundRect(ctx, PAD, y, INNER, itemHeaderH, 8);
    ctx.fill();

    ctx.fillStyle = '#607D8B';
    ctx.font = '600 11.5px "Noto Sans TC", "Inter", sans-serif';
    ctx.fillText('項目說明', PAD + 14, y + 25);
    ctx.fillText('期間／堂數', 280, y + 25);
    ctx.textAlign = 'right';
    ctx.fillText('金額', W - PAD - 14, y + 25);
    y += itemHeaderH;

    data.items.forEach((item, idx) => {
      if (idx % 2 === 1) {
        ctx.fillStyle = '#FAFAFA';
        ctx.fillRect(PAD, y, INNER, itemRowH);
      }
      ctx.textAlign = 'left';
      ctx.fillStyle = '#37474F';
      ctx.font = '500 13px "Noto Sans TC", "Inter", sans-serif';
      ctx.fillText(truncateText(ctx, item.description, 220), PAD + 14, y + 28);

      ctx.fillStyle = '#78909C';
      ctx.font = '400 11.5px "Inter", sans-serif';
      ctx.fillText(item.period, 280, y + 28);

      ctx.textAlign = 'right';
      ctx.fillStyle = '#37474F';
      ctx.font = '600 13px "Inter", "Noto Sans TC", sans-serif';
      ctx.fillText(item.amount, W - PAD - 14, y + 28);
      y += itemRowH;
    });
    y += 6;
    drawDivider(ctx, y);
    y += 10;
  }

  // ── Sessions (course schedule) ──
  if (sessions.length > 0) {
    y += sessPadTop;

    // Section title with accent bar
    const accent = data.type === 'tuition' ? '#E91E63' : '#F4511E';
    ctx.fillStyle = accent;
    roundRect(ctx, PAD, y, 4, 28, 2);
    ctx.fill();

    ctx.textAlign = 'left';
    ctx.fillStyle = '#263238';
    ctx.font = 'bold 14px "Noto Sans TC", "Inter", sans-serif';
    ctx.fillText('課程明細', PAD + 16, y + 14);

    // Summary badges
    const badgeY = y + 3;
    ctx.font = '500 11px "Noto Sans TC", "Inter", sans-serif';
    let bx = PAD + 90;

    // "共 N 堂" badge
    ctx.fillStyle = '#ECEFF1';
    const totalLabel = `共 ${sessions.length} 堂`;
    const totalBW = ctx.measureText(totalLabel).width + 16;
    roundRect(ctx, bx, badgeY, totalBW, 22, 4);
    ctx.fill();
    ctx.fillStyle = '#546E7A';
    ctx.fillText(totalLabel, bx + 8, badgeY + 15);
    bx += totalBW + 6;

    // "已上 N 堂" badge
    if (attendedCount > 0) {
      ctx.fillStyle = '#E8F5E9';
      const attLabel = `已上 ${attendedCount} 堂`;
      const attBW = ctx.measureText(attLabel).width + 16;
      roundRect(ctx, bx, badgeY, attBW, 22, 4);
      ctx.fill();
      ctx.fillStyle = '#2E7D32';
      ctx.fillText(attLabel, bx + 8, badgeY + 15);
    }

    y += sessHeaderH;

    // Column header
    ctx.fillStyle = '#F5F5F5';
    roundRect(ctx, PAD, y, INNER, sessColHeaderH, 6);
    ctx.fill();
    ctx.fillStyle = '#90A4AE';
    ctx.font = '600 10.5px "Noto Sans TC", "Inter", sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('#', PAD + 10, y + 18);
    ctx.fillText('日期', PAD + 36, y + 18);
    ctx.fillText('時間', PAD + 170, y + 18);
    if (hasSubject) ctx.fillText('科目', PAD + 310, y + 18);
    ctx.textAlign = 'right';
    ctx.fillText('狀態', W - PAD - 10, y + 18);
    y += sessColHeaderH;

    // Session rows
    sessions.forEach((s, idx) => {
      const isPast = s.date <= todayStr;
      const isAttended = ['attended', 'completed', 'late', 'absent', 'excused'].includes(s.status);

      // Zebra stripe
      if (idx % 2 === 0) {
        ctx.fillStyle = '#FAFAFA';
        ctx.fillRect(PAD, y, INNER, sessRowH);
      }

      // Row number
      ctx.textAlign = 'left';
      ctx.fillStyle = '#B0BEC5';
      ctx.font = '400 10px "Inter", sans-serif';
      ctx.fillText(String(idx + 1), PAD + 10, y + 19);

      // Date
      ctx.fillStyle = isPast ? '#37474F' : '#78909C';
      ctx.font = `${isPast ? '500' : '400'} 12px "Noto Sans TC", "Inter", sans-serif`;
      ctx.fillText(formatDate(s.date), PAD + 36, y + 19);

      // Time
      ctx.fillStyle = '#546E7A';
      ctx.font = '400 11.5px "Inter", "Noto Sans TC", sans-serif';
      const timeStr = s.start_time && s.end_time
        ? `${s.start_time} – ${s.end_time}`
        : s.start_time || '—';
      ctx.fillText(timeStr, PAD + 170, y + 19);

      // Subject
      if (hasSubject && s.subject) {
        ctx.fillStyle = '#78909C';
        ctx.font = '400 11px "Noto Sans TC", "Inter", sans-serif';
        ctx.fillText(truncateText(ctx, s.subject, 80), PAD + 310, y + 19);
      }

      // Status pill
      const status = s.status || 'scheduled';
      const zh = STATUS_ZH[status] || status;
      const pillColor = STATUS_COLOR[status] || '#607D8B';
      const pillBg = STATUS_BG[status] || '#ECEFF1';

      ctx.font = '600 10px "Noto Sans TC", "Inter", sans-serif';
      const pillTextW = ctx.measureText(zh).width;
      const pillW = pillTextW + 14;
      const pillX = W - PAD - 10 - pillW;
      const pillY = y + 6;

      ctx.fillStyle = pillBg;
      roundRect(ctx, pillX, pillY, pillW, 18, 4);
      ctx.fill();

      // Dot
      ctx.fillStyle = pillColor;
      ctx.beginPath();
      ctx.arc(pillX + 8, pillY + 9, 3, 0, Math.PI * 2);
      ctx.fill();

      // Status text
      ctx.fillStyle = pillColor;
      ctx.textAlign = 'left';
      ctx.fillText(zh, pillX + 14, pillY + 13);

      y += sessRowH;
    });

    y += sessPadBot;
    drawDivider(ctx, y);
    y += 8;
  }

  // ── Footer ──
  y += 14;
  ctx.textAlign = 'center';
  ctx.fillStyle = '#BDBDBD';
  ctx.font = '400 11px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillText('此通知單僅供繳費確認用', W / 2, y + 8);

  if (data.note) {
    y += 20;
    ctx.fillStyle = '#90A4AE';
    ctx.font = '400 12px "Noto Sans TC", "Inter", sans-serif';
    ctx.fillText(`備註：${data.note}`, W / 2, y + 8);
  }

  y += 28;
  ctx.fillStyle = '#CFD8DC';
  ctx.font = '400 10px "Inter", sans-serif';
  ctx.fillText(`AllTrue ${data.campus_name || ''} — ${new Date().toLocaleDateString('zh-TW')}`, W / 2, y + 8);
}

// ─── Lifecycle ───────────────────────────────────────────────────

watch(() => props.show, async (v) => {
  if (!v) return;
  if (!props.invoiceId && !props.studentClassId) return;
  loading.value = true;
  error.value = '';
  slip.value = null;
  try {
    let raw;
    if (props.invoiceId) {
      raw = await fetchInvoiceSlip(props.invoiceId);
    } else {
      raw = await fetchTuitionSlip(props.studentClassId);
    }
    slip.value = normalizeSlipData(raw);
    loading.value = false;
    await nextTick();
    if (canvasRef.value) drawSlip(canvasRef.value, slip.value);
  } catch (e) {
    error.value = e.message || '載入失敗';
    loading.value = false;
  }
}, { immediate: true });

function downloadPng() {
  if (!canvasRef.value || !slip.value) return;
  const link = document.createElement('a');
  link.download = slip.value.filename;
  link.href = canvasRef.value.toDataURL('image/png');
  link.click();
}

async function copyToClipboard() {
  if (!canvasRef.value) return;
  try {
    const blob = await new Promise(resolve => canvasRef.value.toBlob(resolve, 'image/png'));
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
    copying.value = true;
    setTimeout(() => { copying.value = false; }, 2000);
  } catch {
    alert('複製失敗，請使用下載功能');
  }
}
</script>

<style scoped>
.slip-modal {
  width: 100%;
  max-width: 640px;
  max-height: 92vh;
  overflow-y: auto;
  padding: 20px;
}
.slip-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.slip-header h3 { margin: 0; }
.icon-btn {
  border: none;
  background: none;
  cursor: pointer;
  padding: 4px;
  border-radius: 8px;
  color: var(--text-light);
}
.icon-btn:hover { background: var(--bg); }
.slip-loading, .slip-error {
  text-align: center;
  padding: 48px 0;
  color: var(--text-light);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.spin {
  animation: rotate 1s linear infinite;
  font-size: 32px;
}
@keyframes rotate { to { transform: rotate(360deg); } }

.slip-preview-wrap {
  background: var(--bg);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  justify-content: center;
  overflow-x: auto;
}
.slip-canvas {
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.08);
  max-width: 100%;
  height: auto;
}
.slip-actions {
  display: flex;
  gap: 8px;
  justify-content: center;
  margin-top: 16px;
  flex-wrap: wrap;
}
.slip-actions button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
</style>
