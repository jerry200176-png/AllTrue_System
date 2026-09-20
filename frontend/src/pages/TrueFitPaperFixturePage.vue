<template>
  <div class="fixture-page">
    <header class="fixture-head">
      <div><p class="eyebrow">LOCAL · SYNTHETIC ONLY</p><h2>紙本證據驗證台</h2><p>12 筆 page object；不是實體 OCR 影像測試集。操作驗收預設顯示前 2 頁。</p></div>
      <AtButton variant="ghost" shape="rect" @click="$emit('back')">返回今日課程</AtButton>
    </header>
    <ol class="steps" aria-label="驗證流程"><li v-for="x in stepLabels" :key="x">{{ x }}</li></ol>
    <p class="status" role="status"><strong>{{ state.stage }}</strong> · input r{{ state.inputRevision }} · {{ state.rawAvailable ? '原始檔可用' : '原始檔已到期' }}</p>
    <div class="layout">
      <section class="workbench" aria-label="學生作答確認">
        <div class="actions">
          <AtButton shape="rect" @click="ocr(false)">執行 fixture OCR</AtButton><AtButton variant="ghost" shape="rect" @click="ocr(true)">模擬 OCR 失敗</AtButton>
          <AtButton variant="ghost" shape="rect" @click="manual">確認學生作答</AtButton><AtButton variant="secondary" shape="rect" :disabled="!state.confirmed" @click="createDraft">產生講義草稿</AtButton>
          <AtButton variant="ghost" shape="rect" :disabled="!state.confirmed" @click="failAi">模擬 AI 失敗</AtButton><AtButton variant="ghost" shape="rect" @click="expire">模擬 30 天到期</AtButton>
        </div>
        <div class="table-head"><strong>單一合成學生 · 多頁作答</strong><button class="text-action" @click="showAll = !showAll">{{ showAll ? '只顯示前 2 頁' : '顯示全部 12 頁' }}</button></div>
        <table><thead><tr><th>頁</th><th>題目</th><th>OCR／人工答案</th><th>狀態</th><th></th></tr></thead>
          <tbody><tr v-for="p in visiblePages" :key="p.pageId"><td>{{ p.pageId }}</td><td>{{ p.question.prompt }}</td><td><input :value="p.verifiedAnswer ?? p.ocrAnswer ?? ''" :aria-label="`${p.pageId}答案`" @change="edit(p, $event)" /></td><td>{{ p.question.answer == null ? '缺答案' : (p.verifiedAnswer == null ? '待確認' : '已確認') }}</td><td><button class="text-action" @click="swap(p.pageId)">替換頁面</button></td></tr></tbody>
        </table>
      </section>
      <aside class="evidence" aria-label="版本與證據">
        <h3>草稿審閱</h3><p v-if="!state.draft">確認作答後產生草稿；草稿不等於核准。</p>
        <div v-else class="draft" data-testid="pack-draft">
          <p><strong>來源 input r{{ state.draft.sourceRevision }}</strong> · 草稿修訂 {{ state.draft.contentRevision }}</p>
          <label v-for="item in state.draft.items" :key="item.pageId">{{ item.concept }}解說<textarea :value="item.explanation" :aria-label="`${item.pageId}${item.concept}解說`" @change="editDraft(item, $event)" /></label>
          <div class="draft-actions"><AtButton variant="ghost" shape="rect" @click="sendBack">退回草稿</AtButton><AtButton shape="rect" @click="approve">明確核准</AtButton></div>
        </div>
        <h3>目前核准版本</h3><p v-if="!preview">尚未核准；草稿修改或作答變更會清除舊預覽。</p>
        <template v-else><p><strong>{{ preview.approvedRevision }}</strong> · {{ preview.layout }} · {{ preview.variant === 'student' ? '學生版' : '教師版' }}</p>
          <div class="preview-actions"><AtButton variant="ghost" shape="rect" @click="showPrint('student')">學生版預覽</AtButton><AtButton variant="ghost" shape="rect" @click="showPrint('teacher')">教師版預覽</AtButton><AtButton variant="secondary" shape="rect" @click="reflow">同 revision 重新排版</AtButton><AtButton shape="rect" @click="printCurrent">列印目前版本</AtButton></div>
        </template>
        <h3>執行紀錄</h3><ol class="audit"><li v-for="(line, i) in state.audit" :key="i">{{ line }}</li></ol>
      </aside>
    </div>
    <article v-if="preview" class="print-sheet" :data-print-variant="preview.variant" aria-label="列印預覽">
      <header><p>TrueFit · 合成資料本機驗證</p><h1>{{ preview.content.title }}</h1><p>{{ preview.variant === 'student' ? '學生練習版' : '教師解答版' }} · {{ preview.approvedRevision }}</p></header>
      <section v-for="(item, index) in preview.content.items" :key="item.pageId" class="print-item">
        <h2>{{ index + 1 }}. {{ item.concept }}</h2><p class="prompt">{{ item.practicePrompt }}</p><div class="answer-space" aria-hidden="true"></div>
        <div v-if="preview.variant === 'teacher'" class="teacher-key"><p><strong>答案：</strong>{{ item.answer }}</p><p><strong>解說：</strong>{{ item.explanation }}</p></div>
      </section><footer>{{ preview.approvedRevision }} · 僅供本機合成資料驗證</footer>
    </article>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'; import AtButton from '../components/design-system/AtButton.vue';
import { approvePack, confirmEvidence, createPaperFixture, draftPack, expireRaw, renderApprovedPack, replacePage, returnDraft, runFixtureOcr, supplyFixtureAnswerKey, updateDraftItem, verifyPage } from '../lib/truefitPaperFixture.js';
defineEmits(['back']); const state = reactive(createPaperFixture()); const preview = ref(null); const showAll = ref(false);
const stepLabels = ['作答確認', '產生草稿', '審閱／修改／退回', '明確核准', '學生／教師列印'];
const visiblePages = computed(() => showAll.value ? state.pages : state.pages.slice(0, 2));
function syncPreview() { if (!state.approved) preview.value = null; }
function ocr(fail) { runFixtureOcr(state, { fail }); }
function edit(p, e) { verifyPage(state, p.pageId, e.target.value); syncPreview(); }
function swap(id) { replacePage(state, id); syncPreview(); }
function manual() { supplyFixtureAnswerKey(state); state.pages.forEach((p) => { const answer = p.verifiedAnswer ?? p.ocrAnswer ?? (state.rawAvailable && p.question.id === 8105 ? '長 × 寬' : null); if (answer != null) verifyPage(state, p.pageId, answer); }); confirmEvidence(state); syncPreview(); }
function failAi() { if (state.confirmed) draftPack(state, { fail: true }); }
function createDraft() { if (state.confirmed) { draftPack(state, { manual: state.stage === 'AI_UNAVAILABLE' }); preview.value = null; } }
function editDraft(item, e) { updateDraftItem(state, item.pageId, e.target.value); syncPreview(); }
function sendBack() { returnDraft(state); syncPreview(); }
function approve() { if (approvePack(state)) showPrint('student'); }
function showPrint(variant) { preview.value = renderApprovedPack(state, preview.value?.layout || 'a4-standard', variant); }
function reflow() { if (!state.approved || !preview.value) return; preview.value = renderApprovedPack(state, preview.value.layout === 'a4-standard' ? 'a4-compact' : 'a4-standard', preview.value.variant); state.audit.push(`同一 ${preview.value.approvedRevision} 重新排版`); }
function printCurrent() { if (preview.value) window.print(); }
function expire() { expireRaw(state); syncPreview(); }
</script>

<style scoped>
.fixture-page{max-width:1120px;margin:auto;color:var(--ds-text-primary)}.fixture-head,.table-head,.draft-actions,.preview-actions{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.fixture-head h2,.fixture-head p{margin:0 0 6px}.eyebrow{font-size:12px;letter-spacing:.08em;color:var(--ds-text-tertiary)}.steps{display:flex;flex-wrap:wrap;gap:8px;list-style:none;padding:12px 0;margin:14px 0;border-block:1px solid var(--ds-hairline)}.steps li{font-size:13px}.steps li+li:before{content:'→';margin-right:8px;color:var(--ds-text-tertiary)}.status{padding:10px 12px;background:var(--ds-canvas-soft);border-left:3px solid var(--ds-primary)}.layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px}.workbench,.evidence{border:1px solid var(--ds-hairline);background:var(--ds-surface-0);padding:16px}.actions,.preview-actions{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}.table-head{margin:8px 0}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--ds-hairline);vertical-align:top}input,textarea{border:1px solid var(--ds-hairline-input);border-radius:var(--ds-radius-sm);padding:6px 7px;font:inherit}input{width:110px;min-height:32px}textarea{display:block;width:100%;min-height:72px;margin:5px 0 12px;resize:vertical}.text-action{border:0;background:none;color:var(--ds-primary-deep);text-decoration:underline;cursor:pointer}.evidence h3{margin:0 0 10px}.evidence h3:not(:first-child){margin-top:20px}.draft{padding-left:12px;border-left:3px solid var(--ds-warning)}.audit{padding-left:20px;font-size:13px}.audit li{margin-bottom:6px}.print-sheet{width:210mm;min-height:297mm;margin:24px auto;padding:14mm;background:white;color:var(--ds-ink);box-shadow:var(--ds-shadow-2);font-family:"Noto Sans TC",sans-serif;box-sizing:border-box}.print-sheet header{border-bottom:2px solid var(--ds-ink);margin-bottom:10mm}.print-sheet header p,.print-sheet h1{margin:0 0 3mm}.print-item{break-inside:avoid;border-bottom:1px solid var(--ds-hairline);padding:5mm 0}.print-item h2,.print-item p{margin:0 0 3mm}.answer-space{height:24mm;border-bottom:1px solid var(--ds-hairline-input)}.teacher-key{margin-top:4mm;padding:4mm;background:var(--ds-canvas-soft)}.print-sheet footer{margin-top:8mm;font-size:11px;color:var(--ds-ink-mute)}
@media(max-width:760px){.fixture-head{display:block}.layout{grid-template-columns:1fr}.workbench{overflow-x:auto}.print-sheet{width:100%;min-height:auto;margin:16px 0;padding:16px}}
@page{size:A4;margin:14mm}@media print{:global(body){margin:0;background:white}:global(.truefit-shell__header){display:none!important}.fixture-page>:not(.print-sheet){display:none!important}.print-sheet{display:block;position:static;width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}.print-item{break-inside:avoid}.teacher-key{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
