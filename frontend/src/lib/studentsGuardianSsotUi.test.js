/**
 * StudentsList: when multi-guardian edit UI is active, parent_name/phone editors
 * must not compete with guardians (SSOT). Create / flag-off keep legacy fields.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/StudentsList.vue', import.meta.url), 'utf8');

assert.ok(
  source.includes('showLegacyParentFields'),
  'expected showLegacyParentFields gate for parent editors'
);
assert.ok(
  source.includes('v-if="showLegacyParentFields"'),
  'parent name/phone editors must be gated by showLegacyParentFields'
);
assert.ok(
  source.includes("showLegacyParentFields = computed(() => !editingStudentId.value || !multiGuardianEnabled.value)"),
  'legacy parent fields only for create or flag-off edit'
);
assert.ok(
  source.includes('Guardians SSOT when multi-guardian edit UI is active'),
  'submit must omit parent_* when guardians SSOT is active'
);
assert.ok(
  source.includes('家長／監護人'),
  'edit UI should present guardians as parent SSOT section'
);
assert.ok(
  source.includes('class="form-section-title">LINE 綁定家長'),
  'LINE bindings section must remain'
);
assert.ok(
  source.includes('bindRfidFromTemp'),
  'RFID bind must remain'
);

console.log('studentsGuardianSsotUi.test.js: ok');
