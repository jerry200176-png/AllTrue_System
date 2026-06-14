<!--
  ROC Military Rank Badge — 中華民國軍階徽章
  依照現行國軍折槓設計（in-app bug 165 修正：原本士官長誤用梅花＝校官章，已更正）：
    士兵  ：細人字折槓（1–3 條）+ 梅花（二等/一等/上等兵）
    士官  ：粗人字折槓（下士=1，中士=2，上士=3）
    士官長：2 粗折槓 + 細折槓（三等=+1 細，二等=+2 細，一等=+3 細）
    尉官  ：金橫槓（少尉=1槓，中尉=2槓，上尉=3槓）
    校官  ：金梅花（少校=1朵，中校=2朵，上校=3朵）
    將官  ：金星（少將=1星，中將=2星，上將=3星，一級上將=4星）
    五星上將：5 星（super_admin 專屬）
-->
<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 32 32"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    class="roc-badge"
    :aria-label="rankKey"
  >
    <!-- 底板 -->
    <rect x="1" y="1" width="30" height="30" rx="4"
      :fill="bgColor" stroke="#b8975a" stroke-width="1.2" />

    <!-- 階別繪製 -->
    <component :is="'g'" v-html="badgeSvgContent" />
  </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  rankKey: { type: String, required: true },
  size: { type: Number, default: 28 },
});

/* ===== 色盤 ===== */
const GOLD   = '#c9952a';
const SILVER = '#aaaaaa';

/* ===== 背景色（依類別） ===== */
const bgColor = computed(() => {
  const k = props.rankKey;
  if (k.startsWith('private'))          return '#3d3d3d'; // 深灰 — 士兵
  if (k.startsWith('corporal') || k === 'sergeant' || k === 'staff_sergeant') return '#2b4a2b'; // 軍綠 — 士官
  if (k.startsWith('master'))           return '#1a3a1a'; // 深軍綠 — 士官長
  if (k.endsWith('_lieutenant') || k === 'captain') return '#1a2a4a'; // 深藍 — 尉官（橫槓）
  if (k === 'major' || k === 'lieutenant_colonel' || k === 'colonel') return '#0d2010'; // 深墨綠 — 校官（梅花）
  if (k.includes('general'))            return '#1a1a2a'; // 近黑藍 — 將官
  return '#2d2d2d';
});

/* ===== SVG 片段產生器 ===== */

const THIN_W = 1.4;  // 細折槓線粗（士兵 / 士官長的細槓）
const THICK_W = 2.8; // 粗折槓線粗（士官 / 士官長的粗槓）

/** 單一人字折槓（∧，頂點朝上），apexY=頂點 Y，sw=線粗 */
function chevron(apexY, sw) {
  const hw = 8;
  const legH = 4.5;
  const xl = 16 - hw;
  const xr = 16 + hw;
  const yb = (apexY + legH).toFixed(1);
  return `<polyline points="${xl},${yb} 16,${apexY.toFixed(1)} ${xr},${yb}" fill="none" stroke="${GOLD}" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round"/>`;
}

/** 垂直堆疊一組人字折槓（widths 由上到下；centerY 整組垂直置中點） */
function chevronStack(widths, centerY = 16) {
  const n = widths.length;
  const legH = 4.5;
  const gap = n >= 5 ? 4.4 : n >= 4 ? 5 : n >= 3 ? 5.6 : 6.4;
  const totalH = (n - 1) * gap + legH;
  const startApex = centerY - totalH / 2;
  return widths.map((w, i) => chevron(startApex + i * gap, w)).join('');
}

/** 梅花花瓣，cx cy r — 一朵五瓣梅花 */
function plumFlower(cx, cy, r = 3.5) {
  const petals = [];
  for (let i = 0; i < 5; i++) {
    const angle = (i * Math.PI * 2) / 5 - Math.PI / 2;
    const px = cx + r * Math.cos(angle);
    const py = cy + r * Math.sin(angle);
    petals.push(`<circle cx="${px.toFixed(2)}" cy="${py.toFixed(2)}" r="${(r * 0.52).toFixed(2)}" fill="${GOLD}"/>`);
  }
  petals.push(`<circle cx="${cx}" cy="${cy}" r="${(r * 0.45).toFixed(2)}" fill="${GOLD}"/>`);
  return petals.join('');
}

/** 尉官橫槓（少尉=1槓，中尉=2槓，上尉=3槓） */
function lieutenantBar(count) {
  // 置中排列，每槓高 3px，間距 2px
  const barH = 3;
  const gap = 2;
  const totalH = count * barH + (count - 1) * gap;
  const startY = 16 - totalH / 2;
  return Array.from({ length: count }, (_, i) => {
    const y = startY + i * (barH + gap);
    return `<rect x="6" y="${y.toFixed(1)}" width="20" height="${barH}" rx="1" fill="${GOLD}"/>`;
  }).join('');
}

/** 金星，cx cy r */
function star(cx, cy, r = 4, color = GOLD) {
  const pts = [];
  for (let i = 0; i < 5; i++) {
    const outer = (Math.PI / 2) + (i * Math.PI * 2) / 5;
    const inner = outer + Math.PI / 5;
    pts.push(`${(cx + r * Math.cos(outer)).toFixed(2)},${(cy - r * Math.sin(outer)).toFixed(2)}`);
    pts.push(`${(cx + r * 0.42 * Math.cos(inner)).toFixed(2)},${(cy - r * 0.42 * Math.sin(inner)).toFixed(2)}`);
  }
  return `<polygon points="${pts.join(' ')}" fill="${color}"/>`;
}

/** 校官梅花群（少校=1朵，中校=2朵，上校=3朵） */
function colonelPlums(count) {
  // 垂直排列，置中
  const r = 3.8;
  const gap = 9;
  const startY = 16 - ((count - 1) * gap) / 2;
  return Array.from({ length: count }, (_, i) =>
    plumFlower(16, startY + i * gap, r)
  ).join('');
}

/** 將官星群，1–5 顆垂直排（仿肩章） */
function generalStars(count) {
  const r = count >= 4 ? 3.5 : 4;
  const gap = count >= 5 ? 5 : count >= 4 ? 6 : 7;
  const startY = 16 - ((count - 1) * gap) / 2;
  return Array.from({ length: count }, (_, i) =>
    star(16, startY + i * gap, r)
  ).join('');
}

/* ===== 主對照表 ===== */
const badgeSvgContent = computed(() => {
  const k = props.rankKey;
  switch (k) {
    // 士兵：細人字折槓 + 梅花（二等=1，一等=2，上等兵=3）
    case 'private_second':       return chevronStack([THIN_W], 20) + plumFlower(16, 8, 3);
    case 'private_first':        return chevronStack([THIN_W, THIN_W], 21) + plumFlower(16, 8, 3);
    case 'private_specialist':   return chevronStack([THIN_W, THIN_W, THIN_W], 22) + plumFlower(16, 7, 2.8);
    // 士官：粗人字折槓（下士=1，中士=2，上士=3）
    case 'corporal':             return chevronStack([THICK_W], 16);
    case 'sergeant':             return chevronStack([THICK_W, THICK_W], 16);
    case 'staff_sergeant':       return chevronStack([THICK_W, THICK_W, THICK_W], 16);
    // 士官長：2 粗折槓 + 細折槓（三等=+1，二等=+2，一等=+3；細槓在上、粗槓在下）
    case 'master_sergeant_third':
      return chevronStack([THIN_W, THICK_W, THICK_W], 16);
    case 'master_sergeant_second':
      return chevronStack([THIN_W, THIN_W, THICK_W, THICK_W], 16);
    case 'master_sergeant_first':
      return chevronStack([THIN_W, THIN_W, THIN_W, THICK_W, THICK_W], 16);
    // 尉官：橫槓（少尉=1槓，中尉=2槓，上尉=3槓）
    case 'second_lieutenant':    return lieutenantBar(1);
    case 'first_lieutenant':     return lieutenantBar(2);
    case 'captain':              return lieutenantBar(3);
    // 校官：梅花（少校=1朵，中校=2朵，上校=3朵）
    case 'major':                return colonelPlums(1);
    case 'lieutenant_colonel':   return colonelPlums(2);
    case 'colonel':              return colonelPlums(3);
    // 將官
    case 'major_general':        return generalStars(1);
    case 'lieutenant_general':   return generalStars(2);
    case 'general':              return generalStars(3);
    case 'general_first_class':  return generalStars(4);
    // 五星上將
    case 'general_five_star':    return generalStars(5);
    default:
      return `<text x="16" y="20" text-anchor="middle" font-size="10" fill="${SILVER}">?</text>`;
  }
});
</script>

<style scoped>
.roc-badge {
  display: block;
  flex-shrink: 0;
}
</style>
