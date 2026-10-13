// GitHub #3750: a resolve reply must tell the reporter both ways to answer.
// The running backend is the authority: GET /api/v1/bugs/{id} returns
// `resolve_reply_outcomes` ({ confirm, reopen }) from
// BugReportService::RESOLVE_REPLY_OUTCOMES, and the reply template is built only
// from that payload, so a cached frontend can never prefill phrases the backend
// rejects. RESOLVE_REPLY_OUTCOMES below is only the fallback label for the
// reporter-verify buttons when an older backend omits the field.
export const RESOLVE_REPLY_OUTCOMES = Object.freeze({
  confirm: '確認已修好',
  reopen: '問題仍存在',
});

function validOutcomes(outcomes) {
  return Boolean(
    outcomes
    && typeof outcomes.confirm === 'string' && outcomes.confirm !== ''
    && typeof outcomes.reopen === 'string' && outcomes.reopen !== '',
  );
}

export function resolveOutcomeLabels(serverOutcomes) {
  return validOutcomes(serverOutcomes) ? serverOutcomes : RESOLVE_REPLY_OUTCOMES;
}

export function buildResolveReplyTemplate(serverOutcomes) {
  if (!validOutcomes(serverOutcomes)) return '';
  return `已修好並上線：（請用一句話說明修了什麼）。請重新整理頁面後確認；若已正常，請按「${serverOutcomes.confirm}」；若仍有問題，請按「${serverOutcomes.reopen}」並簡述，我們會再追。`;
}

export function missingResolveReplyPhrases(text, serverOutcomes) {
  if (!validOutcomes(serverOutcomes)) return [];
  const body = String(text ?? '');
  return [serverOutcomes.confirm, serverOutcomes.reopen].filter((phrase) => !body.includes(phrase));
}

// Pre-fill only into an empty reply box, and only with phrases the backend sent.
export function shouldPrefillResolveReply(nextStatus, currentReply, serverOutcomes) {
  return nextStatus === 'resolved'
    && String(currentReply ?? '').trim() === ''
    && validOutcomes(serverOutcomes);
}
