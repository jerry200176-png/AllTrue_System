// The app loads Inter / Noto Sans TC from Google Fonts (src/styles.css). When that CDN does not answer on a CI runner the
// request fails, which is not an app defect: specs that assert "no failed requests" skip it (#5659).
export const isFontCdn = (request) => /^https:\/\/fonts\.(gstatic|googleapis)\.com\//.test(request.url());
