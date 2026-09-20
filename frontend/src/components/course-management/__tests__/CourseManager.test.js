import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import CourseManager from '../CourseManager.vue'

vi.mock('../CourseSessionCalendar.vue', () => ({
  default: {
    name: 'CourseSessionCalendar',
    template: '<div class="csc" data-testid="csc-mock" :data-show-quick-add="String(showQuickAdd)" />',
    props: ['course', 'sessions', 'createEnabled', 'showQuickAdd'],
  },
}))

const __dirname = dirname(fileURLToPath(import.meta.url))
const pageSource = readFileSync(resolve(__dirname, '../../../pages/CourseManagement.vue'), 'utf8')

const baseCourse = () => ({
  id: 7,
  student_name: '陳湘甯',
  subject: '高中數學',
  teacher_name: '王老師',
  class_type: '一對一',
  status: 'active',
  payment_type: 'session',
  room_name: 'A',
})

function mountCm(extra = {}) {
  return mount(CourseManager, {
    props: {
      course: baseCourse(),
      tab: 'overview',
      studentName: '陳湘甯',
      subjectLabel: '高中數學',
      classTypeLabel: '一對一',
      statusLabel: '進行中',
      scheduleSummary: '週三 18:30',
      remainingLabel: '剩餘 5 / 12 堂',
      nextSessionLabel: '9/23',
      paymentLabel: '已繳',
      overviewNeeds: [],
      sessionUnits: [
        { id: 1, date: '2026-09-23', isProjected: false },
        { id: null, date: '2026-09-30', isProjected: true },
      ],
      cancelledUnits: [],
      pendingMakeups: [],
      calendarEnabled: true,
      createEnabled: true,
      canQuickAdd: true,
      canClose: true,
      isSessionMode: true,
      isMonthlyMode: false,
      isManualOccurrence: false,
      purchaseLabel: '續報 / 加購',
      paymentNoticeAvailable: true,
      canPackagePreview: true,
      formatSessionChipDate: (u) => String(u?.date || '').slice(0, 10),
      getSessionStateClass: () => '',
      getSessionStateLabel: () => '已建立',
      getSessionNumber: (_c, _d, id) => (id === 1 ? 8 : null),
      sessionRowKey: (u) => String(u?.id || u?.date || ''),
      isUserNote: () => false,
      formatMakeupDate: () => '',
      ...extra,
    },
    slots: {
      settings: `
        <div class="cef">settings</div>
        <button type="button">返回總覽</button>
        <button type="button">儲存課程設定</button>
        <button type="button">複製為新課程並更換老師</button>
      `,
    },
    attachTo: document.body,
  })
}

describe('CourseManager polish', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('opens with four tabs and no Records tab', async () => {
    const w = mountCm()
    await flushPromises()
    expect(w.findAll('.cmw__tab').map((x) => x.text())).toEqual(['總覽', '排課與堂次', '課程設定', '帳務與合約'])
    expect(w.find('[data-testid="course-manager-tab-records"]').exists()).toBe(false)
    expect(w.find('[data-testid="course-manager-status"]').classes()).toContain('cmw__badge--ok')
    expect(w.text()).not.toContain('這門課')
    expect(w.find('.cmw__card--needs').exists()).toBe(false)
    w.unmount()
  })

  it('status badge tone reflects pause / closed', async () => {
    const paused = mountCm({ course: { ...baseCourse(), status: 'inactive' }, statusLabel: '暫停' })
    expect(paused.find('[data-testid="course-manager-status"]').classes()).toContain('cmw__badge--warn')
    paused.unmount()
    const closed = mountCm({ statusLabel: '已結案' })
    expect(closed.find('[data-testid="course-manager-status"]').classes()).toContain('cmw__badge--neutral')
    closed.unmount()
  })

  it('monthly courses expose a single schedule CTA', async () => {
    const w = mountCm({
      tab: 'sessions',
      isSessionMode: false,
      isMonthlyMode: true,
      canQuickAdd: false,
      payment_type: 'monthly',
    })
    const ctas = w.findAll('[data-testid="course-manager-schedule-cta"]')
    expect(ctas).toHaveLength(1)
    expect(ctas[0].text()).toBe('新增月結堂次')
    expect(w.text()).not.toContain('排月結')
    expect(w.findAll('button').filter((b) => b.text() === '新增月結堂次')).toHaveLength(1)
    await ctas[0].trigger('click')
    expect(w.emitted('action')?.at(-1)?.[0]).toEqual({ name: 'manual-session' })
    w.unmount()
  })

  it('quick-add lives only in sessions toolbar; calendar suppresses duplicate', async () => {
    const w = mountCm({ tab: 'sessions' })
    expect(w.find('[data-testid="course-manager-quick-add"]').exists()).toBe(true)
    expect(w.find('[data-testid="csc-mock"]').attributes('data-show-quick-add')).toBe('false')
    const settingsSlot = pageSource.slice(pageSource.indexOf('<template #settings>'), pageSource.indexOf('</template>', pageSource.indexOf('<template #settings>')) + 11)
    expect(settingsSlot).toContain('@click="leaveCourseManagerSettings"')
    expect(settingsSlot).toContain('返回總覽')
    expect(settingsSlot).toContain('複製為新課程並更換老師')
    expect(settingsSlot).not.toContain('補課')
    expect(settingsSlot).not.toContain('>取消<')
    expect(pageSource).toContain('尚有未儲存變更，要放棄嗎？')
    w.unmount()
  })

  it('billing keeps one tuition-center action', async () => {
    const w = mountCm({ tab: 'billing' })
    const tuition = w.findAll('[data-testid="course-manager-tuition"]')
    expect(tuition).toHaveLength(1)
    expect(tuition[0].text()).toBe('前往帳務中心')
    expect(w.text()).not.toContain('查看帳務')
    expect(w.find('[data-testid="course-manager-invoice"]').text()).toBe('查看帳單')
    expect(w.text()).toContain('產生繳費通知')
    w.unmount()
  })

  it('calendar/list switch shares the same session units', async () => {
    const w = mountCm({ tab: 'sessions' })
    expect(w.find('.csc').exists()).toBe(true)
    await w.find('[data-testid="course-manager-view-list"]').trigger('click')
    await nextTick()
    expect(w.find('[data-testid="course-manager-session-list"]').exists()).toBe(true)
    expect(w.text()).toContain('2026-09-23')
    expect(w.text()).toContain('2026-09-30')
    expect(w.text()).toContain('預排')
    expect(w.html()).not.toMatch(/取消此堂|改老師|改時間|this and future|this-and-future/i)
    w.unmount()
  })

  it('settings host copy is truthful and danger zone is demoted', () => {
    expect(pageSource).toContain('data-testid="course-manager-danger"')
    expect(pageSource).toContain('尚有未儲存變更，要放棄嗎？')
    expect(pageSource).not.toContain("courseManagerTab = 'overview'\">取消")
  })

  it('keeps course identity across tabs', async () => {
    const w = mountCm()
    await w.find('[data-testid="course-manager-tab-settings"]').trigger('click')
    expect(w.emitted('update:tab')?.at(-1)).toEqual(['settings'])
    await w.setProps({
      course: { ...baseCourse(), id: 99 },
      studentName: '乙生',
      subjectLabel: '英文',
      tab: 'overview',
    })
    await nextTick()
    expect(w.text()).toContain('乙生')
    expect(w.text()).not.toContain('陳湘甯')
    w.unmount()
  })
})
