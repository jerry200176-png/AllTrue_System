<template>
  <div>
    <div class="card">
      <div class="header-actions">
        <div>
          <h2>📚 費率參考表</h2>
          <p class="ref-hint">此為建立新課程時自動帶入的預設費率，實際收費以學生課程設定為準</p>
        </div>
        <button class="primary" @click="syncRatePer2hFromForm(); showModal = true">+ 新增費率</button>
      </div>

      <table v-if="rates.length">
        <thead>
          <tr>
            <th>年級</th>
            <th>科目</th>
            <th>上課類型</th>
            <th>一堂課預設費率</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="rate in rates" :key="rate.id">
            <td>{{ getGradeLabel(rate.grade) }}</td>
            <td><span class="tag">{{ getSubjectLabel(rate.subject) }}</span></td>
            <td>
              <span class="status-tag" :class="rate.class_type">
                {{ classTypeLabel(rate.class_type) }}
              </span>
            </td>
            <td style="font-weight: 700;">${{ (rate.rate_per_30min || 0) * 4 }}</td>
            <td>
              <button class="small ghost" @click="editRate(rate)">編輯</button>
              <button class="small danger" @click="deleteRate(rate)">刪除</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-else class="empty-text">
        尚未設定任何預設費率，請點擊「+ 新增費率」開始設定
      </div>
    </div>

    <!-- Add/Edit Modal -->
    <div v-if="showModal" class="modal-overlay" @click.self="closeModal">
      <div class="modal">
        <h3>{{ editingId ? '編輯費率' : '新增預設費率' }}</h3>
        <div class="form-group">
          <label>年級</label>
          <select v-model="form.grade">
            <option v-for="g in GRADES" :key="g.value" :value="g.value">{{ g.label }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>科目</label>
          <select v-model="form.subject">
            <option v-for="s in subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>上課類型</label>
          <select v-model="form.class_type">
            <option value="one_on_one">一對一</option>
            <option value="one_on_two">一對二</option>
            <option value="one_on_three">一對三</option>
            <option value="tutoring">輔導</option>
          </select>
        </div>
        <div class="form-group">
          <label>一堂課預設費率 ($)</label>
          <input v-model.number="ratePer2hInput" type="number" placeholder="2000" min="0" step="100" />
        </div>

        <div class="actions">
          <button class="ghost" @click="closeModal">取消</button>
          <button class="primary" @click="submit">儲存</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { supabase } from '../supabase';
import { GRADES, SUBJECTS, getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchSubjectOptions } from '../lib/subjectsApi';

const rates = ref([]);
const showModal = ref(false);
const editingId = ref(null);
const form = ref({ grade: 'J1', subject: 'Math', class_type: 'one_on_one', rate_per_30min: 500 });
const subjectOptions = ref([...SUBJECTS]);
async function loadSubjects() {
  try {
    const opts = await fetchSubjectOptions();
    if (opts.length > 0) subjectOptions.value = opts;
  } catch { /* keep defaults */ }
}
const ratePer2hInput = ref(2000);

function syncRatePer2hFromForm() {
  ratePer2hInput.value = (form.value?.rate_per_30min ?? 0) * 4;
}
function ratePer2hToStore() {
  const v = Number(ratePer2hInput.value);
  if (form.value && !Number.isNaN(v) && v >= 0) {
    form.value.rate_per_30min = Math.round(v / 4) || 0;
  }
}

const getGradeLabel = (val) => GRADES.find(g => g.value === val)?.label || val;
const getSubjectLabel = (val) => getSubjectText(val);
const classTypeLabel = (type) => {
  const map = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' };
  return map[type] || type;
};

const loadRates = async () => {
  const { data } = await supabase.from('default_rates').select('*').order('grade').order('subject');
  rates.value = data || [];
};

const editRate = (rate) => {
  editingId.value = rate.id;
  form.value = { grade: rate.grade, subject: rate.subject, class_type: rate.class_type, rate_per_30min: rate.rate_per_30min };
  syncRatePer2hFromForm();
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  editingId.value = null;
  form.value = { grade: 'J1', subject: 'Math', class_type: 'one_on_one', rate_per_30min: 500 };
  ratePer2hInput.value = 2000;
};

const submit = async () => {
  ratePer2hToStore();
  const rate2h = (form.value?.rate_per_30min ?? 0) * 4;
  if (rate2h <= 0) { alert('請輸入預設一堂課費用'); return; }
  const payload = { ...form.value };
  if (editingId.value) {
    await supabase.from('default_rates').update(payload).eq('id', editingId.value);
  } else {
    await supabase.from('default_rates').insert([payload]);
  }
  closeModal();
  loadRates();
};

const deleteRate = async (rate) => {
  if (!confirm('確定刪除此費率設定？')) return;
  await supabase.from('default_rates').delete().eq('id', rate.id);
  loadRates();
};

onMounted(() => { loadRates(); loadSubjects(); });
</script>

<style scoped>
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 20px;
}

.ref-hint {
  color: var(--text-light);
  font-size: 13px;
  margin-top: 4px;
}

.status-tag.one_on_one { background: #FFF3E0; color: #E65100; }
.status-tag.one_on_two { background: #FFF8E1; color: #F57F17; }
.status-tag.one_on_three { background: #FBE9E7; color: #BF360C; }
.status-tag.tutoring { background: #E8F5E9; color: #2E7D32; }

.cost-preview {
  background: linear-gradient(135deg, #FFF8E1, #FFECB3);
  border: 1px solid #FFE082;
  border-radius: 10px;
  padding: 16px;
  text-align: center;
  margin: 16px 0;
}
.cost-preview-label { font-size: 12px; color: #5D4037; font-weight: 600; }
.cost-preview-value { font-size: 28px; font-weight: 800; color: var(--primary); margin: 4px 0; }
</style>
