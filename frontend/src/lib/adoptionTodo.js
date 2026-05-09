const STORAGE_KEY = 'alltrue_todo_ack_map_v1';

export function sortTodoCards(cards = []) {
  const priority = { breached: 0, overdue: 0, warning: 1, pending: 2, open: 3, acknowledged: 4, done: 9 };
  return [...cards].sort((a, b) => {
    const pa = priority[a.status] ?? 5;
    const pb = priority[b.status] ?? 5;
    if (pa !== pb) return pa - pb;
    return String(a.dueAt || '').localeCompare(String(b.dueAt || ''));
  });
}

export function loadTodoAckMap() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed = JSON.parse(raw || '{}');
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

export function saveTodoAckMap(map) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(map || {}));
}

export function markTodoAcknowledged(taskId) {
  if (!taskId) return;
  const map = loadTodoAckMap();
  map[String(taskId)] = Date.now();
  saveTodoAckMap(map);
}

export function isTodoAcknowledged(taskId) {
  const map = loadTodoAckMap();
  return Boolean(map[String(taskId)]);
}

