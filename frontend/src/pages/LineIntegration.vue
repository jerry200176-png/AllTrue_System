<template>
  <div class="li-page">

    <!-- 狀態總覽 -->
    <div class="card">
      <div class="li-top">
        <div>
          <h2>家長 LINE 通知設定</h2>
          <p class="sub">設定完成後，家長加入官方帳號即可收到學習報告連結</p>
        </div>
        <button class="ghost small" @click="loadStatus" :disabled="loading">↻ 重新整理</button>
      </div>

      <div v-if="status" class="status-row">
        <div class="sc" :class="status.channel_configured ? 'ok' : 'pending'">
          <span class="sc-dot"></span>
          <div>
            <div class="sc-title">LINE 官方帳號</div>
            <div class="sc-desc">{{ status.channel_configured ? '已連線' : '尚未設定' }}</div>
          </div>
        </div>
        <div class="sc" :class="status.liff_configured ? 'ok' : 'pending'">
          <span class="sc-dot"></span>
          <div>
            <div class="sc-title">手機一鍵開啟</div>
            <div class="sc-desc">{{ status.liff_configured ? '已設定' : '尚未設定' }}</div>
          </div>
        </div>
        <div class="sc" :class="status.bound_count > 0 ? 'ok' : 'pending'">
          <span class="sc-dot"></span>
          <div>
            <div class="sc-title">已綁定家長</div>
            <div class="sc-desc">{{ status.bound_count }} 位</div>
          </div>
        </div>
      </div>
      <div v-else-if="loading" class="hint" style="padding:12px 0;">載入中…</div>
    </div>

    <!-- 設定表單 -->
    <div class="card">
      <h3>🔑 填入 LINE 設定</h3>
      <p class="hint mb">以下資訊從 LINE 官方帳號管理後台取得，請見下方步驟說明</p>

      <div v-if="status" class="campus-badge">設定分校：{{ status.campus_name }}</div>

      <div class="field">
        <label>Channel Access Token <span class="required">*</span></label>
        <div class="input-row">
          <input
            v-model="form.messaging_channel_token"
            :type="show.token ? 'text' : 'password'"
            :placeholder="status?.has_channel_token ? '（已設定，輸入新值可覆蓋）' : '貼上你的 Channel Access Token…'"
            class="mono-input"
          />
          <button class="toggle-btn" @click="show.token = !show.token">{{ show.token ? '隱藏' : '顯示' }}</button>
        </div>
        <p class="field-hint">在 LINE Developers → Messaging API → Channel access token 取得（長期 Token）</p>
      </div>

      <div class="field">
        <label>Channel Secret <span class="required">*</span></label>
        <div class="input-row">
          <input
            v-model="form.messaging_channel_secret"
            :type="show.secret ? 'text' : 'password'"
            :placeholder="status?.has_channel_secret ? '（已設定，輸入新值可覆蓋）' : '貼上你的 Channel Secret…'"
            class="mono-input"
          />
          <button class="toggle-btn" @click="show.secret = !show.secret">{{ show.secret ? '隱藏' : '顯示' }}</button>
        </div>
        <p class="field-hint">在 LINE Developers → Basic settings → Channel secret 取得</p>
      </div>

      <div class="field">
        <label>LIFF ID <span class="optional">（選填，讓家長在 LINE 內直接開啟頁面）</span></label>
        <input
          v-model="form.liff_id"
          type="text"
          placeholder="例：1234567890-AbCdEfGh"
          class="mono-input"
        />
        <p class="field-hint">在 LINE Developers → LIFF 分頁建立後取得</p>
      </div>

      <div class="save-row">
        <button class="primary" @click="saveSettings" :disabled="saving">
          {{ saving ? '儲存中…' : '儲存設定' }}
        </button>
        <span v-if="saveMsg" class="save-msg" :class="saveOk ? 'ok' : 'err'">{{ saveMsg }}</span>
      </div>
    </div>

    <!-- Webhook URL -->
    <div class="card" v-if="status">
      <h3>📡 Webhook 網址（填入 LINE 後台）</h3>
      <p class="hint mb">到 LINE Developers → Messaging API → Webhook settings，貼上以下網址並開啟「Use webhook」</p>
      <div class="url-box">
        <code>{{ status.webhook_url }}</code>
        <button class="small ghost" @click="copy(status.webhook_url, 'webhook')">
          {{ copied === 'webhook' ? '✓ 已複製' : '複製' }}
        </button>
      </div>
    </div>

    <!-- 步驟說明 -->
    <div class="card">
      <h3>📋 設定步驟（點擊展開）</h3>

      <div v-for="(step, i) in steps" :key="i" class="step" :class="{ open: openStep === i }">
        <button class="step-head" @click="openStep = openStep === i ? -1 : i">
          <span class="step-num">{{ i + 1 }}</span>
          <span class="step-title">{{ step.title }}</span>
          <span class="step-arrow">{{ openStep === i ? '▲' : '▼' }}</span>
        </button>
        <div class="step-body" v-if="openStep === i">
          <p v-for="(line, j) in step.lines" :key="j" v-html="line"></p>
        </div>
      </div>
    </div>

    <!-- 家長綁定說明 -->
    <div class="card">
      <h3>👨‍👩‍👧 告訴家長怎麼做</h3>
      <p class="hint mb">設定完成後，把以下說明傳給家長：</p>
      <div class="parent-instruction">
        <p>📱 加入補習班 LINE 官方帳號後，傳送：</p>
        <div class="code-block">綁定 學生姓名<br>例：綁定 王小明</div>
        <p>系統會自動回覆確認訊息，之後家長只要傳任何訊息，就會收到查看連結。</p>
        <p style="margin-top:8px;font-size:12px;color:#64748b;">※ 若有同名學生，系統會提示改用「綁定 學號」</p>
      </div>
      <button class="ghost small mt" @click="copyParentGuide">{{ copied === 'guide' ? '✓ 已複製說明文字' : '複製說明文字' }}</button>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { supabase } from '../supabase';

const props = defineProps({ branchId: { type: Number, default: null } });

const loading = ref(false);
const saving  = ref(false);
const status  = ref(null);
const saveMsg = ref('');
const saveOk  = ref(true);
const copied  = ref('');
const openStep = ref(0);

const form = ref({ messaging_channel_token: '', messaging_channel_secret: '', liff_id: '' });
const show = ref({ token: false, secret: false });

const steps = [
  {
    title: '建立 LINE 官方帳號 & 開啟 Messaging API',
    lines: [
      '1. 用電腦打開 <strong>LINE Official Account Manager</strong>（<code>manager.line.biz</code>）',
      '2. 登入後點擊右上角「設定」→「Messaging API」→「啟用 Messaging API」',
      '3. 選擇或建立一個 Provider，完成後系統會帶你到 LINE Developers Console',
      '✅ 完成後你就有了一個可以接收訊息的官方帳號',
    ],
  },
  {
    title: '取得 Channel Access Token 和 Channel Secret',
    lines: [
      '1. 進入 <strong>LINE Developers Console</strong>（<code>developers.line.biz</code>）',
      '2. 點擊你的 Channel → 上方選「<strong>Messaging API</strong>」分頁',
      '3. 往下捲找到「<strong>Channel access token</strong>」→ 點「<strong>Issue</strong>」產生 Token → 複製貼到上方表單',
      '4. 點上方「<strong>Basic settings</strong>」分頁 → 找「<strong>Channel secret</strong>」→ 複製貼到上方表單',
      '5. 回到本頁點「<strong>儲存設定</strong>」',
    ],
  },
  {
    title: '填入 Webhook 網址（讓系統接收家長訊息）',
    lines: [
      '1. 在 LINE Developers → Messaging API 分頁，找「<strong>Webhook settings</strong>」',
      '2. 點「<strong>Edit</strong>」，將上方的 Webhook 網址貼入',
      '3. 開啟「<strong>Use webhook</strong>」開關',
      '4. 點「<strong>Verify</strong>」，若顯示「Success」表示設定成功 ✅',
    ],
  },
  {
    title: '（選）設定 LIFF，讓家長在 LINE 內直接開啟頁面',
    lines: [
      '若不設定，家長點連結會用手機瀏覽器開啟，設定後體驗更流暢。',
      '1. 在 LINE Developers → 你的 Channel → 上方選「<strong>LIFF</strong>」分頁',
      '2. 點「<strong>Add</strong>」，Size 選「<strong>Full</strong>」',
      `3. Endpoint URL 填入：<strong>${window.location.origin}/#/parent</strong>（你的分校網站網址）`,
      '4. Scopes 勾選「<strong>profile</strong>」→ 按「Add」建立',
      '5. 建立後會出現 <strong>LIFF ID</strong>（格式：1234567890-xxxxxxxx），複製貼到上方表單儲存',
    ],
  },
];

async function getAuthHeaders() {
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  const h = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (token) h['Authorization'] = `Bearer ${token}`;
  return h;
}

async function loadStatus() {
  loading.value = true;
  try {
    const headers = await getAuthHeaders();
    const url = props.branchId
      ? `/api/v1/line/status?branch_id=${props.branchId}`
      : '/api/v1/line/status';
    const res = await fetch(url, { headers, credentials: 'include' });
    if (res.ok) {
      status.value = await res.json();
      // Pre-fill LIFF ID if already set (non-sensitive)
      if (status.value.liff_id_value) form.value.liff_id = status.value.liff_id_value;
    } else {
      console.error('LINE status API error:', res.status, await res.text());
    }
  } catch (e) {
    console.error('Failed to load LINE status:', e);
  } finally {
    loading.value = false;
  }
}

async function saveSettings() {
  saving.value = true;
  saveMsg.value = '';
  try {
    const headers = await getAuthHeaders();
    const body = {};
    if (form.value.messaging_channel_token.trim()) body.messaging_channel_token = form.value.messaging_channel_token.trim();
    if (form.value.messaging_channel_secret.trim()) body.messaging_channel_secret = form.value.messaging_channel_secret.trim();
    body.liff_id = form.value.liff_id.trim();

    const res = await fetch('/api/v1/line/settings', {
      method: 'POST',
      headers,
      credentials: 'include',
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (res.ok) {
      saveOk.value = true;
      saveMsg.value = '✅ 已儲存！';
      status.value = data.status;
      // Clear sensitive fields after save
      form.value.messaging_channel_token = '';
      form.value.messaging_channel_secret = '';
    } else {
      saveOk.value = false;
      saveMsg.value = data.message || '儲存失敗';
    }
  } catch (e) {
    saveOk.value = false;
    saveMsg.value = '連線錯誤，請稍後再試';
  } finally {
    saving.value = false;
    setTimeout(() => { saveMsg.value = ''; }, 4000);
  }
}

function copy(text, key) {
  navigator.clipboard.writeText(text).then(() => {
    copied.value = key;
    setTimeout(() => { copied.value = ''; }, 2000);
  });
}

function copyParentGuide() {
  const text = '加入補習班 LINE 官方帳號後，請傳送以下訊息完成綁定：\n\n綁定 學生姓名\n例：綁定 王小明\n\n綁定成功後，傳任何訊息都可以收到查看學習狀況的連結。';
  copy(text, 'guide');
}

onMounted(loadStatus);
watch(() => props.branchId, () => { status.value = null; loadStatus(); });
</script>

<style scoped>
.li-page { max-width: 780px; margin: 0 auto; }

.card {
  background: #fff;
  border-radius: 14px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 8px rgba(15,23,42,0.05);
  padding: 24px;
  margin-bottom: 16px;
}

h2 { margin: 0 0 4px; font-size: 20px; font-weight: 700; color: #1e293b; }
h3 { margin: 0 0 12px; font-size: 15px; font-weight: 700; color: #1e293b; }
.sub { margin: 0; font-size: 13px; color: #64748b; }
.hint { font-size: 12px; color: #64748b; }
.hint.mb { margin-bottom: 14px; }
.mb { margin-bottom: 14px; }
.mt { margin-top: 12px; }

.li-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 20px;
}

/* Status row */
.status-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sc {
  flex: 1;
  min-width: 140px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
}
.sc.ok    { border-color: #bbf7d0; background: #f0fdf4; }
.sc.pending { border-color: #fed7aa; background: #fff7ed; }
.sc-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  background: #94a3b8;
  flex-shrink: 0;
}
.sc.ok .sc-dot    { background: #22c55e; }
.sc.pending .sc-dot { background: #fb923c; }
.sc-title { font-size: 12px; font-weight: 700; color: #374151; }
.sc-desc  { font-size: 13px; color: #64748b; margin-top: 1px; }

/* Form */
.field { margin-bottom: 18px; }
.field label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.required { color: #e11d48; }
.optional { font-size: 11px; color: #94a3b8; font-weight: 400; }
.field-hint { font-size: 11px; color: #94a3b8; margin: 4px 0 0; }

.input-row { display: flex; gap: 8px; }
.input-row input { flex: 1; }

.mono-input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  font-family: 'Courier New', monospace;
  color: #0f172a;
  background: #f8fafc;
  box-sizing: border-box;
}
.mono-input:focus { outline: none; border-color: #06C755; background: #fff; }

.toggle-btn {
  padding: 0 14px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
  font-size: 12px;
  cursor: pointer;
  white-space: nowrap;
  color: #475569;
}
.toggle-btn:hover { background: #f1f5f9; }

.campus-badge {
  display: inline-block;
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 16px;
}

.save-row { display: flex; align-items: center; gap: 12px; margin-top: 4px; }
.save-msg { font-size: 13px; font-weight: 600; }
.save-msg.ok { color: #16a34a; }
.save-msg.err { color: #dc2626; }

/* Webhook URL */
.url-box {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
}
.url-box code {
  flex: 1;
  font-size: 13px;
  font-family: 'Courier New', monospace;
  color: #0f172a;
  word-break: break-all;
}

/* Steps */
.step {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  margin-bottom: 8px;
  overflow: hidden;
}
.step.open { border-color: #06C755; }

.step-head {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: none;
  border: none;
  cursor: pointer;
  text-align: left;
  font-size: 14px;
}
.step-head:hover { background: #f8fafc; }
.step.open .step-head { background: #f0fdf4; }

.step-num {
  width: 26px; height: 26px;
  border-radius: 50%;
  background: #06C755;
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.step-title { flex: 1; font-weight: 600; color: #1e293b; }
.step-arrow { color: #94a3b8; font-size: 11px; }

.step-body {
  padding: 4px 16px 16px 54px;
  border-top: 1px solid #e2e8f0;
}
.step-body p {
  font-size: 13px;
  color: #475569;
  margin: 8px 0;
  line-height: 1.7;
}
.step-body code {
  background: #f1f5f9;
  padding: 1px 5px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 12px;
}

/* Parent guide */
.parent-instruction {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 10px;
  padding: 16px;
}
.parent-instruction p { font-size: 13px; color: #374151; margin: 6px 0; }
.code-block {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 14px;
  font-family: monospace;
  font-size: 13px;
  color: #0f172a;
  margin: 8px 0;
  line-height: 1.8;
}

@media (max-width: 640px) {
  .status-row { flex-direction: column; }
  .li-top { flex-direction: column; }
}
</style>
