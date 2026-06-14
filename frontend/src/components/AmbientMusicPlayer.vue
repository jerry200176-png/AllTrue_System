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
          <div class="ambient-title">AllTrue 自製工作音</div>
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

      <div class="ambient-tracks" role="group" aria-label="選擇音樂">
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
        使用 AllTrue 自製音樂，預設不下載，按播放後才載入。
      </p>
      <p v-if="errorMessage" class="ambient-error" role="alert">{{ errorMessage }}</p>
    </div>

    <audio
      ref="audioRef"
      :src="activeTrack.src"
      preload="none"
      loop
      @play="isPlaying = true"
      @pause="isPlaying = false"
      @ended="isPlaying = false"
      @error="onAudioError"
    ></audio>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const STORAGE_KEY = 'alltrue_ambient_music_preferences';
const DEFAULT_VOLUME = 0.25;

const tracks = [
  {
    id: 'tutoring-loop',
    name: 'Tutoring Loop',
    icon: 'school',
    description: '補習班行政工作循環',
    src: '/audio/tutoring-loop.mp3',
  },
  {
    id: 'paper-window',
    name: 'Paper Window',
    icon: 'description',
    description: '柔和紙張與窗邊氛圍',
    src: '/audio/paper-window.mp3',
  },
  {
    id: 'paperwork-rain',
    name: 'Paperwork Rain',
    icon: 'rainy',
    description: '雨天文書背景音',
    src: '/audio/paperwork-rain.mp3',
  },
];

const panelOpen = ref(false);
const isPlaying = ref(false);
const activeTrackId = ref('tutoring-loop');
const volume = ref(DEFAULT_VOLUME);
const errorMessage = ref('');
const audioRef = ref(null);

const activeTrack = computed(() => tracks.find((track) => track.id === activeTrackId.value) || tracks[0]);

onMounted(() => {
  loadPreferences();
  syncAudioVolume();
});

onBeforeUnmount(() => {
  pauseAudio();
});

watch(volume, (next) => {
  const safeVolume = clampVolume(next);
  if (safeVolume !== next) {
    volume.value = safeVolume;
    return;
  }
  syncAudioVolume();
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

function syncAudioVolume() {
  if (audioRef.value) {
    audioRef.value.volume = clampVolume(volume.value);
  }
}

async function togglePlayback() {
  if (isPlaying.value) {
    pauseAudio();
    return;
  }
  await playAudio();
}

async function selectTrack(trackId) {
  if (!tracks.some((track) => track.id === trackId)) return;
  const shouldResume = isPlaying.value;
  activeTrackId.value = trackId;
  errorMessage.value = '';
  await nextTick();
  syncAudioVolume();

  if (audioRef.value) {
    audioRef.value.load();
  }
  if (shouldResume) {
    await playAudio();
  }
}

async function playAudio() {
  errorMessage.value = '';
  try {
    syncAudioVolume();
    await audioRef.value?.play();
    isPlaying.value = true;
  } catch {
    isPlaying.value = false;
    errorMessage.value = '音樂啟動失敗，請再點一次播放或檢查瀏覽器音訊設定';
  }
}

function pauseAudio() {
  audioRef.value?.pause();
  isPlaying.value = false;
}

function onAudioError() {
  isPlaying.value = false;
  errorMessage.value = '音樂檔案載入失敗，請稍後再試';
}
</script>

<style scoped>
.ambient-player {
  position: relative;
  z-index: 30;
}

.ambient-player audio {
  display: none;
}

.ambient-trigger {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 42px;
  padding: 7px 11px;
  border: 1px solid var(--border, var(--ds-canvas-soft));
  border-radius: 999px;
  background: var(--topbar-bg, var(--ds-canvas));
  color: var(--text, var(--ds-ink));
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
}

.ambient-trigger:hover,
.ambient-player.open .ambient-trigger {
  border-color: rgba(14, 165, 233, 0.45);
  color: var(--ds-ink);
}

.ambient-trigger .material-symbols-outlined {
  font-size: 19px;
}

.ambient-live-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--ds-success);
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
  background: var(--card-bg, var(--ds-canvas));
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
  color: var(--text, var(--ds-ink));
}

.ambient-subtitle {
  margin-top: 2px;
  font-size: 12px;
  color: var(--text-light, var(--ds-ink-mute));
}

.ambient-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border: 0;
  border-radius: 14px;
  background: linear-gradient(135deg, var(--ds-ink-mute), var(--ds-success));
  color: var(--ds-canvas);
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
  border: 1px solid var(--border, var(--ds-canvas-soft));
  border-radius: 14px;
  background: var(--ds-canvas-soft);
  color: var(--text, var(--ds-ink));
  text-align: left;
  cursor: pointer;
}

.ambient-track.active {
  border-color: var(--ds-ink-mute);
  background: var(--ds-canvas-soft);
}

.ambient-track-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 12px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
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
  color: var(--text-light, var(--ds-ink-mute));
}

.ambient-volume {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  font-size: 12px;
  color: var(--text-light, var(--ds-ink-mute));
}

.ambient-volume input {
  width: 100%;
  accent-color: var(--ds-ink-mute);
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
  color: var(--text-light, var(--ds-ink-mute));
}

.ambient-error {
  color: var(--ds-danger);
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
