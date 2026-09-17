import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import CourseManager from '../CourseManager.vue'

vi.mock('../CourseSessionCalendar.vue', () => ({
  default: { name: 'CourseSessionCalendar', template: '<div class="csc" />', props: ['course', 'sessions', 'createEnabled'] },
}))

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
      sessionUnits: [],
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
      getSessionStateLabel: () => '',
      getSessionNumber: () => null,
      sessionRowKey: (u) => String(u?.id || u?.date || ''),
      isUserNote: () => false,
      formatMakeupDate: () => '',
      ...extra,
    },
    slots: { settings: '<form class="cef">settings</form>' },
    attachTo: document.body,
  })
}

describe('CourseManager', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('opens selected course workspace', async () => {
    const w = mountCm()
    await flushPromises()
    expect(w.text()).toContain('課程管理')
    expect(w.text()).toContain('陳湘甯')
    expect(w.text()).toContain('高中數學')
    expect(w.text()).toContain('王老師')
    expect(w.findAll('.cmw__tab').map((x) => x.text())).toEqual(['總覽', '排課與堂次', '課程設定', '帳務與合約', '紀錄'])
    w.unmount()
  })

  it('keeps course identity across tabs and isolates context on course switch', async () => {
    const w = mountCm()
    await w.find('[data-testid="course-manager-tab-settings"]').trigger('click')
    expect(w.emitted('update:tab')?.at(-1)).toEqual(['settings'])
    await w.setProps({ tab: 'settings' })
    expect(w.find('.cef').exists()).toBe(true)
    await w.setProps({
      course: { ...baseCourse(), id: 99, student_name: '乙生', teacher_name: '李老師' },
      studentName: '乙生',
      subjectLabel: '英文',
      tab: 'overview',
    })
    await nextTick()
    expect(w.text()).toContain('乙生')
    expect(w.text()).toContain('英文')
    expect(w.text()).not.toContain('陳湘甯')
    w.unmount()
  })

  it('sessions surface calendar and omit Phase 1b/2/3 occurrence edits', async () => {
    const w = mountCm({ tab: 'sessions' })
    expect(w.find('.csc').exists()).toBe(true)
    expect(w.html()).not.toMatch(/取消此堂|改老師|改時間|this and future|this-and-future/i)
    w.unmount()
  })

  it('billing and lifecycle emit existing action names only', async () => {
    const w = mountCm({ tab: 'billing' })
    await w.findAll('button').find((b) => b.text() === '繳費通知').trigger('click')
    expect(w.emitted('action')?.at(-1)?.[0]).toEqual({ name: 'payment-slip' })
    await w.setProps({ tab: 'overview' })
    await nextTick()
    await w.findAll('button').find((b) => b.text() === '暫停課程').trigger('click')
    expect(w.emitted('action')?.at(-1)?.[0]).toEqual({ name: 'pause' })
    w.unmount()
  })
})
