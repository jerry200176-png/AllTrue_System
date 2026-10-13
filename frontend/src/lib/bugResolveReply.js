// GitHub #3750: a resolve reply must tell the reporter both ways to answer.
// The labels are the reporter-verify buttons on BugReportsPage; the backend
// (BugReportService::RESOLVE_REPLY_REQUIRED_PHRASES) rejects a staff resolve
// whose latest public reply misses either one. bugResolveReply.test.js keeps
// both lists equal.
export const RESOLVE_REPLY_OUTCOMES = Object.freeze({
  confirm: '確認已修好',
  reopen: '問題仍存在',
});

export const RESOLVE_REPLY_REQUIRED_PHRASES = Object.freeze([
  RESOLVE_REPLY_OUTCOMES.confirm,
  RESOLVE_REPLY_OUTCOMES.reopen,
]);

export function buildResolveReplyTemplate() {
  return `已修好並上線：（請用一句話說明修了什麼）。請重新整理頁面後確認；若已正常，請按「${RESOLVE_REPLY_OUTCOMES.confirm}」；若仍有問題，請按「${RESOLVE_REPLY_OUTCOMES.reopen}」並簡述，我們會再追。`;
}

export function missingResolveReplyPhrases(text) {
  const body = String(text ?? '');
  return RESOLVE_REPLY_REQUIRED_PHRASES.filter((phrase) => !body.includes(phrase));
}

// Pre-fill only into an empty reply box so staff text is never overwritten.
export function shouldPrefillResolveReply(nextStatus, currentReply) {
  return nextStatus === 'resolved' && String(currentReply ?? '').trim() === '';
}
