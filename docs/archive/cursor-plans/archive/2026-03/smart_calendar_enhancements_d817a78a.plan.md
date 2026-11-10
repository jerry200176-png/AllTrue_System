---
name: Smart Calendar Enhancements
overview: "Add three UX improvements to SmartCalendar.vue: a Day view mode, drag-to-reschedule for course blocks, and a right-click context menu to immediately trigger leave without opening the detail modal first."
todos:
  - id: day-view
    content: "Add Day view mode: new tab button, displayDay state, prev/next navigation, single-column time grid template, dayFilteredCourses computed"
    status: completed
  - id: drag-reschedule
    content: "Add drag-to-reschedule: draggable course blocks, dragover/drop handlers on slots, pre-fill reschedule modal on drop, drag-over CSS highlight"
    status: completed
  - id: rightclick-leave
    content: "Add right-click leave: contextmenu handler on course blocks, floating context menu component, onContextLeave function, refactor submitLeave to use leaveForm fields instead of modalForm"
    status: completed
  - id: deploy
    content: "Deploy: run npm run deploy in frontend/"
    status: completed
isProject: false
---

# Smart Calendar Enhancements

All changes are in a single file: [`frontend/src/pages/SmartCalendar.vue`](frontend/src/pages/SmartCalendar.vue)

---

## Feature 1 – Day View (日檢視)

**New tab** in `.view-tabs`:
```html
<button type="button" :class="{ active: viewMode === 'day' }" @click="viewMode = 'day'">日課表</button>
```

**New state:**
```js
const displayDay = ref(new Date().toISOString().split('T')[0]); // YYYY-MM-DD
```

**Day navigation** (prev/next day buttons + date picker `<input type="date">`):
```html
<div v-if="viewMode === 'day'" class="day-nav">
  <button @click="prevDay">‹ 前一天</button>
  <input type="date" v-model="displayDay" />
  <button @click="nextDay">後一天 ›</button>
</div>
```

**Day view template**: Same time-axis as the week grid but a single day column — reuses `hours` array (8–22), uses `displayDay` as the target date, and renders course blocks absolutely positioned by start time.

**Computed `dayFilteredCourses`**: Filters `filteredCourses` (after suppression logic) for courses whose computed date == `displayDay`, plus any `scheduled` exceptions on that date.

**Navigation helpers:**
```js
const prevDay = () => { /* subtract 1 day from displayDay */ };
const nextDay = () => { /* add 1 day to displayDay */ };
```

---

## Feature 2 – Drag Course Block to Reschedule (拖動調課)

Only active in week view, only for non-teacher role (same restriction as existing click handlers).

**New state:**
```js
const draggingCourse = ref(null);   // { course, originalDate }
const dragOverSlot = ref(null);     // { dow, h } for visual feedback
```

**Course block** – add `draggable` and drag events:
```html
<div class="course-block"
  :draggable="!isTeacher"
  @dragstart.stop="onCourseDragStart(course, getDisplayDateFull(dayIdx+1), $event)"
  @click.stop="..."
  ...>
```

**Slot** – add drag target events:
```html
<div class="slot"
  @dragover.prevent="dragOverSlot = { dow: dayIdx+1, h }"
  @dragleave="dragOverSlot = null"
  @drop.prevent="onSlotDrop(dayIdx+1, h, getDisplayDateFull(dayIdx+1))"
  :class="{ 'drag-over': dragOverSlot?.dow === dayIdx+1 && dragOverSlot?.h === h }"
  ...>
```

**Handler logic:**
```js
const onCourseDragStart = (course, date, event) => {
  draggingCourse.value = { course, originalDate: date };
  event.dataTransfer.effectAllowed = 'move';
};

const onSlotDrop = (dow, h, targetDate) => {
  if (!draggingCourse.value) return;
  const { course, originalDate } = draggingCourse.value;
  draggingCourse.value = null;
  dragOverSlot.value = null;
  // Pre-fill reschedule form with new date and new start time (h:00)
  // then show the reschedule modal for confirmation
  const newStart = String(h).padStart(2,'0') + ':00';
  const dur = course.duration_hours || 2;
  rescheduleForm.value = { ...existing fields..., original_date: originalDate, new_date: targetDate, new_start: newStart, new_end: computeEndTime(newStart, dur) };
  showRescheduleModal.value = true;
};
```

**New CSS:**
```css
.slot.drag-over { background: #dbeafe; }
.course-block[draggable="true"] { cursor: grab; }
.course-block[draggable="true"]:active { cursor: grabbing; }
```

---

## Feature 3 – Right-click Context Menu for Leave (右鍵請假)

**New state:**
```js
const contextMenu = ref({ show: false, x: 0, y: 0, course: null, date: null });
```

**Course block** – add context menu handler (alongside existing `@click.stop`):
```html
@contextmenu.prevent="onCourseRightClick(course, getDisplayDateFull(dayIdx+1), $event)"
```

**Handler:**
```js
const onCourseRightClick = (course, date, event) => {
  contextMenu.value = { show: true, x: event.clientX, y: event.clientY, course, date };
};
```

**Context menu template** (fixed positioned, outside the grid):
```html
<div v-if="contextMenu.show" class="context-menu"
  :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
  @click.stop
  v-click-outside="() => contextMenu.show = false">
  <button @click="onContextLeave">請假</button>
  <button @click="contextMenu.show = false">取消</button>
</div>
```

**`onContextLeave` handler** – populates `leaveForm` directly from the course object (no modal chain):
```js
const onContextLeave = () => {
  const { course, date } = contextMenu.value;
  const baseId = course.is_exception ? course.student_course_id : course.id;
  leaveForm.value = {
    student_id: course.student_id,
    subject: course.subject,
    teacher_id: course.teacher_id,
    day_of_week: course.day_of_week,
    start_time: course.start_time,
    end_time: course.end_time,
    duration_hours: course.duration_hours || 2,
    class_type: course.class_type,
    schedule_date: date,
    course_id: baseId
  };
  contextMenu.value = { show: false };
  showLeaveModal.value = true;
};
```

**Refactor `submitLeave`** to read `teacher_id`, `duration_hours`, `class_type` from `leaveForm` instead of `modalForm`, so it works both from the old modal chain and from the context menu shortcut. Add these fields to the `leaveForm` ref.

**Dismiss on Escape/outside click** – add a `keydown` listener on `document` to close the context menu.

**Context menu CSS:**
```css
.context-menu {
  position: fixed; z-index: 9999;
  background: white; border: 1px solid #e2e8f0;
  border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
  padding: 4px 0; min-width: 120px;
}
.context-menu button {
  display: block; width: 100%;
  padding: 8px 16px; text-align: left;
  border: none; background: none; cursor: pointer;
}
.context-menu button:hover { background: #f1f5f9; }
```

---

## Deployment

After all changes, run `npm run deploy` in `frontend/` to build and copy to `backend/public`.