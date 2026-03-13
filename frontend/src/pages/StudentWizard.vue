<template>
  <div>
    <HelpGuide
      title="新生報名 — 操作說明"
      :items="[
        '填入學生姓名、年級、分校、手機、就讀學校等基本資料',
        '點擊「建立學生檔案」即可完成報名',
        '建立完成後，學生會出現在「學生管理」列表中'
      ]"
      tip="報名完成後請到「智慧排課」為新生安排第一堂課。"
    />
  <div class="card">
    <h2>✨ 新生報名</h2>
    <div class="grid">
      <div>
        <label>姓名 (Name)</label>
        <input v-model="studentForm.name" />
      </div>
      <div>
        <label>年級 (Grade)</label>
        <select v-model="studentForm.Grade">
            <option v-for="g in GRADES" :key="g.value" :value="g.value">{{ g.label }}</option>
        </select>
      </div>
      <div>
        <label>分校 (Branch)</label>
        <select v-model="studentForm.branch_id">
            <option v-for="b in BRANCHES" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>

      <div>
        <label>就讀學校 (School)</label>
        <input v-model="studentForm.SchoolName" />
      </div>
    </div>
    
    <div class="actions">
      <button class="primary" @click="submitStudent">建立學生檔案</button>
    </div>
  </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { supabase } from '../supabase';
import { BRANCHES, GRADES } from '../lib/constants';
import HelpGuide from '../components/HelpGuide.vue';

const props = defineProps({
    branchId: String
});

const studentForm = ref({
    name: '',
    Grade: 'J1',
    branch_id: props.branchId || BRANCHES[0].id,
    phone: '',
    SchoolName: ''
});

const submitStudent = async () => {
    if (!studentForm.value.name) {
        alert('請輸入姓名');
        return;
    }

    try {
        const { data: { session } } = await supabase.auth.getSession();
        const token = session?.access_token;

        if (!token) {
            alert('無法新增：請重新登入後再試');
            return;
        }

        const body = {
            branch_id: Number(studentForm.value.branch_id || props.branchId),
            name: studentForm.value.name,
            grade: studentForm.value.Grade,
            phone: studentForm.value.phone || '',
            school: studentForm.value.SchoolName || '',
            parent_name: '',
            parent_phone: '',
            notes: '',
            status: 'active'
        };

        const res = await fetch('/api/v1/students', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify(body)
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            const msg =
                err?.message ||
                (err?.errors ? Object.values(err.errors || {}).flat().join(' ') : null) ||
                '新增學生失敗，請稍後再試';
            alert(msg);
            return;
        }

        alert('建立成功！');
        studentForm.value = {
            name: '',
            Grade: 'J1',
            branch_id: props.branchId || BRANCHES[0].id,
            phone: '',
            SchoolName: ''
        };
    } catch (e) {
        alert('連線失敗：' + (e?.message || '請稍後再試'));
    }
};
</script>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.grid label {
    display: block;
    font-weight: bold;
    margin-bottom: 4px;
}
.grid input, .grid select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.actions {
    display: flex;
    justify-content: flex-end;
}
</style>
