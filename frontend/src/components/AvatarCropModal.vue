<template>
  <div v-if="modelValue" class="crop-overlay" @click.self="onCancel">
    <div class="crop-modal" @click.stop>
      <h3>調整頭像呈現範圍</h3>
      <p class="crop-hint">
        拖曳照片對準要顯示的區域，並用滑桿縮放。確認後會輸出正方形圖檔，與左下角側欄的顯示方式一致。
      </p>
      <div
        class="viewport"
        @pointerdown="onPointerDown"
        @wheel.prevent="onWheel"
      >
        <img
          v-show="imageSrc"
          ref="imgRef"
          :src="imageSrc"
          alt=""
          class="crop-img"
          :style="imgStyle"
          draggable="false"
          @load="onImgLoad"
        />
      </div>
      <div class="zoom-row">
        <label>縮放 {{ Math.round(zoom * 100) }}%</label>
        <input v-model.number="zoom" type="range" min="1" max="3" step="0.02" @input="clampPan" />
      </div>
      <div class="crop-actions">
        <button type="button" class="btn-cancel" @click="onCancel">取消</button>
        <button type="button" class="btn-ok" :disabled="!ready || exporting" @click="onConfirm">
          {{ exporting ? '處理中…' : '確認並上傳' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const VIEW = 280;

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  imageSrc: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel']);

const imgRef = ref(null);
const ready = ref(false);
const exporting = ref(false);
const zoom = ref(1);
const pan = ref({ x: 0, y: 0 });
const meta = ref({ nw: 0, nh: 0, cover: 1 });

let dragging = false;
let start = { x: 0, y: 0 };
let panStart = { x: 0, y: 0 };

const imgStyle = computed(() => {
  const { nw, nh, cover } = meta.value;
  if (!nw || !nh) return { visibility: 'hidden' };
  const dw = nw * cover * zoom.value;
  const dh = nh * cover * zoom.value;
  const left = (VIEW - dw) / 2 + pan.value.x;
  const top = (VIEW - dh) / 2 + pan.value.y;
  return {
    position: 'absolute',
    width: `${dw}px`,
    height: `${dh}px`,
    left: `${left}px`,
    top: `${top}px`,
    userSelect: 'none',
    touchAction: 'none',
  };
});

function clampPan() {
  const { nw, nh, cover } = meta.value;
  if (!nw || !nh) return;
  const dw = nw * cover * zoom.value;
  const dh = nh * cover * zoom.value;
  const maxX = Math.max(0, (dw - VIEW) / 2);
  const maxY = Math.max(0, (dh - VIEW) / 2);
  pan.value = {
    x: Math.min(maxX, Math.max(-maxX, pan.value.x)),
    y: Math.min(maxY, Math.max(-maxY, pan.value.y)),
  };
}

function onImgLoad() {
  const el = imgRef.value;
  if (!el) return;
  const nw = el.naturalWidth;
  const nh = el.naturalHeight;
  if (!nw || !nh) return;
  meta.value = {
    nw,
    nh,
    cover: Math.max(VIEW / nw, VIEW / nh),
  };
  zoom.value = 1;
  pan.value = { x: 0, y: 0 };
  clampPan();
  ready.value = true;
}

function onWindowMove(e) {
  if (!dragging) return;
  pan.value = {
    x: panStart.x + (e.clientX - start.x),
    y: panStart.y + (e.clientY - start.y),
  };
  clampPan();
}

function onWindowUp() {
  dragging = false;
  window.removeEventListener('pointermove', onWindowMove);
  window.removeEventListener('pointerup', onWindowUp);
  window.removeEventListener('pointercancel', onWindowUp);
}

function onPointerDown(e) {
  if (!ready.value) return;
  dragging = true;
  start = { x: e.clientX, y: e.clientY };
  panStart = { ...pan.value };
  window.addEventListener('pointermove', onWindowMove);
  window.addEventListener('pointerup', onWindowUp);
  window.addEventListener('pointercancel', onWindowUp);
}

function onWheel(e) {
  if (!ready.value) return;
  const delta = e.deltaY > 0 ? -0.06 : 0.06;
  zoom.value = Math.min(3, Math.max(1, zoom.value + delta));
  clampPan();
}

function onCancel() {
  onWindowUp();
  exporting.value = false;
  ready.value = false;
  emit('update:modelValue', false);
  emit('cancel');
}

function onConfirm() {
  const el = imgRef.value;
  const { nw, nh, cover } = meta.value;
  if (!el || !nw || !nh || !ready.value) return;
  exporting.value = true;
  try {
    const c = cover * zoom.value;
    const dw = nw * c;
    const dh = nh * c;
    const left = (VIEW - dw) / 2 + pan.value.x;
    const top = (VIEW - dh) / 2 + pan.value.y;
    let sx = (-left / dw) * nw;
    let sy = (-top / dh) * nh;
    let sw = (VIEW / dw) * nw;
    let sh = (VIEW / dh) * nh;
    sx = Math.max(0, Math.min(nw - 0.5, sx));
    sy = Math.max(0, Math.min(nh - 0.5, sy));
    sw = Math.min(sw, nw - sx);
    sh = Math.min(sh, nh - sy);
    if (sw < 1 || sh < 1) {
      exporting.value = false;
      return;
    }

    const canvas = document.createElement('canvas');
    const out = 512;
    canvas.width = out;
    canvas.height = out;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(el, sx, sy, sw, sh, 0, 0, out, out);
    canvas.toBlob(
      (blob) => {
        exporting.value = false;
        if (!blob) {
          emit('update:modelValue', false);
          return;
        }
        const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
        emit('confirm', file);
        emit('update:modelValue', false);
        ready.value = false;
      },
      'image/jpeg',
      0.92
    );
  } catch {
    exporting.value = false;
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) {
      onWindowUp();
      ready.value = false;
      dragging = false;
      zoom.value = 1;
      pan.value = { x: 0, y: 0 };
      meta.value = { nw: 0, nh: 0, cover: 1 };
    }
  }
);

watch(zoom, () => clampPan());
</script>

<style scoped>
.crop-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.crop-modal {
  background: var(--card-bg, var(--ds-canvas));
  border-radius: 14px;
  padding: 20px 22px;
  max-width: 420px;
  width: 100%;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.crop-modal h3 {
  margin: 0 0 8px;
  font-size: 1.1rem;
}
.crop-hint {
  margin: 0 0 14px;
  font-size: 13px;
  color: var(--text-light, var(--ds-ink-mute));
  line-height: 1.45;
}
.viewport {
  width: 280px;
  height: 280px;
  margin: 0 auto;
  position: relative;
  overflow: hidden;
  border-radius: 12px;
  background: var(--ds-ink);
  cursor: grab;
  touch-action: none;
}
.viewport:active {
  cursor: grabbing;
}
.crop-img {
  display: block;
  max-width: none;
}
.zoom-row {
  margin-top: 14px;
  display: grid;
  gap: 6px;
}
.zoom-row label {
  font-size: 13px;
  color: var(--text-muted, var(--ds-ink));
}
.zoom-row input[type='range'] {
  width: 100%;
}
.crop-actions {
  display: flex;
  gap: 10px;
  margin-top: 18px;
  justify-content: flex-end;
}
.btn-cancel {
  padding: 8px 16px;
  border-radius: 8px;
  border: 1px solid var(--border, var(--ds-canvas-soft));
  background: var(--card-bg, var(--ds-canvas));
  cursor: pointer;
  font-size: 14px;
}
.btn-ok {
  padding: 8px 16px;
  border-radius: 8px;
  border: none;
  background: var(--primary, var(--ds-warning));
  color: var(--ds-canvas);
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}
.btn-ok:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
