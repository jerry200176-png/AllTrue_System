<script setup>
// #1139: parent asks for a one-time Telegram bind code; the bot accepts `/start <code>` only.
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { createParentTelegramBindCode } from '../api';

const props = defineProps({ token: { type: String, required: true } });
const code = ref('');
const error = ref('');
const busy = ref(false);
const copied = ref(false);

async function request() {
  busy.value = true;
  error.value = '';
  copied.value = false;
  try {
    code.value = (await createParentTelegramBindCode(props.token)).code;
  } catch (e) {
    error.value = e?.message || '無法取得綁定碼';
  } finally {
    busy.value = false;
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(code.value);
    copied.value = true;
  } catch {
    // clipboard unavailable: the code stays selectable on screen
  }
}
</script>

<template>
  <div class="tg-bind">
    <Button type="button" class="tg-bind__btn" :disabled="busy" @click="request">綁定 Telegram 通知</Button>
    <p v-if="error" class="tg-bind__error" role="alert">{{ error }}</p>
    <div v-if="code" class="tg-bind__result">
      <button type="button" class="tg-bind__code" data-testid="tg-code" aria-label="複製綁定碼" @click="copy">{{ code }}</button>
      <span v-if="copied" class="tg-bind__ok">已複製</span>
      <p class="tg-bind__hint">在 Telegram 搜尋本校機器人，傳送 /start 加上這組代碼，10 分鐘內有效</p>
    </div>
  </div>
</template>

<style scoped>
.tg-bind { margin-top: 16px; }
.tg-bind__btn { min-height: 44px; font-size: 16px; background: var(--ds-ink); color: #fff; }
.tg-bind__code { display: block; min-height: 44px; margin-top: 12px; padding: 8px 16px; font: 700 28px/1.2 ui-monospace, monospace; letter-spacing: .12em; color: var(--ds-ink); background: #fff; border: 2px solid #c9a227; border-radius: 10px; cursor: pointer; user-select: all; }
.tg-bind__ok { margin-left: 8px; font-size: 16px; color: #8a6d0b; }
.tg-bind__hint, .tg-bind__error { margin-top: 8px; font-size: 16px; line-height: 1.5; }
.tg-bind__error { color: #b42318; }
</style>
