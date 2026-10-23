/**
 * in-app #426: which regular class type a trial course is for (StudentClass.trial_target_class_type).
 * Values mirror backend StudentClass::TRIAL_TARGET_CLASS_TYPES. A 1-to-3 target lets several
 * trials share a 1-to-3 slot (total ≤ 3); no target keeps one trial per slot.
 */
export const TRIAL_TARGET_CLASS_TYPE_OPTIONS = [
  { value: 'one_on_one', label: '一對一' },
  { value: 'one_on_two', label: '一對二' },
  { value: 'one_on_three', label: '一對三' },
];

/** Request fields for a course form: only a trial sends its target (null clears it). */
export function trialTargetPayload(form) {
  if (String(form?.class_type || '') !== 'trial') return {};
  return { trial_target_class_type: form?.trial_target_class_type || null };
}
