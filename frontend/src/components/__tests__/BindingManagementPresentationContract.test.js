import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/BindingManagementPage.vue'), 'utf8');

describe('BindingManagementPage responsive presentation contract', () => {
  it('keeps the dense desktop table while exposing the same rows as labelled mobile cards', () => {
    expect(source).toContain('class="bmp-table-wrap bmp-desktop-table"');
    expect(source).toContain('class="bmp-mobile-list" aria-label="LINE 綁定清單"');
    expect(source).toContain('class="bmp-mobile-card"');
    expect(source).toContain('@media (max-width: 640px)');
    expect(source).toContain('.bmp-desktop-table { display: none; }');
    expect(source).toContain('.bmp-mobile-list { display: block; }');
    expect(source).toContain('min-height: var(--ds-control-height-touch, 44px)');
  });

  it('names the page for parents and maps each LINE reply a parent can get to a director action (#3900)', () => {
    const webhook = readFileSync(resolve(__dirname, '../../../../backend/app/Http/Controllers/LineWebhookController.php'), 'utf8');
    expect(source).toContain('title="家長 LINE 綁定"');
    expect(source).toContain('<summary>家長綁不上怎麼辦</summary>');
    // The guide quotes the parent's LINE reply; keep it tied to the replies the webhook really sends.
    // Every lookup-dependent failure gets this one reply (no student-existence oracle), so the guide has one row for it.
    expect(webhook).toContain('找不到相符的資料');
    expect(source).toContain('「找不到相符的資料」');
    for (const retired of ['找不到學生', '手機號碼不符', '未登記手機號碼', '已在其他分校綁定']) {
      expect(webhook).not.toContain(retired);
      expect(source).not.toContain(`「${retired}」`);
    }
    expect(webhook).toContain('綁定 王小明 0912345678');
    expect(source).toContain('綁定 王小明 0912345678');
    // The reply no longer says the student is at another campus: the guide tells the director to verify the campus first.
    expect(source).toContain('先確認學生現在在哪個分校上課');
    // A phone-only change keeps the binding but still needs the new number on file.
    expect(source).toContain('不用解除或重綁');
    expect(source).not.toContain('則不用處理');
  });

  it('routes the guide only to workflows that work today; broken ones escalate (#3915 review)', () => {
    const guide = source.slice(source.indexOf('data-testid="binding-guide"'), source.indexOf('</details>'));
    // Unbinding from the list does not revoke multi-guardian access, the conflict-review page cannot
    // resolve conflicts, and staff cannot change a student's campus or a multi-guardian phone.
    expect(guide).not.toContain('綁定衝突審查');
    expect(guide).not.toContain('在下方清單解除舊綁定');
    expect(guide).not.toContain('更正學生的分校，再請家長');
    expect(guide).not.toContain('改成新的家長手機');
    expect(guide).toContain('不要自己在下方清單解除');
    for (const situation of ['同一學生在兩個分校都有綁定', '家長換了手機號碼', '家長換了 LINE 帳號']) {
      const row = guide.slice(guide.indexOf(situation), guide.indexOf('</div>', guide.indexOf(situation)));
      expect(row).toContain('回報超級管理員');
    }
    expect(source).not.toContain('解除錯誤或舊的綁定');
    // The sidebar/onboarding copy must not advertise unbinding either (#3915 review, #3933).
    const featureMap = readFileSync(resolve(__dirname, '../../lib/roleFeatureMap.js'), 'utf8');
    const entry = featureMap.split('\n').find((l) => l.includes("'binding-management':"));
    expect(entry).toBeTruthy();
    expect(entry).not.toContain('解除');
  });

  it('keeps destructive unbinding on the existing confirmation and API path', () => {
    expect(source).toContain('@click="openUnbindDialog(row)"');
    expect(source).toContain('title="解除 LINE 綁定"');
    expect(source).toContain('@click="confirmUnbind"');
    expect(source).toContain('await unbindBinding(unbindTarget.value.id)');
    expect(source).toContain('fetchBindings({');
    expect(source).toContain('campusId: activeCampusId.value');
    expect(source).toContain('studentName: filters.value.studentName || undefined');
    expect(source).toContain('perPage,');
  });

  it('describes the all-permitted-campus scope when no campus is selected (#3945)', () => {
    expect(source).not.toContain('description="查家長是否已綁定。只顯示所選分校。"');
    expect(source).toContain(':description="headerDescription"');
    expect(source).toContain("activeCampusId.value == null\n  ? '查家長是否已綁定。顯示你有權限的所有分校。'");
    expect(source).toContain("const scopeLabel = computed(() => (activeCampusId.value == null ? '權限內分校' : '本分校'));");
    expect(source).toContain('<span>{{ scopeLabel }} <strong>{{ statsLabel }}</strong></span>');
  });
});
