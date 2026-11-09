// @ts-check
/**
 * HTTP method guard plus known stateful-read blocks for acceptance specs.
 *
 * Other HTTP reads pass through; that is not proof of zero database effects.
 * Known app GET/HEAD materializers are blocked. App adoption telemetry is answered
 * locally with 204. An opt-in spec may answer a valid transaction-only Sentry
 * envelope locally; every other HTTP write is aborted and recorded. Blocking the
 * first write hop
 * prevents redirect chains from carrying a write back to the app: Playwright
 * routes only the first request in an HTTP redirect chain. Native read redirects
 * remain outside any zero-side-effect guarantee.
 * Self-tests: readOnlyAppGuard.test.js (unit) and
 * e2e/read-only-app-guard.spec.js (browser, local servers only).
 */

export const READ_ONLY_METHODS = Object.freeze(['GET', 'HEAD', 'OPTIONS']);
const STATEFUL_READ_PATHS = Object.freeze(['/api/v1/class-sessions', '/api/v1/class-sessions/projection']);
export const ADOPTION_TELEMETRY_PATH = '/api/v1/adoption/events';
/**
 * Reads the app sends as POST, with the query in the body; the server writes
 * nothing (StudentClassController::sessionDates also serves the GET route).
 * The guard still aborts them like any write. A spec may count such a blocked
 * read (isKnownPostRead) instead of failing on it.
 */
export const KNOWN_POST_READ_PATHS = Object.freeze(['/api/v1/student-classes/session-dates']);
const MAX_SENTRY_ENVELOPE_BYTES = 1024 * 1024;

/** Inspect only the envelope framing and event type; never return payload text. */
export function classifySentryEnvelope(body) {
  if (!body) return 'malformed';
  if (body.length > MAX_SENTRY_ENVELOPE_BYTES) return 'oversize';
  try {
    const bytes = Buffer.isBuffer(body) ? body : Buffer.from(body);
    const readLine = (start) => {
      const end = bytes.indexOf(10, start);
      if (end < 0) throw new Error('missing envelope separator');
      const line = bytes.subarray(start, end);
      return { line: line.at(-1) === 13 ? line.subarray(0, -1) : line, next: end + 1 };
    };
    const decodeJson = (part) => JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(part));
    const envelope = readLine(0);
    const envelopeHeader = decodeJson(envelope.line);
    if (!envelopeHeader || Array.isArray(envelopeHeader) || typeof envelopeHeader !== 'object') return 'malformed';
    const itemHeaderLine = readLine(envelope.next);
    const itemHeader = decodeJson(itemHeaderLine.line);
    if (!itemHeader || Array.isArray(itemHeader) || typeof itemHeader !== 'object'
      || typeof itemHeader.type !== 'string') return 'malformed';
    let payload = bytes.subarray(itemHeaderLine.next);
    let extra = Buffer.alloc(0);
    let extraHeaderOffset = 0;
    const length = itemHeader.length;
    if (length !== undefined) {
      if (!Number.isSafeInteger(length) || length < 0 || length > MAX_SENTRY_ENVELOPE_BYTES
        || payload.length < length) return 'malformed';
      payload = payload.subarray(0, length);
      const remainderOffset = itemHeaderLine.next + length;
      const remainder = bytes.subarray(remainderOffset);
      if (remainder.length > 0) {
        if (remainder[0] !== 10) return 'malformed';
        extra = remainder.subarray(1);
        extraHeaderOffset = remainderOffset + 1;
      }
    } else {
      const end = payload.indexOf(10);
      if (end >= 0) {
        extra = payload.subarray(end + 1);
        payload = payload.subarray(0, end);
        extraHeaderOffset = itemHeaderLine.next + end + 1;
      }
    }
    if (payload.includes(10) || payload.includes(13)) return 'malformed';
    const event = decodeJson(payload);
    if (!event || Array.isArray(event) || typeof event !== 'object') return 'malformed';
    if (extra.length) {
      try {
        const nextHeader = decodeJson(readLine(extraHeaderOffset).line);
        return nextHeader && typeof nextHeader === 'object' && typeof nextHeader.type === 'string'
          ? 'mixed' : 'malformed';
      } catch {
        return 'malformed';
      }
    }
    if (itemHeader.type === 'transaction' && event.type === 'transaction'
      && !Object.hasOwn(event, 'exception') && !['error', 'fatal'].includes(event.level)) return 'transaction';
    if (itemHeader.type === 'event' || event.type === 'error' || ['error', 'fatal'].includes(event.level)
      || Object.hasOwn(event, 'exception')) return 'error';
    return 'unknown';
  } catch {
    return 'malformed';
  }
}

function isSentryEnvelopeUrl(url) {
  try {
    const parsed = new URL(String(url));
    return parsed.protocol === 'https:' && parsed.port === '' && !parsed.username && !parsed.password
      && /^o[0-9]+\.ingest(?:\.us)?\.sentry\.io$/.test(parsed.hostname)
      && /^\/api\/[0-9]+\/envelope\/$/.test(parsed.pathname);
  } catch {
    return false;
  }
}

/** Origin of an http(s) base URL, or '' when it is missing or not http(s). */
export function appOriginOf(baseUrl) {
  try {
    const url = new URL(String(baseUrl || ''));
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.origin : '';
  } catch {
    return '';
  }
}

/** True when `url` (URL or string) belongs to the app origin. */
export function isAppOriginUrl(appOrigin, url) {
  if (!appOrigin) return false;
  try {
    return new URL(String(url)).origin === appOrigin;
  } catch {
    return false;
  }
}

function isHttpUrl(url) {
  try {
    const protocol = new URL(String(url)).protocol;
    return protocol === 'http:' || protocol === 'https:';
  } catch {
    return false;
  }
}

export function isKnownStatefulRead(appOrigin, method, url) {
  if (!['GET', 'HEAD'].includes(method) || !isAppOriginUrl(appOrigin, url)) return false;
  let path = new URL(String(url)).pathname;
  // Conservatively cover proxy/Laravel percent decoding, without UTF-8 decode
  // failures hiding an ASCII route. Bound repeated escaping work to four passes.
  for (let round = 0; round < 4; round += 1) {
    const decoded = path.replace(/%([0-9a-f]{2})/gi, (_match, hex) => String.fromCharCode(Number.parseInt(hex, 16)));
    if (decoded === path) break;
    path = decoded;
  }
  // Routing may normalize separators/dot segments and trim trailing slashes.
  // This URL is parsed locally only; it is never requested.
  path = new URL('https://guard.invalid' + path.replace(/\\/g, '/').replace(/\/+/g, '/')).pathname.replace(/\/+$/, '');
  return STATEFUL_READ_PATHS.includes(path);
}

/**
 * What the guard does with a request:
 * 'ignore' (not HTTP), 'allow' (read), 'stub-telemetry', or 'block'.
 */
export function classifyAppRequest(appOrigin, method, url, { allowTransactionTelemetry = false } = {}) {
  if (!isHttpUrl(url)) return 'ignore';
  const verb = String(method || '').toUpperCase();
  if (isKnownStatefulRead(appOrigin, verb, url)) return 'block';
  if (READ_ONLY_METHODS.includes(verb)) return 'allow';
  if (verb === 'POST' && isAppOriginUrl(appOrigin, url)
      && new URL(String(url)).pathname === ADOPTION_TELEMETRY_PATH) {
    return 'stub-telemetry';
  }
  if (allowTransactionTelemetry && verb === 'POST' && isSentryEnvelopeUrl(url)) return 'inspect-telemetry';
  return 'block';
}

/** True when a blocked request { method, origin, path } is a known POST read to the app origin. */
export function isKnownPostRead(appOrigin, blocked) {
  return Boolean(appOrigin) && blocked?.method === 'POST' && blocked.origin === appOrigin
    && KNOWN_POST_READ_PATHS.includes(blocked.path);
}

/** Classify a guard-produced blocked record using the same route matcher. */
export function isKnownBlockedStatefulRead(appOrigin, blocked) {
  return Boolean(blocked && blocked.origin === appOrigin && typeof blocked.path === 'string'
    && blocked.path.startsWith('/')
    && isKnownStatefulRead(appOrigin, blocked.method, blocked.origin + blocked.path));
}

/** Create-entry acceptance inspects blocked records, never merely observed URLs. */
export function unexpectedCreateEntryRequests(appOrigin, blocked) {
  return blocked.filter(request => !isKnownPostRead(appOrigin, request)
    && !isKnownBlockedStatefulRead(appOrigin, request));
}

/**
 * Install the guard on a browser context (covers popups). Returns the list
 * that collects blocked methods and known stateful reads as { method, origin, path }.
 */
export async function installReadOnlyAppGuard(context, appOrigin, options = {}) {
  const blocked = [];
  const telemetryCounts = { transaction: 0, error: 0, mixed: 0, unknown: 0, malformed: 0, oversize: 0 };
  Object.defineProperty(blocked, 'telemetryCounts', { value: telemetryCounts });
  await context.route(isHttpUrl, async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();
    const action = classifyAppRequest(appOrigin, method, request.url(), options);
    if (action === 'block') {
      const url = new URL(request.url());
      blocked.push({ method, origin: url.origin, path: url.pathname });
      await route.abort('blockedbyclient');
    } else if (action === 'stub-telemetry') {
      await route.fulfill({ status: 204, body: '' });
    } else if (action === 'inspect-telemetry') {
      const type = classifySentryEnvelope(request.postDataBuffer());
      telemetryCounts[type] += 1;
      if (type === 'transaction') await route.fulfill({ status: 204, body: '' });
      else {
        const url = new URL(request.url());
        blocked.push({ method, origin: url.origin, path: url.pathname });
        await route.abort('blockedbyclient');
      }
    } else {
      await route.continue();
    }
  });
  return blocked;
}
