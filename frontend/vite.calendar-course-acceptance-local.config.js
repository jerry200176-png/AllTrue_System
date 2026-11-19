import local from './vite.director-acceptance.config.js';
import { CALENDAR_COURSE_LOCAL_PORT, calendarCourseAcceptanceApi } from './e2e/fixtures/calendarCourseAcceptanceApi.js';

export default {
  ...local,
  plugins: [calendarCourseAcceptanceApi(), ...local.plugins],
  server: { ...local.server, port: CALENDAR_COURSE_LOCAL_PORT },
};
