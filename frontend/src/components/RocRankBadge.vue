<!--
  ROC Military Rank Badge — 中華民國軍階徽章
  依照現行陸軍肩章設計（Wikimedia Commons Taiwan-army-OF 系列圖校驗）：
    士兵  ：黃色斜槓（1–3 條）
    士官  ：黃色 V 形 chevron（1–3 條）
    士官長：chevron + 梅花（三等/二等/一等 = 1/2/3 chevron + 梅花）
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

/** 斜槓（士兵用），count = 1|2|3 */
function chevronSlash(count) {
  const positions = [10, 16, 22].slice(0, count);
  return positions.map(x =>
    `<line x1="${x - 2}" y1="22" x2="${x + 2}" y2="10" stroke="${GOLD}" stroke-width="2" stroke-linecap="round"/>`
  ).join('');
}

/** V 形 chevron（士官用），count = 1|2|3 */
function chevronV(count) {
  const offsets = [-5, 0, 5].slice(3 - count);
  return offsets.map(dy =>
    `<polyline points="8,${20 + dy} 16,${26 + dy} 24,${20 + dy}" fill="none" stroke="${GOLD}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>`
  ).join('');
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
    // 士兵
    case 'private_second':       return chevronSlash(1);
    case 'private_first':        return chevronSlash(2);
    case 'private_specialist':   return chevronSlash(3);
    // 士官
    case 'corporal':             return chevronV(1);
    case 'sergeant':             return chevronV(2);
    case 'staff_sergeant':       return chevronV(3);
    // 士官長（chevron + 梅花）
    case 'master_sergeant_third':
      return chevronV(1) + plumFlower(16, 9, 3);
    case 'master_sergeant_second':
      return chevronV(2) + plumFlower(16, 7, 3);
    case 'master_sergeant_first':
      return chevronV(3) + plumFlower(16, 5, 3);
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
