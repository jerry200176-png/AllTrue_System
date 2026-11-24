import local from './playwright.director-acceptance-local.config.js';
import { CALENDAR_COURSE_LOCAL_PORT, calendarCourseFixtureUser } from './e2e/fixtures/calendarCourseAcceptanceApi.js';

const origin = `http://127.0.0.1:${CALENDAR_COURSE_LOCAL_PORT}`;
process.env.SMOKE_BASE_URL = origin;
process.env.SMOKE_BRANCH_ID = '9';
process.env.SMOKE_START_DATE = '2026-08-05';
process.env.SMOKE_END_DATE = '2026-08-07';
process.env.SMOKE_DIRECTOR_SESSION_B64 = Buffer.from(JSON.stringify({
  access_token: 'synthetic-local-only',
  user: calendarCourseFixtureUser,
})).toString('base64');

export default {
  ...local,
  testMatch: /calendar-course-consistency\.spec\.js$/,
  grep: /calendar and course management agree|contracted four-session|in-app 390|in-app 395\/403|in-app 400|in-app 417/,
  metadata: { calendarCourseFixture: true },
  reporter: process.env.PLAYWRIGHT_JSON_OUTPUT_FILE ? [['list'], ['json']] : local.reporter,
  fullyParallel: true,
  workers: 2,
  use: { ...local.use, baseURL: origin },
  webServer: {
    ...local.webServer,
    command: 'node node_modules/vite/bin/vite.js --config vite.calendar-course-acceptance-local.config.js',
    url: origin,
    env: { ...local.webServer.env, VITE_API_BASE: '/api' },
  },
};
