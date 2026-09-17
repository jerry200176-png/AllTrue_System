<script>
export default {
  name: 'CourseManagerBilling',
  props: {
    course: { type: Object, required: true },
    remainingLabel: { type: String, default: '' },
    paymentLabel: { type: String, default: '' },
    purchaseLabel: { type: String, default: '加購堂數' },
    paymentNoticeAvailable: { type: Boolean, default: false },
    canPackagePreview: { type: Boolean, default: false },
    isSessionMode: { type: Boolean, default: false },
  },
  emits: ['action'],
  setup(props, { emit }) {
    const act = (name) => emit('action', { name });
    const billingType = () => (props.course.payment_type === 'session' ? '堂數制' : '月結');
    const unitPrice = () => {
      const n = Number(props.course.rate_per_30min ?? props.course.session_price ?? NaN);
      return Number.isFinite(n) ? `$${n}` : '—';
    };
    return { act, billingType, unitPrice };
  },
};
</script>

<template>
  <div class="cm-billing" data-testid="course-manager-billing">
    <section class="cm-card">
      <h3 class="cm-card__title">帳務摘要</h3>
      <dl class="cm-facts">
        <div><dt>計費</dt><dd>{{ billingType() }}</dd></div>
        <div><dt>單價</dt><dd>{{ unitPrice() }}</dd></div>
        <div><dt>堂次</dt><dd>{{ remainingLabel || '—' }}</dd></div>
        <div><dt>付款</dt><dd>{{ paymentLabel || '—' }}</dd></div>
        <div v-if="course.last_paid_at"><dt>最近付款</dt><dd>{{ course.last_paid_at }}</dd></div>
      </dl>
      <div class="cm-billing__actions">
        <button type="button" class="small ghost" @click="act('invoice')">查看帳單</button>
        <button type="button" class="small ghost" @click="act('tuition')">查看帳務</button>
        <button
          v-if="course.usage_balance_status === 'review_required'"
          type="button"
          class="small primary"
          @click="act('ledger')"
        >堂數待對帳</button>
      </div>
    </section>

    <section class="cm-card">
      <h3 class="cm-card__title">合約操作</h3>
      <div class="cm-billing__actions">
        <button type="button" class="small primary" @click="act('purchase')">{{ purchaseLabel }}</button>
        <button type="button" class="small ghost" @click="act('contract-adjust')">合約／堂次調整</button>
        <button
          v-if="canPackagePreview"
          type="button"
          class="small ghost"
          @click="act('package-preview')"
        >轉多科方案預檢</button>
      </div>
    </section>

    <section class="cm-card">
      <h3 class="cm-card__title">收款</h3>
      <div class="cm-billing__actions">
        <button
          v-if="paymentNoticeAvailable"
          type="button"
          class="small ghost"
          @click="act('payment-slip')"
        >繳費通知</button>
        <button type="button" class="small ghost" @click="act('tuition')">前往帳務中心</button>
      </div>
      <p class="cm-billing__hint">收款與對帳仍走既有帳務流程；此處不直接改寫 Charge／Paid。</p>
    </section>
  </div>
</template>

<style scoped>
.cm-billing { display: grid; gap: 14px; max-width: 760px; }
.cm-card {
  background: #fffdf9;
  border: 1px solid #e7e2da;
  border-radius: 10px;
  padding: 14px 16px;
}
.cm-card__title { margin: 0 0 10px; font-size: 0.95rem; font-weight: 650; }
.cm-facts {
  margin: 0 0 12px;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 10px;
}
.cm-facts dt { font-size: 0.72rem; color: #78716c; }
.cm-facts dd { margin: 2px 0 0; }
.cm-billing__actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cm-billing__hint { margin: 10px 0 0; color: #78716c; font-size: 0.82rem; }
</style>
