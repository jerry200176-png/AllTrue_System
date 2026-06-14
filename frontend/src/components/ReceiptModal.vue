<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal receipt-modal">
      <div class="receipt-header">
        <h3>電子收據</h3>
        <button class="ghost icon-btn" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div v-if="loading" class="receipt-loading">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <span>載入中…</span>
      </div>

      <div v-else-if="error" class="receipt-error">
        <p>{{ error }}</p>
        <button class="ghost" @click="$emit('close')">關閉</button>
      </div>

      <template v-else-if="data">
        <div v-if="data && data.is_backfilled" class="receipt-backfill-notice">
          <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;margin-top:1px">info</span>
          <span>此收據由系統依舊繳費記錄補建，原始付款方式與日期可能不精確。</span>
        </div>
        <div class="receipt-preview-wrap">
          <canvas ref="canvasRef" class="receipt-canvas"></canvas>
        </div>
        <div class="receipt-actions">
          <button class="primary" @click="downloadPng">
            <span class="material-symbols-outlined">download</span>
            下載收據
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
  reportId: { type: Number, default: null },
});
defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const data = ref(null);
const canvasRef = ref(null);
const copying = ref(false);

function formatAmount(n) {
  return 'NT$ ' + Number(n || 0).toLocaleString('zh-TW');
}
const BRAND_TITLE = '台北全真一對一補習班';
function formatBrandTitle(campusName) {
  const branch = String(campusName || '').trim();
  return branch ? `${BRAND_TITLE}｜${branch}` : BRAND_TITLE;
}

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

const METHOD_ZH = { transfer: '匯款', cash: '現金', backfill: '現金（補建）' };
const MODE_ZH = { count: '堂數制', date: '月結制' };
const TYPE_ZH = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' };

// ─── Canvas constants ────────────────────────────────────────────
const DPR = 2;
const W = 580;
const PAD = 32;
const INNER = W - PAD * 2;

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

function drawReceipt(canvas, d) {
  const rows = [
    { label: '收據編號', value: d.receipt_no },
    { label: '學生姓名', value: d.student_name },
    { label: '科目', value: d.subject },
    { label: '課程類型', value: TYPE_ZH[d.class_type] || d.class_type || '—' },
    { label: '計費模式', value: MODE_ZH[d.schedule_mode] || d.schedule_mode || '—' },
    d.session_count ? { label: '已購堂數', value: `${d.session_count} 堂` } : null,
    { label: '繳費日期', value: d.payment_date || '—' },
    { label: '繳費方式', value: d.is_backfilled ? '現金（補建）' : (METHOD_ZH[d.payment_method] || d.payment_method) },
    { label: '核帳日期', value: d.confirmed_at || '—' },
    { label: '核帳人員', value: d.confirmed_by || '—' },
  ].filter(Boolean);

  // 上課日期：直式列表，每堂一行。優先用 session_dates（含「預期」標記，#554），
  // 否則退回舊的 attended_dates（全部視為已上課）。
  const dates = Array.isArray(d.session_dates) && d.session_dates.length
    ? d.session_dates.map((e) => ({ date: e?.date ?? e, expected: !!(e && e.expected) }))
    : (Array.isArray(d.attended_dates) ? d.attended_dates.map((date) => ({ date, expected: false })) : []);
  const DATE_ROW_H = 24;
  const datesBlockH = dates.length > 0 ? 36 + dates.length * DATE_ROW_H + 8 : 36;

  const headerH = 120;
  const amountH = 110;
  const rowH = 36;
  const detailH = 20 + rows.length * rowH + datesBlockH + 20;
  const footerH = 80;
  const totalH = headerH + amountH + detailH + footerH;

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

  // Brand bar (green for receipt)
  const g = ctx.createLinearGradient(0, 0, W, 0);
  g.addColorStop(0, '#1B5E20');
  g.addColorStop(1, '#43A047');
  ctx.fillStyle = g;
  roundRectTop(ctx, 0, 0, W, headerH, 16);
  ctx.fill();

  // Checkmark icon
  ctx.fillStyle = '#FFFFFF';
  ctx.font = 'bold 28px "Noto Sans TC", "Inter", sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText('繳費收據', W / 2, 48);

  ctx.font = '500 14px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillStyle = 'rgba(255,255,255,0.85)';
  ctx.fillText(truncateText(ctx, formatBrandTitle(d.campus_name), INNER), W / 2, 76);
  ctx.fillText(`學生：${d.student_name}`, W / 2, 100);

  // Amount block
  let y = headerH + 16;
  ctx.textAlign = 'center';
  ctx.fillStyle = '#546E7A';
  ctx.font = '600 13px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillText('已繳金額', W / 2, y + 10);

  ctx.fillStyle = '#1B5E20';
  ctx.font = 'bold 40px "Inter", "Noto Sans TC", sans-serif';
  ctx.fillText(formatAmount(d.amount), W / 2, y + 58);

  y += amountH;
  drawDivider(ctx, y);
  y += 20;

  // Detail rows
  ctx.textAlign = 'left';
  rows.forEach((row) => {
    ctx.fillStyle = '#90A4AE';
    ctx.font = '500 12px "Noto Sans TC", "Inter", sans-serif';
    ctx.fillText(row.label, PAD + 10, y + 14);

    ctx.fillStyle = '#37474F';
    ctx.font = '500 13px "Noto Sans TC", "Inter", sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(row.value, W - PAD - 10, y + 14);
    ctx.textAlign = 'left';

    y += rowH;
  });

  // 上課日期區塊：直式列表
  ctx.fillStyle = '#90A4AE';
  ctx.font = '500 12px "Noto Sans TC", "Inter", sans-serif';
  ctx.textAlign = 'left';
  ctx.fillText(`上課日期（${dates.length} 堂）`, PAD + 10, y + 14);

  if (dates.length === 0) {
    ctx.fillStyle = '#37474F';
    ctx.font = '500 13px "Noto Sans TC", "Inter", sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText('—', W - PAD - 10, y + 14);
    ctx.textAlign = 'left';
    y += rowH;
  } else {
    y += rowH;
    const DAY_NAMES = ['日', '一', '二', '三', '四', '五', '六'];
    dates.forEach((entry) => {
      const dateStr = entry.date;
      const isExpected = entry.expected;
      // 小圓點：已上課綠色，預期（尚未上課）灰色
      ctx.fillStyle = isExpected ? '#B0BEC5' : '#43A047';
      ctx.beginPath();
      ctx.arc(PAD + 20, y + 8, 3.5, 0, Math.PI * 2);
      ctx.fill();

      // 日期 + 星期（預期堂次加註「(預期)」）
      const dt = new Date(String(dateStr).replace(/\//g, '-') + 'T12:00:00');
      const dayLabel = isNaN(dt.getTime()) ? '' : `（週${DAY_NAMES[dt.getDay()]}）`;
      const suffix = isExpected ? '　(預期)' : '';
      ctx.fillStyle = isExpected ? '#90A4AE' : '#37474F';
      ctx.font = '500 12px "Noto Sans TC", "Inter", sans-serif';
      ctx.textAlign = 'left';
      ctx.fillText(`${dateStr}${dayLabel}${suffix}`, PAD + 32, y + 12);

      y += DATE_ROW_H;
    });
    y += 8;
  }

  y += 10;
  drawDivider(ctx, y);
  y += 20;

  // Footer
  ctx.textAlign = 'center';
  ctx.fillStyle = '#BDBDBD';
  ctx.font = '400 11px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillText('此收據由 AllTrue 系統自動產生，僅供繳費確認用', W / 2, y + 8);

  y += 28;
  ctx.fillStyle = '#CFD8DC';
  ctx.font = '400 10px "Noto Sans TC", "Inter", sans-serif';
  ctx.fillText(`${formatBrandTitle(d.campus_name)} — ${new Date().toLocaleDateString('zh-TW')}`, W / 2, y + 8);
}

watch(() => props.show, async (v) => {
  if (!v || !props.reportId) return;
  loading.value = true;
  error.value = '';
  data.value = null;
  try {
    const resp = await fetch(`/api/v1/payment-reports/${props.reportId}/receipt`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
    });
    if (!resp.ok) {
      const body = await resp.json().catch(() => ({}));
      throw new Error(body.message || '載入失敗');
    }
    data.value = await resp.json();
    loading.value = false;
    await nextTick();
    if (canvasRef.value) drawReceipt(canvasRef.value, data.value);
  } catch (e) {
    error.value = e.message || '載入失敗';
    loading.value = false;
  }
}, { immediate: true });

function downloadPng() {
  if (!canvasRef.value || !data.value) return;
  const link = document.createElement('a');
  link.download = `收據_${data.value.student_name}_${data.value.receipt_no}.png`;
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
.receipt-modal {
  width: 100%;
  max-width: 640px;
  max-height: 92vh;
  overflow-y: auto;
  padding: 20px;
}
.receipt-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.receipt-header h3 { margin: 0; }
.icon-btn {
  border: none;
  background: none;
  cursor: pointer;
  padding: 4px;
  border-radius: 8px;
  color: var(--text-light);
}
.icon-btn:hover { background: var(--bg); }
.receipt-loading, .receipt-error {
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

.receipt-backfill-notice {
  background: var(--ds-warning-wash);
  border: 1px solid var(--ds-warning);
  color: var(--ds-warning);
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px;
  margin: 0 0 12px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
}
.receipt-preview-wrap {
  background: var(--bg);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  justify-content: center;
  overflow-x: auto;
}
.receipt-canvas {
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.08);
  max-width: 100%;
  height: auto;
}
.receipt-actions {
  display: flex;
  gap: 8px;
  justify-content: center;
  margin-top: 16px;
  flex-wrap: wrap;
}
.receipt-actions button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
</style>
