import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

describe('StudentsList monthly renewal discount payload', () => {
  it('omits discount when NONE so admin renewals are not rejected by the finance gate', () => {
    // The payload is now built by the shared composable (also used by CourseManagement).
    const submit = readFileSync(resolve(__dirname, '../../composables/course-management/useMonthlyRenewal.js'), 'utf8');
    expect(submit).not.toContain('discount: form.value.discount }),');
    expect(submit).toContain("discount.type !== 'NONE'");
    expect(source).toContain('monthlyRenewal.submit(course, endDate)');
  });
});
