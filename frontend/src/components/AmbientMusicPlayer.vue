<template>
  <div class="ambient-player" :class="{ open: panelOpen, playing: isPlaying }">
    <button
      type="button"
      class="ambient-trigger"
      :aria-expanded="panelOpen ? 'true' : 'false'"
      aria-controls="ambient-music-panel"
      title="工作音樂"
      @click="panelOpen = !panelOpen"
    >
      <span class="material-symbols-outlined" aria-hidden="true">{{ isPlaying ? 'volume_up' : 'music_note' }}</span>
      <span class="ambient-trigger-label">工作音樂</span>
      <span v-if="isPlaying" class="ambient-live-dot" aria-hidden="true"></span>
    </button>

    <div v-if="panelOpen" id="ambient-music-panel" class="ambient-panel" role="dialog" aria-label="工作音樂播放器">
      <div class="ambient-panel-head">
        <div>
          <div class="ambient-title">輕鬆工作音</div>
          <div class="ambient-subtitle">手動開啟，不會自動播放</div>
        </div>
        <button
          type="button"
          class="ambient-icon-btn"
          :aria-label="isPlaying ? '暫停工作音樂' : '播放工作音樂'"
          @click="togglePlayback"
        >
          <span class="material-symbols-outlined" aria-hidden="true">{{ isPlaying ? 'pause' : 'play_arrow' }}</span>
        </button>
      </div>

      <div class="ambient-tracks" role="group" aria-label="選擇音景">
        <button
          v-for="track in tracks"
          :key="track.id"
          type="button"
          class="ambient-track"
          :class="{ active: activeTrackId === track.id }"
          @click="selectTrack(track.id)"
        >
          <span class="ambient-track-icon" aria-hidden="true">{{ track.icon }}</span>
          <span>
            <strong>{{ track.name }}</strong>
            <small>{{ track.description }}</small>
          </span>
        </button>
      </div>

      <label class="ambient-volume">
        <span>音量</span>
        <input
          v-model.number="volume"
          type="range"
          min="0"
          max="0.6"
          step="0.01"
          aria-label="工作音樂音量"
        />
        <span class="ambient-volume-value">{{ Math.round(volume * 100) }}%</span>
      </label>

      <p class="ambient-license-note">
        使用瀏覽器本地合成音景，無第三方音檔。
      </p>
      <p v-if="errorMessage" class="ambient-error" role="alert">{{ errorMessage }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const STORAGE_KEY = 'alltrue_ambient_music_preferences';
const DEFAULT_VOLUME = 0.25;

const tracks = [
  {
    id: 'rain',
    name: '雨聲',
    icon: 'rainy',
    description: '柔和高頻雨幕',
  },
  {
    id: 'cafe',
    name: '咖啡廳',
    icon: 'local_cafe',
    description: '低調人聲感背景',
  },
  {
    id: 'brown',
    name: '棕噪音',
    icon: 'graphic_eq',
    description: '穩定低頻遮噪',
  },
];

const panelOpen = ref(false);
const isPlaying = ref(false);
const activeTrackId = ref('rain');
const volume = ref(DEFAULT_VOLUME);
const errorMessage = ref('');

let audioCtx = null;
let masterGain = null;
let activeNodes = [];

const activeTrack = computed(() => tracks.find((track) => track.id === activeTrackId.value) || tracks[0]);

onMounted(() => {
  loadPreferences();
});

onBeforeUnmount(() => {
  stopSound();
  if (audioCtx) {
    audioCtx.close?.();
    audioCtx = null;
  }
});

watch(volume, (next) => {
  const safeVolume = clampVolume(next);
  if (safeVolume !== next) {
    volume.value = safeVolume;
    return;
  }
  if (masterGain) {
    masterGain.gain.setTargetAtTime(safeVolume, audioCtx.currentTime, 0.03);
  }
  savePreferences();
});

watch(activeTrackId, savePreferences);

function loadPreferences() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const prefs = JSON.parse(raw);
    if (tracks.some((track) => track.id === prefs.trackId)) {
      activeTrackId.value = prefs.trackId;
    }
    if (typeof prefs.volume === 'number') {
      volume.value = clampVolume(prefs.volume);
    }
  } catch {
    // Ignore broken localStorage values; the player can fall back to defaults.
  }
}

function savePreferences() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      trackId: activeTrackId.value,
      volume: clampVolume(volume.value),
    }));
  } catch {
    // Preferences are optional and should never block the work UI.
  }
}

function clampVolume(value) {
  if (!Number.isFinite(value)) return DEFAULT_VOLUME;
  return Math.min(Math.max(value, 0), 0.6);
}

async function togglePlayback() {
  if (isPlaying.value) {
    stopSound();
    return;
  }
  await startSound();
}

async function selectTrack(trackId) {
  if (!tracks.some((track) => track.id === trackId)) return;
  activeTrackId.value = trackId;
  if (isPlaying.value) {
    stopSound({ keepPlayingState: true });
    await startSound();
  }
}

async function ensureAudioContext() {
  if (!audioCtx) {
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) {
      throw new Error('此瀏覽器不支援工作音樂');
    }
    audioCtx = new AudioContextCtor();
  }
  if (audioCtx.state === 'suspended') {
    await audioCtx.resume();
  }
  if (!masterGain) {
    masterGain = audioCtx.createGain();
    masterGain.gain.value = clampVolume(volume.value);
    masterGain.connect(audioCtx.destination);
  }
}

async function startSound() {
  errorMessage.value = '';
  try {
    await ensureAudioContext();
    stopSound({ keepPlayingState: true });
    activeNodes = createTrackNodes(activeTrack.value.id);
    isPlaying.value = true;
  } catch (error) {
    stopSound();
    errorMessage.value = error?.message || '音訊啟動失敗，請稍後再試';
  }
}

function stopSound(options = {}) {
  activeNodes.forEach((node) => {
    try {
      node.stop?.();
    } catch {
      // Some node types cannot be stopped or may already be stopped.
    }
    try {
      node.disconnect?.();
    } catch {
      // Disconnect best effort only.
    }
  });
  activeNodes = [];
  if (!options.keepPlayingState) {
    isPlaying.value = false;
  }
}

function createTrackNodes(trackId) {
  if (trackId === 'brown') return createBrownNoise();
  if (trackId === 'cafe') return createCafeNoise();
  return createRainNoise();
}

function createLoopingNoiseSource(durationSeconds = 2, generator = () => Math.random() * 2 - 1) {
  const frameCount = Math.max(1, Math.floor(audioCtx.sampleRate * durationSeconds));
  const buffer = audioCtx.createBuffer(1, frameCount, audioCtx.sampleRate);
  const data = buffer.getChannelData(0);
  for (let i = 0; i < frameCount; i += 1) {
    data[i] = generator(i);
  }
  const source = audioCtx.createBufferSource();
  source.buffer = buffer;
  source.loop = true;
  return source;
}

function createRainNoise() {
  const source = createLoopingNoiseSource(2.5);
  const highpass = audioCtx.createBiquadFilter();
  highpass.type = 'highpass';
  highpass.frequency.value = 900;
  const lowpass = audioCtx.createBiquadFilter();
  lowpass.type = 'lowpass';
  lowpass.frequency.value = 5200;
  const gain = audioCtx.createGain();
  gain.gain.value = 0.45;

  source.connect(highpass).connect(lowpass).connect(gain).connect(masterGain);
  source.start();
  return [source, highpass, lowpass, gain];
}

function createCafeNoise() {
  const room = createLoopingNoiseSource(3, () => (Math.random() * 2 - 1) * 0.7);
  const murmur = createLoopingNoiseSource(4, (i) => {
    const base = Math.sin(i / 37) * 0.25 + Math.sin(i / 91) * 0.18;
    return base + (Math.random() * 2 - 1) * 0.25;
  });
  const roomFilter = audioCtx.createBiquadFilter();
  roomFilter.type = 'bandpass';
  roomFilter.frequency.value = 900;
  roomFilter.Q.value = 0.7;
  const murmurFilter = audioCtx.createBiquadFilter();
  murmurFilter.type = 'lowpass';
  murmurFilter.frequency.value = 1200;
  const gain = audioCtx.createGain();
  gain.gain.value = 0.28;

  room.connect(roomFilter).connect(gain);
  murmur.connect(murmurFilter).connect(gain);
  gain.connect(masterGain);
  room.start();
  murmur.start();
  return [room, murmur, roomFilter, murmurFilter, gain];
}

function createBrownNoise() {
  let lastOut = 0;
  const source = createLoopingNoiseSource(2, () => {
    const white = Math.random() * 2 - 1;
    lastOut = (lastOut + (0.02 * white)) / 1.02;
    return lastOut * 3.5;
  });
  const lowpass = audioCtx.createBiquadFilter();
  lowpass.type = 'lowpass';
  lowpass.frequency.value = 900;
  const gain = audioCtx.createGain();
  gain.gain.value = 0.5;

  source.connect(lowpass).connect(gain).connect(masterGain);
  source.start();
  return [source, lowpass, gain];
}
</script>

<style scoped>
.ambient-player {
  position: relative;
  z-index: 30;
}

.ambient-trigger {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 42px;
  padding: 7px 11px;
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 999px;
  background: var(--topbar-bg, #fff);
  color: var(--text, #334155);
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
}

.ambient-trigger:hover,
.ambient-player.open .ambient-trigger {
  border-color: rgba(14, 165, 233, 0.45);
  color: #0369a1;
}

.ambient-trigger .material-symbols-outlined {
  font-size: 19px;
}

.ambient-live-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: #22c55e;
  box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.14);
}

.ambient-panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: min(320px, calc(100vw - 32px));
  padding: 14px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  border-radius: 18px;
  background: var(--card-bg, #fff);
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
}

.ambient-panel-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.ambient-title {
  font-size: 14px;
  font-weight: 800;
  color: var(--text, #334155);
}

.ambient-subtitle {
  margin-top: 2px;
  font-size: 12px;
  color: var(--text-light, #64748b);
}

.ambient-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border: 0;
  border-radius: 14px;
  background: linear-gradient(135deg, #0ea5e9, #22c55e);
  color: #fff;
  cursor: pointer;
  box-shadow: 0 8px 18px rgba(14, 165, 233, 0.22);
}

.ambient-icon-btn .material-symbols-outlined {
  font-size: 24px;
}

.ambient-tracks {
  display: grid;
  gap: 8px;
}

.ambient-track {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 10px;
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 14px;
  background: #f8fafc;
  color: var(--text, #334155);
  text-align: left;
  cursor: pointer;
}

.ambient-track.active {
  border-color: #38bdf8;
  background: #ecfeff;
}

.ambient-track-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 12px;
  background: #e0f2fe;
  color: #0369a1;
  font-family: 'Material Symbols Outlined';
  font-size: 20px;
}

.ambient-track strong,
.ambient-track small {
  display: block;
}

.ambient-track strong {
  font-size: 13px;
}

.ambient-track small {
  margin-top: 2px;
  font-size: 12px;
  color: var(--text-light, #64748b);
}

.ambient-volume {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  font-size: 12px;
  color: var(--text-light, #64748b);
}

.ambient-volume input {
  width: 100%;
  accent-color: #0ea5e9;
}

.ambient-volume-value {
  min-width: 34px;
  text-align: right;
}

.ambient-license-note,
.ambient-error {
  margin: 10px 0 0;
  font-size: 12px;
  line-height: 1.45;
}

.ambient-license-note {
  color: var(--text-light, #64748b);
}

.ambient-error {
  color: #b91c1c;
}

@media (max-width: 640px) {
  .ambient-trigger {
    min-width: 42px;
    padding: 7px 10px;
  }

  .ambient-trigger-label {
    display: none;
  }

  .ambient-panel {
    right: -68px;
  }
}
</style>
