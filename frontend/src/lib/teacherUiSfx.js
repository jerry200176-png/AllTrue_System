/**
 * 老師端「介面操作」短音效（Web Audio 合成，非取樣音檔）。
 * 與 TeacherHomePage 待辦提醒音（teacher_pending_sound_enabled）分開設定。
 * 預設關閉：localStorage teacher_ui_sfx_enabled 未設或 '0'。
 */

const STORAGE_KEY = 'teacher_ui_sfx_enabled';

let audioCtx = null;

function getAudioContext() {
  if (typeof window === 'undefined') return null;
  if (!audioCtx) {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    audioCtx = new Ctx();
  }
  return audioCtx;
}

export function isTeacherUiSfxEnabled() {
  try {
    const v = localStorage.getItem(STORAGE_KEY);
    if (v === null) return false;
    return v === '1';
  } catch {
    return false;
  }
}

export function setTeacherUiSfxEnabled(on) {
  try {
    localStorage.setItem(STORAGE_KEY, on ? '1' : '0');
  } catch {
    /* ignore */
  }
}

function scheduleTone(ctx, freq, startTime, durationSec, peakGain) {
  const osc = ctx.createOscillator();
  const g = ctx.createGain();
  osc.type = 'sine';
  osc.frequency.setValueAtTime(freq, startTime);
  const eps = 0.0001;
  g.gain.setValueAtTime(eps, startTime);
  g.gain.exponentialRampToValueAtTime(Math.max(peakGain, eps), startTime + 0.012);
  g.gain.exponentialRampToValueAtTime(eps, startTime + durationSec);
  osc.connect(g);
  g.connect(ctx.destination);
  osc.start(startTime);
  osc.stop(startTime + durationSec + 0.03);
}

/**
 * @param {'nav'|'tap'|'action'} kind
 */
export function playTeacherUiSfx(kind) {
  if (!isTeacherUiSfxEnabled()) return;
  const ctx = getAudioContext();
  if (!ctx) return;
  try {
    const t0 = ctx.currentTime;
    if (ctx.state === 'suspended') {
      ctx.resume().catch(() => {});
    }
    const vol = 0.055;
    if (kind === 'tap') {
      scheduleTone(ctx, 880, t0, 0.045, vol * 0.85);
      return;
    }
    if (kind === 'action') {
      scheduleTone(ctx, 523.25, t0, 0.09, vol * 1.1);
      scheduleTone(ctx, 659.25, t0 + 0.04, 0.1, vol);
      return;
    }
    // nav — 輕快兩音（選單／換頁）
    scheduleTone(ctx, 620, t0, 0.07, vol);
    scheduleTone(ctx, 740, t0 + 0.065, 0.08, vol * 0.95);
  } catch {
    /* ignore */
  }
}
