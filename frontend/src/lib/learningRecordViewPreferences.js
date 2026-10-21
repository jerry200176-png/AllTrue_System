const normalizedWidth = (viewportWidth) => {
  const width = Number(viewportWidth);
  return Number.isFinite(width) ? width : 1280;
};

const normalizedViewMode = (savedViewMode, viewportWidth) => {
  if (savedViewMode === 'table' || savedViewMode === 'card') return savedViewMode;
  return normalizedWidth(viewportWidth) < 760 ? 'card' : 'table';
};

/**
 * Keep the assessment queue scan-friendly on first load. Full assessment
 * content remains available through the explicit preview control.
 */
export const resolveLearningRecordViewDefaults = ({ viewportWidth, savedViewMode } = {}) => ({
  viewMode: normalizedViewMode(savedViewMode, viewportWidth),
  showContentPreview: false,
});

/** Mobile has one supported layout; retain the saved desktop preference. */
export const resolveLearningRecordViewMode = ({ viewportWidth, viewMode } = {}) => (
  normalizedWidth(viewportWidth) <= 640 ? 'card' : normalizedViewMode(viewMode, viewportWidth)
);

/**
 * View modes the chooser may offer at this width: only modes that actually
 * take effect when picked, so a narrow screen never shows a no-op 列表 button.
 */
export const resolveLearningRecordViewModeOptions = ({ viewportWidth } = {}) => (
  ['table', 'card'].filter(
    (mode) => resolveLearningRecordViewMode({ viewportWidth, viewMode: mode }) === mode,
  )
);
