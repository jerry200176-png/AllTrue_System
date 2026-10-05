import assert from 'node:assert/strict';
import { isDirectorRole } from './roleCapabilities.js';

for (const role of ['director', 'admin', 'super_admin']) assert.equal(isDirectorRole(role), true, role);
for (const role of ['teacher', 'parent', '', undefined, null]) assert.equal(isDirectorRole(role), false, String(role));
console.log('roleCapabilities: ok');
