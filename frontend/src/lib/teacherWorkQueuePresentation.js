export function teacherWorkQueuePhase({
  criticalLoading = false,
  supplementalLoading = false,
  hasError = false,
  taskCount = 0,
} = {}) {
  if (hasError) return 'error';
  if (criticalLoading) return 'loading';
  if (supplementalLoading) return taskCount > 0 ? 'partial' : 'loading';
  return taskCount > 0 ? 'ready' : 'empty';
}
