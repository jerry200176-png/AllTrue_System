<template>
  <div class="fixture-page">
    <header class="fixture-head">
      <div><p class="eyebrow">LOCAL · SYNTHETIC ONLY</p><h2>紙本證據驗證台</h2><p>12 頁自製國小數學樣本；不呼叫 OCR／AI 或寫入後端。</p></div>
      <AtButton variant="ghost" shape="rect" @click="$emit('back')">返回今日課程</AtButton>
    </header>
    <ol class="steps" aria-label="驗證流程"><li v-for="x in stepLabels" :key="x">{{ x }}</li></ol>
    <p class="status" role="status"><strong>{{ state.stage }}</strong> · input r{{ state.inputRevision }} · {{ state.rawAvailable ? '原始檔可用' : '原始檔已到期' }}</p>
    <div class="layout">
      <section class="workbench">
        <div class="actions">
          <AtButton shape="rect" @click="ocr(false)">執行 fixture OCR</AtButton>
          <AtButton variant="ghost" shape="rect" @click="ocr(true)">模擬 OCR 失敗</AtButton>
          <AtButton variant="ghost" shape="rect" @click="manual">人工填入並確認</AtButton>
          <AtButton variant="ghost" shape="rect" :disabled="!state.confirmed" @click="failAi">模擬 AI 失敗</AtButton>
          <AtButton variant="secondary" shape="rect" :disabled="!state.confirmed" @click="makePack">建立並核准講義</AtButton>
          <AtButton variant="ghost" shape="rect" @click="expire">模擬 30 天到期</AtButton>
        </div>
        <table><caption>單一合成學生 · 多頁作答</caption><thead><tr><th>頁</th><th>題目</th><th>OCR／人工答案</th><th>狀態</th><th></th></tr></thead>
          <tbody><tr v-for="p in state.pages" :key="p.pageId"><td>{{ p.pageId }}</td><td>{{ p.question.prompt }}</td><td><input :value="p.verifiedAnswer ?? p.ocrAnswer ?? ''" :aria-label="`${p.pageId}答案`" @change="edit(p, $event)" /></td><td>{{ p.question.answer == null ? '缺答案' : (p.verifiedAnswer == null ? '待確認' : '已確認') }}</td><td><button class="text-action" @click="swap(p.pageId)">替換頁面</button></td></tr></tbody>
        </table>
      </section>
      <aside class="evidence" aria-label="版本與證據">
        <h3>核准版本</h3>
        <p v-if="!preview">尚未核准；老師確認前不產生學習紀錄。</p>
        <template v-else><p><strong>{{ preview.approvedRevision }}</strong> · {{ preview.layout }}</p><ul><li v-for="i in preview.content.items" :key="i.questionId">{{ i.concept }}：{{ i.explanation }}</li></ul>
          <AtButton variant="ghost" shape="rect" @click="reflow">同 revision 重新排版</AtButton></template>
        <h3>執行紀錄</h3><ol class="audit"><li v-for="(line, i) in state.audit" :key="i">{{ line }}</li></ol>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'; import AtButton from '../components/design-system/AtButton.vue';
import { createPaperFixture, runFixtureOcr, verifyPage, replacePage, supplyFixtureAnswerKey, confirmEvidence, draftPack, approvePack, renderApprovedPack, expireRaw } from '../lib/truefitPaperFixture.js';
defineEmits(['back']); const state = reactive(createPaperFixture()); const preview = ref(null);
const stepLabels = ['上傳', 'Fixture OCR', '老師確認', '診斷草稿', '核准列印', '到期驗證'];
function ocr(fail) { runFixtureOcr(state, { fail }); }
function edit(p, e) { verifyPage(state, p.pageId, e.target.value); preview.value = null; }
function swap(id) { replacePage(state, id); preview.value = null; }
function manual() { supplyFixtureAnswerKey(state); state.pages.forEach((p) => { const answer = p.verifiedAnswer ?? p.ocrAnswer ?? (state.rawAvailable && p.question.id === 8105 ? '長 × 寬' : null); if (answer != null) verifyPage(state, p.pageId, answer); }); confirmEvidence(state); }
function failAi() { if (!state.confirmed) return; draftPack(state, { fail: true }); }
function makePack() { if (!state.confirmed) return; draftPack(state, { manual: state.stage === 'AI_UNAVAILABLE' }); approvePack(state); preview.value = renderApprovedPack(state); }
function reflow() { if (!state.approved || !preview.value) return; preview.value = renderApprovedPack(state, preview.value.layout === 'a4-standard' ? 'a4-compact' : 'a4-standard'); state.audit.push(`同一 ${preview.value.approvedRevision} 重新排版`); }
function expire() { expireRaw(state); }
</script>

<style scoped>
.fixture-page{max-width:1120px;margin:auto;color:var(--ds-text-primary)}.fixture-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.fixture-head h2,.fixture-head p{margin:0 0 6px}.eyebrow{font-size:12px;letter-spacing:.08em;color:var(--ds-text-tertiary)}.steps{display:flex;flex-wrap:wrap;gap:8px;list-style:none;padding:12px 0;margin:14px 0;border-block:1px solid var(--ds-hairline)}.steps li{font-size:13px}.steps li+li:before{content:'→';margin-right:8px;color:var(--ds-text-tertiary)}.status{padding:10px 12px;background:var(--ds-canvas-soft);border-left:3px solid var(--ds-primary)}.layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(240px,1fr);gap:16px}.workbench,.evidence{border:1px solid var(--ds-hairline);background:var(--ds-surface-0);padding:16px}.actions{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}table{width:100%;border-collapse:collapse;font-size:13px}caption{text-align:left;font-weight:700;margin-bottom:8px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--ds-hairline);vertical-align:top}input{width:110px;min-height:32px;border:1px solid var(--ds-hairline-input);border-radius:var(--ds-radius-sm);padding:4px 7px}.text-action{border:0;background:none;color:var(--ds-primary-deep);text-decoration:underline;cursor:pointer}.evidence h3{margin:0 0 10px}.evidence h3:not(:first-child){margin-top:20px}.audit{padding-left:20px;font-size:13px}.audit li{margin-bottom:6px}@media(max-width:760px){.fixture-head{display:block}.layout{grid-template-columns:1fr}.workbench{overflow-x:auto}}
</style>
