<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <section class="modal course-modal" role="dialog" aria-modal="true" aria-labelledby="monthly-correction-title">
      <h3 id="monthly-correction-title">預覽月結分期更正</h3>
      <p>先核對兩期期間、應收金額與付款依據。此預覽不會修改合約或收款。</p>
      <div v-if="!blocked" class="correction-fields" @input="$emit('invalidate')">
        <label>原合約開始日<input v-model="form.source_start" type="date" readonly /></label>
        <label>舊期結束日<input v-model="form.source_end" type="date" /></label>
        <label>新期開始日<input v-model="form.target_start" type="date" /></label>
        <label>新期結束日<input v-model="form.target_end" type="date" /></label>
        <label>舊期應收<input v-model.number="form.source_charge" type="number" min="0" step="1" /></label>
        <label>新期應收<input v-model.number="form.target_charge" type="number" min="0" step="1" /></label>
        <label>下一期合約<select v-model="form.target_course_id" @change="$emit('invalidate')">
          <option :value="null">建立新的未繳費合約</option>
          <option v-for="candidate in candidates" :key="candidate.ID" :value="Number(candidate.ID)">{{ String(candidate.StartDate).slice(0, 10) }} ～ {{ String(candidate.EndDate).slice(0, 10) }} · {{ candidate.teacher_name }}</option>
        </select></label>
        <label>已核對付款的依據識別（必要時）<input v-model="form.payment_evidence_reference" maxlength="128" placeholder="核對紀錄識別，不填姓名或帳號" /></label>
      </div>
      <p v-if="error" role="alert">{{ error }}</p>
      <p v-if="loading" role="status">正在核對堂次與帳款…</p>
      <dl v-if="preview" class="correction-summary" aria-label="更正預覽">
        <dt>保留系統登錄收款</dt><dd>{{ preview.source_paid_amount === null ? '原繳費標記待核對，不代表實際收款' : `NT$ ${preview.source_paid_amount.toLocaleString()}` }}</dd>
        <dt>移轉堂次</dt><dd>{{ preview.session_ids.length }} 堂（保留原紀錄）</dd>
        <dt>新期應收</dt><dd>NT$ {{ preview.target_charge.toLocaleString() }} · 未繳費</dd>
      </dl>
      <p v-if="preview">預覽已備妥。實際更正須由管理者核對修復清單並完成核准。</p>
      <div class="actions"><button type="button" class="ghost" @click="$emit('close')">關閉</button><button type="button" class="primary" :disabled="loading || blocked" @click="$emit('check')">預覽更正</button></div>
    </section>
  </div>
</template>
<script setup>
defineProps({ candidates: { type: Array, default: () => [] }, show: Boolean, blocked: Boolean, form: { type: Object, required: true }, preview: { type: Object, default: null }, loading: Boolean, error: String });
defineEmits(['close', 'check', 'invalidate']);
</script>
<style scoped>
.correction-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.correction-fields label{display:grid;gap:6px;font-size:.85rem}.correction-summary{display:grid;grid-template-columns:1fr 1fr;gap:8px}.correction-summary dd{margin:0;font-weight:600}@media(max-width:560px){.correction-fields{grid-template-columns:1fr}}
</style>
