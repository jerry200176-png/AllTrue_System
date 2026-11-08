<script setup>
import { ref } from 'vue';
import '@/styles.css';
import '@/ui.css';
import FormDialog from '@/components/app-overlay/FormDialog.vue';
import DetailSheet from '@/components/app-overlay/DetailSheet.vue';
import ConfirmDialogHost from '@/components/course-management/ConfirmDialogHost.vue';
import { askConfirm } from '@/composables/useConfirmDialog';

const open = ref(false), sheet = ref(false), legacy = ref(false), result = ref(''), submitted = ref(0);
const params = new URLSearchParams(location.search);
const dirty = params.has('dirty'), loading = params.has('loading'), size = params.get('size') || 'md';
const confirm = async () => { result.value = String(await askConfirm({ title: '測試確認？', message: '僅使用合成資料。', confirmLabel: '繼續' })); };
</script>
<template>
    <main><h1>合成測試</h1>
      <button @click="open = true">開啟表單</button>
      <button @click="sheet = true">開啟詳情</button>
      <button @click="legacy = true">開啟舊視窗</button>
      <output data-testid="result">{{ result }}</output><output data-testid="submitted">{{ submitted }}</output>
      <FormDialog v-model:open="open" title="合成表單" description="測試長表單捲動。" :dirty="dirty" :loading="loading" :size="size" @submit="submitted++">
        <label v-for="n in 30" :key="n" :for="'field-' + n" style="display:block; margin-bottom:16px">
          測試欄位 {{ n }}<input :id="'field-' + n" style="display:block; width:100%; box-sizing:border-box" />
        </label>
      </FormDialog>
      <DetailSheet v-model:open="sheet" title="合成詳情"><button @click="confirm">詳情內確認</button></DetailSheet>
      <div v-if="legacy" class="modal-overlay"><div class="modal">
        <button @click="confirm">舊視窗內確認</button>
      </div></div>
      <ConfirmDialogHost />
    </main>
</template>
