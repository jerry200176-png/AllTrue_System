<template>
  <div class="chat-page" @click="closeContextMenu">
    <div class="page-header" data-guide="chat-header">
      <h2><span class="material-symbols-outlined header-icon">forum</span> 內部聊天</h2>
      <div class="page-desc">校區人員即時訊息</div>
      <div v-if="superAdmin" class="page-desc-sub">您的列表會包含所有曾參與的分校對話（與左上角分校切換無關）。若仍為空，代表尚無聊天室或載入失敗。</div>
      <div v-else class="page-desc-sub">對話紀錄依「左上角目前分校」篩選；在別校開過的對話，需切回該分校才會出現在列表。</div>
    </div>

    <div v-if="branchId == null" class="card empty-card">
      請先選擇分校後再使用聊天功能。
    </div>

    <template v-else>
      <div class="chat-container">
        <div v-if="threadsLoadError" class="chat-inline-alert" role="alert">
          {{ threadsLoadError }}
        </div>
        <div class="chat-panels-row">

          <!-- Thread list (left panel) -->
          <div class="thread-panel" :class="{ 'hidden-mobile': activeThread }">
            <div class="thread-panel-header">
              <button class="btn-new-chat" @click="showNewChat = true; dmSearch = ''; selectedUserId = ''">
                <span class="material-symbols-outlined">add</span>
                新對話
              </button>
            </div>

            <div v-if="loadingThreads" class="loading-box">載入中...</div>
            <div v-else-if="threads.length === 0" class="empty-threads">
              <span class="material-symbols-outlined empty-icon">chat_bubble_outline</span>
              <p>{{ superAdmin ? '尚無聊天記錄' : '此分校尚無聊天記錄' }}</p>
              <p class="hint">{{ superAdmin ? '按「新對話」開始。' : '按「新對話」開始，或切換左上角分校查看其他校區的對話。' }}</p>
            </div>
            <div v-else class="thread-list" data-guide="chat-thread-list">
              <div
                v-for="t in threads"
                :key="t.id"
                class="thread-item"
                :class="{ active: activeThread?.id === t.id }"
                @click="selectThread(t)"
                @contextmenu.prevent="openThreadContextMenu($event, t)"
                @touchstart="onThreadTouchStart($event, t)"
                @touchend="onThreadTouchEnd"
                @touchmove="cancelLongPress"
              >
                <div class="thread-avatar">
                  <img v-if="threadListAvatarUrl(t)" :src="threadListAvatarUrl(t)" alt="" class="thread-avatar-img" />
                  <span v-else class="material-symbols-outlined">{{ t.type === 'group' ? 'groups' : 'person' }}</span>
                </div>
                <div class="thread-info">
                  <div class="thread-name">{{ t.name || '(未命名)' }}</div>
                  <div class="thread-preview" v-if="t.last_message">
                    {{ t.last_message.sender_name }}：{{ t.last_message.body }}
                  </div>
                </div>
                <div class="thread-meta">
                  <span v-if="t.is_pinned" class="pin-icon material-symbols-outlined" title="已釘選">push_pin</span>
                  <div class="thread-time" v-if="t.last_message">{{ formatTime(t.last_message.created_at) }}</div>
                  <span v-if="t.unread_count > 0" class="unread-badge">{{ t.unread_count > 99 ? '99+' : t.unread_count }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Messages (right panel) -->
          <div class="message-panel" :class="{ 'hidden-mobile': !activeThread }" data-guide="chat-message-area">
            <div v-if="!activeThread" class="no-thread-selected">
              <span class="material-symbols-outlined big-icon">forum</span>
              <p>選擇一個對話或建立新對話</p>
            </div>
            <template v-else>
              <div class="message-header">
                <button class="btn-back-mobile" @click="activeThread = null">
                  <span class="material-symbols-outlined">arrow_back</span>
                </button>
                <div class="message-header-avatar thread-avatar">
                  <img v-if="threadListAvatarUrl(activeThread)" :src="threadListAvatarUrl(activeThread)" alt="" class="thread-avatar-img" />
                  <span v-else class="material-symbols-outlined">{{ activeThread.type === 'group' ? 'groups' : 'person' }}</span>
                </div>
                <span class="message-header-name">{{ activeThread.name }}</span>
                <!-- Group settings button -->
                <button
                  v-if="activeThread.type === 'group'"
                  class="btn-header-action"
                  @click="openGroupInfo"
                  title="群組設定"
                >
                  <span class="material-symbols-outlined">settings</span>
                </button>
                <!-- DM delete button -->
                <button
                  v-else
                  class="btn-header-action btn-danger-text"
                  @click="confirmDeleteThread = true"
                  title="刪除對話"
                >
                  <span class="material-symbols-outlined">delete</span>
                </button>
              </div>

              <div class="message-list" ref="messageListEl" @scroll="onMessageScroll">
                <div v-if="loadingMessages" class="loading-box">載入中...</div>
                <div
                  v-for="msg in sortedMessages"
                  :key="msg.id"
                  class="message-row"
                  :class="{ 'own': msg.sender_user_id === myUserId }"
                  @contextmenu.prevent="openMsgContextMenu($event, msg)"
                  @touchstart="onMsgTouchStart($event, msg)"
                  @touchend="onMsgTouchEnd"
                  @touchmove="cancelLongPress"
                >
                  <!-- Peer avatar (left) -->
                  <div v-if="msg.sender_user_id !== myUserId" class="msg-avatar-wrap" aria-hidden="true">
                    <img v-if="messagePeerAvatar(msg)" :src="messagePeerAvatar(msg)" alt="" class="msg-avatar-img" />
                    <span v-else class="material-symbols-outlined msg-avatar-fallback">person</span>
                  </div>

                  <div class="msg-body-col">
                    <div class="msg-sender" v-if="msg.sender_user_id !== myUserId && activeThread.type === 'group'">
                      {{ msg.sender_name }}
                    </div>
                    <div class="msg-bubble" :class="{ 'is-deleted': msg.is_deleted }">
                      <!-- Reply preview -->
                      <div v-if="msg.reply_to" class="reply-preview">
                        <span class="reply-sender">{{ msg.reply_to.sender_name }}</span>
                        <span class="reply-body">{{ msg.reply_to.is_deleted ? '此訊息已刪除' : (msg.reply_to.message_type !== 'text' ? '[附件]' : msg.reply_to.body) }}</span>
                      </div>
                      <!-- Deleted -->
                      <span v-if="msg.is_deleted" class="deleted-label">此訊息已刪除</span>
                      <!-- Image -->
                      <template v-else-if="msg.message_type === 'image'">
                        <img
                          :src="msg.media_url"
                          class="msg-image"
                          alt="圖片"
                          @click="previewImageUrl = msg.media_url"
                        />
                      </template>
                      <!-- File -->
                      <template v-else-if="msg.message_type === 'file'">
                        <a :href="msg.media_url" :download="msg.media_name" class="msg-file-link" target="_blank">
                          <span class="material-symbols-outlined">attach_file</span>
                          {{ msg.media_name }}
                        </a>
                      </template>
                      <!-- Text -->
                      <template v-else>{{ msg.body }}</template>
                    </div>
                    <div class="msg-time">{{ formatTime(msg.created_at) }}</div>
                  </div>

                  <!-- Own avatar (right) -->
                  <div v-if="msg.sender_user_id === myUserId" class="msg-avatar-wrap own" aria-hidden="true">
                    <img v-if="myAvatarDisplay" :src="myAvatarDisplay" alt="" class="msg-avatar-img" />
                    <span v-else class="material-symbols-outlined msg-avatar-fallback">person</span>
                  </div>
                </div>
              </div>

              <!-- Reply bar -->
              <div v-if="replyingTo" class="reply-bar">
                <span class="reply-bar-icon material-symbols-outlined">reply</span>
                <div class="reply-bar-content">
                  <span class="reply-bar-sender">回覆 {{ replyingTo.sender_name }}</span>
                  <span class="reply-bar-body">{{ replyingTo.is_deleted ? '此訊息已刪除' : (replyingTo.message_type !== 'text' ? '[附件]' : replyingTo.body) }}</span>
                </div>
                <button class="reply-bar-close" @click="replyingTo = null">
                  <span class="material-symbols-outlined">close</span>
                </button>
              </div>

              <div class="message-input-bar">
                <!-- Attach button -->
                <button class="btn-attach" @click="fileInput.value?.click()" title="傳送附件">
                  <span class="material-symbols-outlined">attach_file</span>
                </button>
                <input
                  ref="fileInput"
                  type="file"
                  style="display:none"
                  accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.doc,.docx,.xls,.xlsx"
                  @change="handleFileSelect"
                />
                <input
                  v-model="newMessage"
                  @keydown.enter.prevent="doSend"
                  placeholder="輸入訊息..."
                  class="msg-input"
                  :disabled="sending || uploadingAttachment"
                />
                <button class="btn-send" @click="doSend" :disabled="!newMessage.trim() || sending || uploadingAttachment">
                  <span class="material-symbols-outlined">send</span>
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </template>

    <!-- ── New Chat Dialog ──────────────────────────────────────── -->
    <div v-if="showNewChat" class="modal-overlay" @click.self="showNewChat = false">
      <div class="modal-card">
        <h3>新對話</h3>
        <div class="new-chat-tabs">
          <button :class="{ active: newChatTab === 'dm' }" @click="newChatTab = 'dm'">私訊</button>
          <button :class="{ active: newChatTab === 'group' }" @click="newChatTab = 'group'">群組</button>
        </div>

        <div v-if="newChatTab === 'dm'">
          <label>搜尋對象</label>
          <input
            v-model="dmSearch"
            class="form-input"
            placeholder="輸入名字篩選..."
            autocomplete="off"
          />
          <div class="dm-staff-list">
            <div
              v-for="u in filteredStaffList"
              :key="u.id"
              class="dm-staff-item"
              :class="{ selected: selectedUserId == u.id }"
              @click="selectedUserId = u.id"
            >
              {{ u.display_name }}
            </div>
            <div v-if="filteredStaffList.length === 0" class="dm-staff-empty">找不到符合的人員</div>
          </div>
          <button class="btn-primary" :disabled="!selectedUserId" @click="startDm">開始聊天</button>
        </div>

        <div v-if="newChatTab === 'group'">
          <label>群組名稱</label>
          <input v-model="groupName" class="form-input" placeholder="例：教務群" />
          <label>成員（多選）</label>
          <div class="member-checkboxes">
            <label v-for="u in staffList" :key="u.id" class="checkbox-label">
              <input type="checkbox" :value="u.id" v-model="groupMemberIds" />
              {{ u.display_name }}
            </label>
          </div>
          <button class="btn-primary" :disabled="!groupName.trim() || groupMemberIds.length === 0" @click="startGroup">建立群組</button>
        </div>

        <button class="btn-cancel" @click="showNewChat = false">取消</button>
      </div>
    </div>

    <!-- ── Group Info Dialog ────────────────────────────────────── -->
    <div v-if="showGroupInfo" class="modal-overlay" @click.self="showGroupInfo = false">
      <div class="modal-card">
        <h3>群組設定</h3>

        <!-- Group name -->
        <div class="group-name-section">
          <template v-if="!editingGroupName">
            <span class="group-name-display">{{ activeThread?.name }}</span>
            <button v-if="canManageGroup" class="btn-small" @click="startEditGroupName">修改名稱</button>
          </template>
          <template v-else>
            <input v-model="newGroupNameInput" class="form-input" @keydown.enter.prevent="saveGroupName" />
            <div class="btn-row">
              <button class="btn-primary btn-sm" @click="saveGroupName">儲存</button>
              <button class="btn-cancel btn-sm" @click="editingGroupName = false">取消</button>
            </div>
          </template>
        </div>

        <!-- Members list -->
        <div class="members-section">
          <div class="section-label">成員（{{ groupMembers.length }} 人）</div>
          <div v-if="loadingGroupMembers" class="loading-box">載入中...</div>
          <div v-else class="member-list">
            <div v-for="m in groupMembers" :key="m.id" class="member-row">
              <div class="member-avatar">
                <img v-if="m.avatar_url" :src="m.avatar_url" alt="" class="member-avatar-img" />
                <span v-else class="material-symbols-outlined">person</span>
              </div>
              <span class="member-name">{{ m.name }}</span>
              <span v-if="m.role === 'owner'" class="role-badge">擁有者</span>
              <div class="member-actions">
                <!-- Transfer owner (only current owner, to non-owner) -->
                <button
                  v-if="myMemberRole === 'owner' && m.id !== myUserId && m.role !== 'owner'"
                  class="btn-small btn-link"
                  @click="doTransferOwner(m.id)"
                >設為擁有者</button>
                <!-- Remove (canManageGroup, not removing owner) -->
                <button
                  v-if="canManageGroup && m.role !== 'owner' && m.id !== myUserId"
                  class="btn-small btn-danger-outline"
                  @click="doRemoveMember(m.id)"
                >移除</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Add members -->
        <div v-if="canManageGroup && availableToAdd.length > 0" class="add-members-section">
          <div class="section-label">新增成員</div>
          <div class="member-checkboxes">
            <label v-for="u in availableToAdd" :key="u.id" class="checkbox-label">
              <input type="checkbox" :value="u.id" v-model="addMemberIds" />
              {{ u.display_name }}
            </label>
          </div>
          <button
            class="btn-primary"
            :disabled="addMemberIds.length === 0"
            @click="doAddMembers"
          >新增成員</button>
        </div>

        <div class="group-danger-zone">
          <!-- Leave group -->
          <button class="btn-danger-outline" @click="doLeaveThread">
            <span class="material-symbols-outlined">logout</span>
            離開群組
          </button>
          <!-- Delete group (owner or director) -->
          <button v-if="canDeleteThread" class="btn-danger" @click="confirmDeleteThread = true; showGroupInfo = false">
            <span class="material-symbols-outlined">delete_forever</span>
            刪除群組
          </button>
        </div>

        <button class="btn-cancel" @click="showGroupInfo = false">關閉</button>
      </div>
    </div>

    <!-- ── Confirm delete thread dialog ─────────────────────────── -->
    <div v-if="confirmDeleteThread" class="modal-overlay" @click.self="confirmDeleteThread = false">
      <div class="modal-card modal-card-sm">
        <h3>確認刪除</h3>
        <p>{{ activeThread?.type === 'group' ? '確定要刪除此群組嗎？所有訊息將永久移除。' : '確定要刪除此對話嗎？所有訊息將永久移除。' }}</p>
        <div class="btn-row">
          <button class="btn-danger" @click="doDeleteThread">確認刪除</button>
          <button class="btn-cancel" @click="confirmDeleteThread = false">取消</button>
        </div>
      </div>
    </div>

    <!-- ── Message context menu ──────────────────────────────────── -->
    <div
      v-if="contextMenu.show"
      class="context-menu"
      :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
      @click.stop
    >
      <button class="context-menu-item" @click="doReply(contextMenu.msg)">
        <span class="material-symbols-outlined">reply</span> 回覆
      </button>
      <button
        v-if="contextMenu.msg?.sender_user_id === myUserId && !contextMenu.msg?.is_deleted"
        class="context-menu-item context-menu-danger"
        @click="doDeleteMessage(contextMenu.msg)"
      >
        <span class="material-symbols-outlined">delete</span> 刪除訊息
      </button>
    </div>

    <!-- ── Thread context menu ───────────────────────────────────── -->
    <div
      v-if="threadContextMenu.show"
      class="context-menu"
      :style="{ top: threadContextMenu.y + 'px', left: threadContextMenu.x + 'px' }"
      @click.stop
    >
      <button class="context-menu-item" @click="togglePin(threadContextMenu.thread)">
        <span class="material-symbols-outlined">{{ threadContextMenu.thread?.is_pinned ? 'keep_off' : 'push_pin' }}</span>
        {{ threadContextMenu.thread?.is_pinned ? '取消釘選' : '釘選對話' }}
      </button>
      <button class="context-menu-item context-menu-danger" @click="startDeleteFromThreadMenu">
        <span class="material-symbols-outlined">delete</span> 刪除對話
      </button>
    </div>

    <!-- ── Image lightbox ────────────────────────────────────────── -->
    <div v-if="previewImageUrl" class="lightbox" @click="previewImageUrl = null">
      <img :src="previewImageUrl" alt="圖片預覽" class="lightbox-img" />
      <button class="lightbox-close" @click="previewImageUrl = null">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import {
  fetchThreads, createDm, createGroup, fetchMessages,
  sendMessage, markThreadRead, fetchStaffList,
  deleteMessage, deleteThread, uploadAttachment,
  fetchThreadMembers, updateThreadName, addThreadMembers,
  removeThreadMember, leaveThread, transferOwner, pinThread,
} from '../lib/chatApi';
import { initEcho, subscribeThread, unsubscribeThread, unsubscribeAll } from '../lib/chatRealtime';

const props = defineProps({
  branchId:   { type: [Number, String], default: null },
  userId:     { type: [Number, String], default: null },
  avatarUrl:  { type: String, default: '' },
  superAdmin: { type: Boolean, default: false },
  userRole:   { type: String, default: 'teacher' },
});

// ── Thread state ────────────────────────────────────────────────
const threads          = ref([]);
const activeThread     = ref(null);
const messages         = ref([]);
const newMessage       = ref('');
const sending          = ref(false);
const loadingThreads   = ref(false);
const threadsLoadError = ref('');
const loadingMessages  = ref(false);

// ── New chat dialog ─────────────────────────────────────────────
const showNewChat    = ref(false);
const newChatTab     = ref('dm');
const selectedUserId = ref('');
const groupName      = ref('');
const groupMemberIds = ref([]);
const staffList      = ref([]);
const dmSearch       = ref('');

// ── Message context menu (right-click / long-press) ─────────────
const contextMenu = ref({ show: false, msg: null, x: 0, y: 0 });

// ── Thread context menu ─────────────────────────────────────────
const threadContextMenu = ref({ show: false, thread: null, x: 0, y: 0 });

// ── Reply state ─────────────────────────────────────────────────
const replyingTo = ref(null); // { id, sender_name, body, message_type, is_deleted }

// ── Group info dialog ───────────────────────────────────────────
const showGroupInfo       = ref(false);
const groupMembers        = ref([]);
const loadingGroupMembers = ref(false);
const editingGroupName    = ref(false);
const newGroupNameInput   = ref('');
const addMemberIds        = ref([]);

// ── Confirm delete ──────────────────────────────────────────────
const confirmDeleteThread     = ref(false);
const pendingDeleteThread     = ref(null); // thread to delete (from thread list context menu)

// ── Attachment ──────────────────────────────────────────────────
const fileInput           = ref(null);
const uploadingAttachment = ref(false);

// ── Image lightbox ──────────────────────────────────────────────
const previewImageUrl = ref(null);

// ── Scroll ref ──────────────────────────────────────────────────
const messageListEl = ref(null);

let threadPollTimer = null;
let msgPollTimer    = null;
let longPressTimer  = null;

// ── Computed ────────────────────────────────────────────────────

const sortedMessages = computed(() => [...messages.value].sort((a, b) => a.id - b.id));

const myUserId = computed(() => props.userId ? Number(props.userId) : null);

const myAvatarDisplay = computed(() => (props.avatarUrl && String(props.avatarUrl).trim()) || '');

const myMemberRole = computed(() => activeThread.value?.member_role ?? 'member');

const canManageGroup = computed(() => {
  if (!activeThread.value || activeThread.value.type !== 'group') return false;
  return myMemberRole.value === 'owner' || props.userRole === 'director' || props.superAdmin;
});

const canDeleteThread = computed(() => {
  if (!activeThread.value) return false;
  if (activeThread.value.type === 'dm') return true;
  return myMemberRole.value === 'owner' || props.userRole === 'director' || props.superAdmin;
});

const availableToAdd = computed(() => {
  const existingIds = new Set(groupMembers.value.map(m => m.id));
  return staffList.value.filter(u => !existingIds.has(Number(u.id)));
});

const filteredStaffList = computed(() => {
  const q = dmSearch.value.trim().toLowerCase();
  if (!q) return staffList.value;
  return staffList.value.filter(u => u.display_name.toLowerCase().includes(q));
});

// ── Avatar helpers ───────────────────────────────────────────────

function threadListAvatarUrl(t) {
  if (!t || t.type === 'group') return '';
  return t.thread_avatar_url || t.other_members?.[0]?.avatar_url || '';
}

function messagePeerAvatar(msg) {
  if (!msg) return '';
  return msg.sender_avatar_url || threadListAvatarUrl(activeThread.value) || '';
}

// ── Lifecycle ────────────────────────────────────────────────────

onMounted(async () => {
  await loadThreads();
  await loadStaff();
  initEcho();
  threadPollTimer = setInterval(loadThreads, 8000);
});

onBeforeUnmount(() => {
  unsubscribeAll();
  if (threadPollTimer) clearInterval(threadPollTimer);
  if (msgPollTimer)    clearInterval(msgPollTimer);
  if (longPressTimer)  clearTimeout(longPressTimer);
});

watch(() => props.branchId, () => {
  threadsLoadError.value = '';
  activeThread.value     = null;
  messages.value         = [];
  replyingTo.value       = null;
  unsubscribeAll();
  loadThreads();
  loadStaff();
});

// ── Data loading ─────────────────────────────────────────────────

async function loadThreads() {
  if (!props.branchId) return;
  try {
    loadingThreads.value   = threads.value.length === 0;
    threadsLoadError.value = '';
    const data             = await fetchThreads(props.branchId);
    threads.value          = data.data || [];
  } catch (e) {
    console.error('[Chat] loadThreads:', e);
    threadsLoadError.value = e?.message || '無法載入聊天列表（請確認已登入、已選分校，或稍後再試）。';
    threads.value          = [];
  } finally {
    loadingThreads.value = false;
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
    }
  }
}

async function loadStaff() {
  try {
    const data = await fetchStaffList(null); // load all branches so cross-branch DM works
    const raw  = Array.isArray(data) ? data : data.data || [];
    staffList.value = raw
      .map((u) => ({
        ...u,
        display_name: u.username || u.Name || u.name || u.account || u.email || `User#${u.id}`,
      }))
      .filter((u) => Number(u.id) !== myUserId.value);
  } catch (e) {
    console.error('[Chat] loadStaff:', e);
  }
}

// ── Thread selection ─────────────────────────────────────────────

async function selectThread(t) {
  if (activeThread.value?.id === t.id) return;

  if (activeThread.value) {
    unsubscribeThread(activeThread.value.id);
    if (msgPollTimer) { clearInterval(msgPollTimer); msgPollTimer = null; }
  }

  activeThread.value   = t;
  messages.value       = [];
  replyingTo.value     = null;
  loadingMessages.value = true;

  try {
    const data    = await fetchMessages(t.id);
    messages.value = data.data || [];
    await nextTick();
    scrollToBottom();
    if (t.unread_count > 0) {
      await markThreadRead(t.id);
      t.unread_count = 0;
    }
  } catch (e) {
    console.error('[Chat] loadMessages:', e);
  } finally {
    loadingMessages.value = false;
  }

  subscribeThread(t.id, (msg) => {
    if (!messages.value.find(m => m.id === msg.id)) {
      // If it's an update to an existing message (delete), replace it
      const idx = messages.value.findIndex(m => m.id === msg.id);
      if (idx >= 0) {
        messages.value[idx] = msg;
      } else {
        messages.value.push(msg);
        nextTick(() => scrollToBottom());
        markThreadRead(t.id, msg.id);
      }
    } else {
      // Update in place (e.g. soft-delete broadcast)
      const idx = messages.value.findIndex(m => m.id === msg.id);
      if (idx >= 0) messages.value[idx] = msg;
    }
  }, () => pollNewMessages(t.id));
}

async function pollNewMessages(threadId) {
  if (!activeThread.value || activeThread.value.id !== threadId) return;
  try {
    const data    = await fetchMessages(threadId, null, 20);
    const newMsgs = (data.data || []).filter(m => !messages.value.find(em => em.id === m.id));
    if (newMsgs.length) {
      messages.value.push(...newMsgs);
      nextTick(() => scrollToBottom());
      const maxId = Math.max(...newMsgs.map(m => m.id));
      markThreadRead(threadId, maxId);
    }
  } catch (e) { /* ignore polling errors */ }
}

// ── Send / upload ────────────────────────────────────────────────

async function doSend() {
  if (!newMessage.value.trim() || !activeThread.value || sending.value) return;
  sending.value = true;
  const replyId = replyingTo.value?.id ?? null;
  try {
    const msg = await sendMessage(activeThread.value.id, newMessage.value.trim(), replyId);
    messages.value.push(msg);
    newMessage.value = '';
    replyingTo.value = null;
    await nextTick();
    scrollToBottom();
    loadThreads();
  } catch (e) {
    console.error('[Chat] sendMessage:', e);
  } finally {
    sending.value = false;
  }
}

async function handleFileSelect(event) {
  const file = event.target.files?.[0];
  if (!file || !activeThread.value) return;

  // Reset input so same file can be selected again
  event.target.value = '';

  uploadingAttachment.value = true;
  try {
    const msg = await uploadAttachment(activeThread.value.id, file);
    messages.value.push(msg);
    await nextTick();
    scrollToBottom();
    loadThreads();
  } catch (e) {
    console.error('[Chat] uploadAttachment:', e);
    alert('上傳失敗：' + (e?.message || '未知錯誤'));
  } finally {
    uploadingAttachment.value = false;
  }
}

// ── New thread ───────────────────────────────────────────────────

async function startDm() {
  if (!selectedUserId.value) return;
  try {
    const thread = await createDm(props.branchId, Number(selectedUserId.value));
    showNewChat.value    = false;
    selectedUserId.value = '';
    await loadThreads();
    const found = threads.value.find(t => t.id === thread.id);
    if (found) selectThread(found);
  } catch (e) {
    console.error('[Chat] createDm:', e);
  }
}

async function startGroup() {
  if (!groupName.value.trim() || groupMemberIds.value.length === 0) return;
  try {
    const thread = await createGroup(props.branchId, groupName.value.trim(), groupMemberIds.value);
    showNewChat.value    = false;
    groupName.value      = '';
    groupMemberIds.value = [];
    await loadThreads();
    const found = threads.value.find(t => t.id === thread.id);
    if (found) selectThread(found);
  } catch (e) {
    console.error('[Chat] createGroup:', e);
  }
}

// ── Delete ────────────────────────────────────────────────────────

async function doDeleteMessage(msg) {
  closeContextMenu();
  if (!msg || msg.is_deleted) return;
  try {
    const res = await deleteMessage(msg.id);
    const idx = messages.value.findIndex(m => m.id === msg.id);
    if (idx >= 0 && res.message) {
      messages.value[idx] = res.message;
    }
    loadThreads();
  } catch (e) {
    console.error('[Chat] deleteMessage:', e);
    alert('刪除失敗：' + (e?.message || '未知錯誤'));
  }
}

async function doDeleteThread() {
  const target = pendingDeleteThread.value ?? activeThread.value;
  if (!target) return;
  confirmDeleteThread.value  = false;
  pendingDeleteThread.value  = null;
  try {
    await deleteThread(target.id);
    threads.value = threads.value.filter(t => t.id !== target.id);
    if (activeThread.value?.id === target.id) {
      activeThread.value = null;
      messages.value     = [];
    }
  } catch (e) {
    console.error('[Chat] deleteThread:', e);
    alert('刪除失敗：' + (e?.message || '未知錯誤'));
  }
}

// ── Reply ─────────────────────────────────────────────────────────

function doReply(msg) {
  closeContextMenu();
  if (!msg || msg.is_deleted) return;
  replyingTo.value = {
    id:           msg.id,
    sender_name:  msg.sender_name,
    body:         msg.body,
    message_type: msg.message_type,
    is_deleted:   msg.is_deleted,
  };
}

// ── Context menus ─────────────────────────────────────────────────

function openMsgContextMenu(event, msg) {
  if (msg.is_deleted) return;
  contextMenu.value = {
    show: true,
    msg,
    x: Math.min(event.clientX, window.innerWidth - 160),
    y: Math.min(event.clientY, window.innerHeight - 100),
  };
  threadContextMenu.value.show = false;
}

function openThreadContextMenu(event, t) {
  threadContextMenu.value = {
    show: true,
    thread: t,
    x: Math.min(event.clientX, window.innerWidth - 160),
    y: Math.min(event.clientY, window.innerHeight - 60),
  };
  contextMenu.value.show = false;
}

function closeContextMenu() {
  contextMenu.value.show       = false;
  threadContextMenu.value.show = false;
}

function startDeleteFromThreadMenu() {
  pendingDeleteThread.value    = threadContextMenu.value.thread;
  confirmDeleteThread.value    = true;
  threadContextMenu.value.show = false;
}

// ── Long-press (mobile) ───────────────────────────────────────────

function onMsgTouchStart(event, msg) {
  longPressTimer = setTimeout(() => {
    const touch = event.touches[0];
    openMsgContextMenu({ clientX: touch.clientX, clientY: touch.clientY }, msg);
  }, 500);
}

function onMsgTouchEnd() {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
}

function onThreadTouchStart(event, t) {
  longPressTimer = setTimeout(() => {
    const touch = event.touches[0];
    openThreadContextMenu({ clientX: touch.clientX, clientY: touch.clientY }, t);
  }, 500);
}

function onThreadTouchEnd() {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
}

function cancelLongPress() {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
}

// ── Group management ──────────────────────────────────────────────

async function openGroupInfo() {
  showGroupInfo.value       = true;
  loadingGroupMembers.value = true;
  addMemberIds.value        = [];
  editingGroupName.value    = false;
  try {
    const res        = await fetchThreadMembers(activeThread.value.id);
    groupMembers.value = res.data || [];
  } catch (e) {
    console.error('[Chat] fetchMembers:', e);
  } finally {
    loadingGroupMembers.value = false;
  }
}

function startEditGroupName() {
  newGroupNameInput.value = activeThread.value?.name || '';
  editingGroupName.value  = true;
}

async function saveGroupName() {
  const name = newGroupNameInput.value.trim();
  if (!name || !activeThread.value) return;
  try {
    await updateThreadName(activeThread.value.id, name);
    activeThread.value.name = name;
    const t = threads.value.find(t => t.id === activeThread.value.id);
    if (t) t.name = name;
    editingGroupName.value = false;
  } catch (e) {
    console.error('[Chat] updateThreadName:', e);
    alert('修改失敗：' + (e?.message || '未知錯誤'));
  }
}

async function doAddMembers() {
  if (!addMemberIds.value.length || !activeThread.value) return;
  try {
    await addThreadMembers(activeThread.value.id, addMemberIds.value);
    addMemberIds.value = [];
    // Refresh member list
    const res          = await fetchThreadMembers(activeThread.value.id);
    groupMembers.value = res.data || [];
  } catch (e) {
    console.error('[Chat] addMembers:', e);
    alert('新增失敗：' + (e?.message || '未知錯誤'));
  }
}

async function doRemoveMember(targetId) {
  if (!activeThread.value) return;
  try {
    await removeThreadMember(activeThread.value.id, targetId);
    groupMembers.value = groupMembers.value.filter(m => m.id !== targetId);
  } catch (e) {
    console.error('[Chat] removeMember:', e);
    alert('移除失敗：' + (e?.message || '未知錯誤'));
  }
}

async function doTransferOwner(newOwnerId) {
  if (!activeThread.value) return;
  try {
    await transferOwner(activeThread.value.id, newOwnerId);
    groupMembers.value = groupMembers.value.map(m => ({
      ...m,
      role: m.id === newOwnerId ? 'owner' : (m.id === myUserId.value ? 'member' : m.role),
    }));
    // Update activeThread.member_role
    if (activeThread.value) {
      activeThread.value = {
        ...activeThread.value,
        member_role: 'member',
      };
    }
  } catch (e) {
    console.error('[Chat] transferOwner:', e);
    alert('轉讓失敗：' + (e?.message || '未知錯誤'));
  }
}

async function doLeaveThread() {
  if (!activeThread.value) return;
  showGroupInfo.value = false;
  try {
    await leaveThread(activeThread.value.id);
    const leftId      = activeThread.value.id;
    activeThread.value = null;
    messages.value     = [];
    threads.value      = threads.value.filter(t => t.id !== leftId);
  } catch (e) {
    console.error('[Chat] leaveThread:', e);
    alert('離開失敗：' + (e?.message || '未知錯誤'));
  }
}

// ── Pin ──────────────────────────────────────────────────────────

async function togglePin(t) {
  if (!t) return;
  closeContextMenu();
  const newPinned = !t.is_pinned;
  try {
    await pinThread(t.id, newPinned);
    t.is_pinned = newPinned;
    threads.value = [
      ...threads.value.filter(x => x.is_pinned),
      ...threads.value.filter(x => !x.is_pinned),
    ];
  } catch (e) {
    console.error('[Chat] pinThread:', e);
    alert('釘選失敗：' + (e?.message || '未知錯誤'));
  }
}

// ── Scroll ───────────────────────────────────────────────────────

function scrollToBottom() {
  const el = messageListEl.value;
  if (el) el.scrollTop = el.scrollHeight;
}

function onMessageScroll() {
  // Placeholder for future infinite scroll (load older messages)
}

// ── Time format ──────────────────────────────────────────────────

function formatTime(iso) {
  if (!iso) return '';
  const d   = new Date(iso);
  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  if (isToday) {
    return d.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' });
  }
  return d.toLocaleDateString('zh-TW', { month: 'numeric', day: 'numeric' }) +
    ' ' + d.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.chat-page { height: calc(100vh - 80px); display: flex; flex-direction: column; }
.page-header { padding: 16px 24px 8px; flex-shrink: 0; }
.page-header h2 { display: flex; align-items: center; gap: 8px; margin: 0; }
.header-icon { font-size: 28px; color: var(--primary); }
.page-desc { color: var(--text-light); font-size: 14px; margin-top: 2px; }
.page-desc-sub { color: var(--text-light); font-size: 13px; margin-top: 6px; max-width: 720px; line-height: 1.45; }
.empty-card { margin: 24px; padding: 32px; text-align: center; }

.chat-container {
  flex: 1; display: flex; flex-direction: column; margin: 0 16px 16px; gap: 0;
  background: var(--card-bg); border-radius: var(--radius);
  box-shadow: var(--shadow); overflow: hidden; min-height: 0;
}
.chat-inline-alert {
  flex-shrink: 0; padding: 10px 14px; font-size: 13px;
  background: #fff3cd; color: #664d03; border-bottom: 1px solid #e6d89c;
}
.chat-panels-row {
  flex: 1; display: flex; min-height: 0; overflow: hidden;
}

/* Thread panel */
.thread-panel {
  width: 320px; min-width: 280px; border-right: 1px solid var(--border);
  display: flex; flex-direction: column; overflow: hidden;
}
.thread-panel-header {
  padding: 12px; border-bottom: 1px solid var(--border);
  display: flex; justify-content: flex-end;
}
.btn-new-chat {
  display: flex; align-items: center; gap: 4px;
  padding: 6px 14px; border: none; border-radius: 8px;
  background: var(--primary); color: #fff; cursor: pointer;
  font-size: 13px; font-weight: 500;
}
.btn-new-chat:hover { background: var(--accent-hover); }

.thread-list { flex: 1; overflow-y: auto; }
.thread-item {
  display: flex; align-items: center; gap: 10px;
  padding: 12px; cursor: pointer; border-bottom: 1px solid var(--border);
  transition: background 0.15s;
}
.thread-item:hover { background: var(--primary-bg); }
.thread-item.active { background: var(--primary-bg); border-left: 3px solid var(--primary); }

.thread-avatar {
  width: 40px; height: 40px; border-radius: 50%;
  background: var(--primary-bg); display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.thread-avatar .material-symbols-outlined { color: var(--primary); font-size: 22px; }
.thread-avatar-img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%; }
.message-header-avatar { width: 36px; height: 36px; }

.thread-info { flex: 1; min-width: 0; }
.thread-name { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.thread-preview { font-size: 12px; color: var(--text-light); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }

.thread-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.thread-time { font-size: 11px; color: var(--text-light); }
.unread-badge {
  background: var(--danger); color: #fff; font-size: 11px; font-weight: 700;
  min-width: 20px; height: 20px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; padding: 0 5px;
}

.empty-threads { text-align: center; padding: 48px 16px; color: var(--text-light); }
.empty-icon { font-size: 48px; opacity: 0.4; }
.empty-threads .hint { font-size: 13px; margin-top: 4px; }

/* Message panel */
.message-panel { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.no-thread-selected {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  color: var(--text-light);
}
.big-icon { font-size: 64px; opacity: 0.25; margin-bottom: 12px; }

.message-header {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 16px; border-bottom: 1px solid var(--border);
  font-weight: 600; font-size: 15px;
}
.btn-back-mobile { display: none; background: none; border: none; cursor: pointer; }
.message-header-name { flex: 1; }
.btn-header-action {
  background: none; border: none; cursor: pointer;
  color: var(--text-light); padding: 4px; border-radius: 6px;
}
.btn-header-action:hover { background: var(--primary-bg); color: var(--primary); }
.btn-danger-text:hover { color: var(--danger); background: #fee2e2; }

.message-list {
  flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px;
}
.message-row {
  display: flex; flex-direction: row; align-items: flex-end; gap: 8px;
  max-width: min(85%, 520px);
}
.message-row.own {
  align-self: flex-end; flex-direction: row-reverse;
}

.msg-avatar-wrap {
  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
  background: var(--primary-bg); display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.msg-avatar-wrap.own { background: var(--primary-bg); }
.msg-avatar-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.msg-avatar-fallback { font-size: 18px; color: var(--primary); }

.msg-body-col { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.message-row.own .msg-body-col { align-items: flex-end; }

.msg-sender { font-size: 11px; color: var(--text-light); margin-bottom: 2px; padding-left: 12px; }
.msg-bubble {
  background: #f0f0f0; padding: 8px 14px; border-radius: 16px;
  font-size: 14px; line-height: 1.5; word-break: break-word;
  max-width: 100%;
}
.message-row.own .msg-bubble { background: var(--primary); color: #fff; }
.msg-bubble.is-deleted { background: #f5f5f5; color: #aaa; font-style: italic; }
.message-row.own .msg-bubble.is-deleted { background: rgba(255,255,255,0.2); color: rgba(255,255,255,0.6); }

/* Reply preview inside bubble */
.reply-preview {
  border-left: 3px solid rgba(0,0,0,0.2); padding: 4px 8px; margin-bottom: 6px;
  border-radius: 4px; background: rgba(0,0,0,0.06);
  font-size: 12px;
}
.message-row.own .reply-preview { border-color: rgba(255,255,255,0.4); background: rgba(255,255,255,0.12); }
.reply-sender { font-weight: 600; display: block; margin-bottom: 2px; }
.reply-body { color: inherit; opacity: 0.8; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }

/* Image / file in bubble */
.msg-image {
  max-width: 220px; max-height: 200px; border-radius: 8px;
  display: block; cursor: pointer; object-fit: cover;
}
.msg-file-link {
  display: flex; align-items: center; gap: 6px;
  color: inherit; text-decoration: none; font-size: 13px;
}
.msg-file-link:hover { text-decoration: underline; }
.msg-file-link .material-symbols-outlined { font-size: 18px; }

.msg-time { font-size: 10px; color: var(--text-light); margin-top: 2px; padding: 0 12px; }
.message-row.own .msg-time { text-align: right; }

/* Reply bar */
.reply-bar {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 16px; background: var(--primary-bg); border-top: 1px solid var(--border);
  font-size: 13px;
}
.reply-bar-icon { font-size: 18px; color: var(--primary); flex-shrink: 0; }
.reply-bar-content { flex: 1; min-width: 0; }
.reply-bar-sender { font-weight: 600; color: var(--primary); display: block; }
.reply-bar-body { color: var(--text-light); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
.reply-bar-close { background: none; border: none; cursor: pointer; color: var(--text-light); padding: 2px; }
.reply-bar-close:hover { color: var(--danger); }

.message-input-bar {
  display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--border);
  align-items: center;
}
.btn-attach {
  background: none; border: none; cursor: pointer; color: var(--text-light);
  padding: 6px; border-radius: 50%; display: flex; align-items: center;
  flex-shrink: 0;
}
.btn-attach:hover { background: var(--primary-bg); color: var(--primary); }
.msg-input {
  flex: 1; padding: 10px 16px; border: 1px solid var(--border);
  border-radius: 24px; font-size: 14px; outline: none;
  font-family: inherit;
}
.msg-input:focus { border-color: var(--primary); }
.btn-send {
  width: 44px; height: 44px; border: none; border-radius: 50%;
  background: var(--primary); color: #fff; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.btn-send:disabled { opacity: 0.4; cursor: not-allowed; }

.loading-box { text-align: center; padding: 24px; color: var(--text-light); }

/* Context menu */
.context-menu {
  position: fixed; z-index: 2000;
  background: var(--card-bg); border: 1px solid var(--border);
  border-radius: 10px; box-shadow: var(--shadow-hover);
  min-width: 140px; overflow: hidden;
}
.context-menu-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 10px 14px; border: none; background: none;
  cursor: pointer; font-size: 14px; text-align: left;
}
.context-menu-item:hover { background: var(--primary-bg); }
.context-menu-danger { color: var(--danger); }
.context-menu-danger:hover { background: #fee2e2; }
.context-menu-item .material-symbols-outlined { font-size: 18px; }

/* Modal */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.modal-card {
  background: var(--card-bg); border-radius: var(--radius); padding: 24px;
  width: 420px; max-width: 90vw; max-height: 80vh; overflow-y: auto;
  box-shadow: var(--shadow-hover);
}
.modal-card-sm { width: 320px; }
.modal-card h3 { margin: 0 0 16px; }
.new-chat-tabs { display: flex; gap: 8px; margin-bottom: 16px; }
.new-chat-tabs button {
  flex: 1; padding: 8px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--card-bg); cursor: pointer; font-size: 14px;
}
.new-chat-tabs button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.form-select, .form-input {
  width: 100%; padding: 8px 12px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 14px; margin: 4px 0 12px; box-sizing: border-box;
}
.member-checkboxes {
  max-height: 200px; overflow-y: auto; border: 1px solid var(--border);
  border-radius: 8px; padding: 8px; margin: 4px 0 12px;
}
.checkbox-label { display: block; padding: 4px 0; font-size: 14px; cursor: pointer; }
.btn-primary {
  width: 100%; padding: 10px; border: none; border-radius: 8px;
  background: var(--primary); color: #fff; font-size: 14px; cursor: pointer;
  margin-bottom: 8px;
}
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm { width: auto; padding: 6px 14px; margin-bottom: 0; }
.btn-cancel {
  width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--card-bg); font-size: 14px; cursor: pointer;
}
.btn-small {
  padding: 4px 10px; border: 1px solid var(--border); border-radius: 6px;
  background: var(--card-bg); cursor: pointer; font-size: 12px;
}
.btn-small:hover { background: var(--primary-bg); }
.btn-link {
  background: none; border: none; cursor: pointer; color: var(--primary);
  font-size: 12px; padding: 2px 6px;
}
.btn-link:hover { text-decoration: underline; }
.btn-row { display: flex; gap: 8px; margin-top: 8px; }
.btn-danger {
  padding: 10px 16px; border: none; border-radius: 8px;
  background: var(--danger, #ef4444); color: #fff; font-size: 14px; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
}
.btn-danger:hover { opacity: 0.9; }
.btn-danger-outline {
  padding: 8px 14px; border: 1px solid var(--danger, #ef4444); border-radius: 8px;
  background: none; color: var(--danger, #ef4444); font-size: 13px; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
}
.btn-danger-outline:hover { background: #fee2e2; }

/* Group info modal */
.group-name-section { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.group-name-display { font-weight: 600; font-size: 16px; flex: 1; }
.section-label { font-size: 12px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.members-section { margin-bottom: 16px; }
.member-list { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; max-height: 200px; overflow-y: auto; }
.member-row {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 12px; border-bottom: 1px solid var(--border);
}
.member-row:last-child { border-bottom: none; }
.member-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--primary-bg); display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; overflow: hidden;
}
.member-avatar-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.member-avatar .material-symbols-outlined { font-size: 18px; color: var(--primary); }
.member-name { flex: 1; font-size: 14px; }
.role-badge {
  padding: 2px 8px; border-radius: 10px; font-size: 11px;
  background: var(--primary-bg); color: var(--primary); font-weight: 600;
}
.member-actions { display: flex; gap: 4px; align-items: center; }
.add-members-section { margin-bottom: 16px; }
.group-danger-zone { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0; padding-top: 16px; border-top: 1px solid var(--border); }

/* DM staff picker */
.dm-staff-list {
  max-height: 220px; overflow-y: auto; border: 1px solid var(--border);
  border-radius: 8px; margin: 4px 0 12px;
}
.dm-staff-item {
  padding: 10px 14px; font-size: 14px; cursor: pointer;
  border-bottom: 1px solid var(--border);
  transition: background 0.12s;
}
.dm-staff-item:last-child { border-bottom: none; }
.dm-staff-item:hover { background: var(--primary-bg); }
.dm-staff-item.selected { background: var(--primary); color: #fff; }
.dm-staff-empty { padding: 14px; font-size: 13px; color: var(--text-light); text-align: center; }

/* Lightbox */
.lightbox {
  position: fixed; inset: 0; background: rgba(0,0,0,0.85);
  display: flex; align-items: center; justify-content: center;
  z-index: 3000; cursor: zoom-out;
}
.lightbox-img { max-width: 90vw; max-height: 90vh; object-fit: contain; border-radius: 4px; }
.lightbox-close {
  position: absolute; top: 16px; right: 16px;
  background: rgba(255,255,255,0.15); border: none; color: #fff;
  border-radius: 50%; width: 40px; height: 40px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.lightbox-close:hover { background: rgba(255,255,255,0.25); }

/* Mobile responsive */
@media (max-width: 768px) {
  .chat-panels-row { flex-direction: column; }
  .thread-panel { width: 100%; min-width: 0; border-right: none; }
  .thread-panel.hidden-mobile { display: none; }
  .message-panel.hidden-mobile { display: none; }
  .btn-back-mobile { display: block; }
  .message-header-name { flex: 1; }
}
</style>
