const PROCESSOR = 'fixture-ocr/1';

const BANK = Object.freeze([
  { id: 8101, v: 3, prompt: '48 ÷ 6 = ?', answer: '8', concept: '整數除法' },
  { id: 8102, v: 2, prompt: '3/4 與 2/3，何者較大？', answer: '3/4', concept: '異分母分數比較' },
  { id: 8103, v: 1, prompt: '一枝筆 12 元，5 枝共多少元？', answer: '60', concept: '整數乘法應用' },
  { id: 8104, v: 4, prompt: '0.7 + 0.25 = ?', answer: '0.95', concept: '小數加法' },
  { id: 8105, v: 1, prompt: '請寫出長方形面積公式。', answer: null, concept: '面積公式' },
]);

function clonePack(pack) {
  return { ...pack, items: pack.items.map((item) => ({ ...item })) };
}

export function createPaperFixture() {
  const pages = Array.from({ length: 12 }, (_, i) => {
    const q = BANK[i % BANK.length];
    return { pageId: `syn-p${i + 1}`, question: { ...q }, ocrAnswer: i % 4 === 0 ? '7' : q.answer, verifiedAnswer: null };
  });
  return {
    jobId: 'fixture-job-01', attemptId: 'fixture-attempt-01', studentId: 'synthetic-student-a',
    inputRevision: 1, processorVersion: PROCESSOR, stage: 'UPLOADED', pages,
    runs: new Map(), confirmed: null, draft: null, approved: null, rawAvailable: true, audit: [],
  };
}

export function processingKey(s) {
  return [s.jobId, s.attemptId, s.studentId, s.inputRevision, s.processorVersion].join(':');
}

export function runFixtureOcr(s, { fail = false } = {}) {
  if (!s.rawAvailable) { s.stage = 'SOURCE_EXPIRED'; s.audit.push('原始資料已到期；不可再執行 OCR'); return null; }
  const key = processingKey(s);
  if (fail) {
    const result = { key, status: 'OCR_FAILED', revision: s.inputRevision };
    s.stage = result.status; s.audit.push('Fixture OCR 失敗；可改用人工輸入或重試'); return result;
  }
  if (s.runs.has(key)) { const result = s.runs.get(key); s.stage = result.status; return result; }
  const result = { key, status: 'TEACHER_REVIEW', revision: s.inputRevision };
  s.runs.set(key, result); s.stage = result.status;
  s.audit.push(`Fixture OCR 完成 · ${s.pages.length} 頁`);
  return result;
}

export function replacePage(s, pageId) {
  const page = s.pages.find((p) => p.pageId === pageId); if (!page) return;
  page.ocrAnswer = ''; page.verifiedAnswer = null; s.inputRevision += 1; s.stage = 'UPLOADED';
  s.confirmed = null; s.draft = null; s.approved = null; s.audit.push(`${pageId} 已替換；舊處理結果失效`);
}

export function verifyPage(s, pageId, answer) {
  const page = s.pages.find((p) => p.pageId === pageId); if (!page) return;
  const normalized = String(answer ?? '').trim();
  if (page.verifiedAnswer === normalized) return page;
  page.verifiedAnswer = normalized; s.inputRevision += 1; s.stage = 'TEACHER_REVIEW';
  s.confirmed = null; s.draft = null; s.approved = null;
  return page;
}

export function confirmEvidence(s) {
  if (s.confirmed) return s.confirmed;
  const missing = s.pages.find((p) => p.question.answer == null);
  if (missing) { s.stage = 'NEEDS_ANSWER_KEY'; s.audit.push(`${missing.pageId} 無有效答案 snapshot`); return null; }
  if (s.pages.some((p) => p.verifiedAnswer == null)) { s.stage = 'TEACHER_REVIEW'; return null; }
  s.confirmed = { id: 'confirmed-01', studentId: s.studentId, attemptId: s.attemptId,
    revision: s.inputRevision, answers: s.pages.map((p) => ({ pageId: p.pageId, questionId: p.question.id, answer: p.verifiedAnswer })) };
  s.stage = 'VERIFIED'; s.audit.push('老師已確認；建立唯一確認紀錄'); return s.confirmed;
}

export function supplyFixtureAnswerKey(s) {
  const missing = s.pages.filter((p) => p.question.answer == null);
  missing.forEach((p) => { p.question.answer = '長 × 寬'; });
  if (missing.length) { s.inputRevision += 1; s.audit.push('補入自製 fixture answer snapshot'); }
}

export function draftPack(s, { fail = false, manual = false } = {}) {
  if (!s.confirmed) return null;
  if (fail) { s.stage = 'AI_UNAVAILABLE'; s.audit.push('Fixture AI 失敗；可改用人工建立草稿'); return null; }
  if (s.draft?.sourceRevision === s.confirmed.revision) return s.draft;
  const wrong = s.pages.filter((p) => p.verifiedAnswer !== p.question.answer);
  s.draft = { sourceRevision: s.confirmed.revision, contentRevision: 1, title: '分數與運算補強', items: wrong.map((p) => ({ pageId: p.pageId, questionId: p.question.id,
    prompt: p.question.prompt, concept: p.question.concept, answer: p.question.answer,
    practicePrompt: `${p.question.concept}練習：請寫出計算或判斷過程。`,
    explanation: `回到「${p.question.concept}」的判斷步驟，再完成一道同型題。` })) };
  s.stage = 'PACK_DRAFTED'; s.audit.push(manual ? '人工診斷與講義草稿完成' : 'Fixture 診斷與講義草稿完成'); return s.draft;
}

export function updateDraftItem(s, pageId, explanation) {
  const item = s.draft?.items.find((candidate) => candidate.pageId === pageId); if (!item) return null;
  const normalized = String(explanation ?? '').trim(); if (item.explanation === normalized) return s.draft;
  item.explanation = normalized; s.draft.contentRevision += 1; s.approved = null; s.stage = 'PACK_DRAFTED';
  s.audit.push(`草稿頁面 ${pageId} 已修改；舊核准失效`); return s.draft;
}

export function returnDraft(s) {
  if (!s.draft) return null;
  s.draft = null; s.approved = null; s.stage = 'VERIFIED'; s.audit.push('草稿已退回；保留老師確認作答'); return s.confirmed;
}

export function approvePack(s) {
  if (!s.draft) return null;
  if (s.approved) return s.approved;
  s.approved = clonePack({ ...s.draft, approvedRevision: `pack-r${s.draft.sourceRevision}.${s.draft.contentRevision}` });
  s.stage = 'PACK_APPROVED'; s.audit.push(`核准 ${s.approved.approvedRevision}`); return s.approved;
}

export function renderApprovedPack(s, layout = 'a4-standard', variant = 'student') {
  if (!s.approved) return null;
  return { layout, variant, approvedRevision: s.approved.approvedRevision, content: clonePack(s.approved) };
}

export function expireRaw(s) {
  s.rawAvailable = false; s.pages.forEach((p) => { p.ocrAnswer = null; });
  s.audit.push('模擬第 30 天：原始檔與 raw OCR 已清除；確認紀錄保留');
}
