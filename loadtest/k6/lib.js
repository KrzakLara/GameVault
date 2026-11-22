// Shared helpers for the GameVault k6 scenarios. Target: the isolated E2E stack only
// (scripts/loadtest.sh). Accounts load1..loadN@e2e.test come from LoadTestSeeder.
import http from 'k6/http';

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
export const PASSWORD = 'password';
const SESSION_COOKIE = 'gamevault_session';

/** Signs one account in with its own cookie jar and returns the cookies needed to act as it. */
export function login(email) {
    const jar = new http.CookieJar();
    http.get(`${BASE_URL}/login`, { jar, tags: { name: 'login page' } });
    const res = http.post(
        `${BASE_URL}/login`,
        { email, password: PASSWORD },
        { jar, headers: { 'X-XSRF-TOKEN': xsrf(jar), Accept: 'text/html' }, tags: { name: 'login' } },
    );
    if (res.status !== 200 || !res.url.endsWith('/dashboard')) {
        throw new Error(`login failed for ${email}: HTTP ${res.status} at ${res.url}`);
    }
    const cookies = jar.cookiesForURL(BASE_URL);

    return { session: cookies[SESSION_COOKIE][0], xsrf: cookies['XSRF-TOKEN'][0] };
}

/** Restores a signed-in session (from setup()) into this VU's cookie jar. */
export function useSession(session) {
    const jar = http.cookieJar();
    jar.set(BASE_URL, SESSION_COOKIE, session.session);
    jar.set(BASE_URL, 'XSRF-TOKEN', session.xsrf);
}

export function xsrf(jar = http.cookieJar()) {
    const value = jar.cookiesForURL(BASE_URL)['XSRF-TOKEN'];
    return value ? decodeURIComponent(value[0]) : '';
}

export function checkout(slug, idempotencyKey) {
    return http.post(`${BASE_URL}/games/${slug}/checkout`, JSON.stringify({ idempotency_key: idempotencyKey }), {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        tags: { name: 'checkout' },
        responseCallback: http.expectedStatuses(200, 201, 409),
    });
}

/** RFC 4122 v4 shaped id; uniqueness is all an idempotency key needs here. */
export function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/** Compact console summary plus the full k6 summary as JSON for the report. */
export function summary(name, data, extra = []) {
    const m = data.metrics;
    const fmt = (v) => (v === undefined ? 'n/a' : `${v.toFixed(1)}`);
    const lines = [
        `== ${name}`,
        `requests: ${m.http_reqs.values.count} (${m.http_reqs.values.rate.toFixed(1)}/s), failed: ${(m.http_req_failed.values.rate * 100).toFixed(2)}%`,
        `latency ms: avg ${fmt(m.http_req_duration.values.avg)}, p50 ${fmt(m.http_req_duration.values.med)}, p90 ${fmt(m.http_req_duration.values['p(90)'])}, p95 ${fmt(m.http_req_duration.values['p(95)'])}, p99 ${fmt(m.http_req_duration.values['p(99)'])}, max ${fmt(m.http_req_duration.values.max)}`,
        ...extra.map((key) => {
            const metric = m[key];
            if (!metric) return `${key}: n/a`;
            const v = metric.values;
            return v.count !== undefined && v.avg === undefined
                ? `${key}: ${v.count}`
                : `${key}: avg ${fmt(v.avg)}, p50 ${fmt(v.med)}, p95 ${fmt(v['p(95)'])}, max ${fmt(v.max)}`;
        }),
        `thresholds: ${Object.entries(m)
            .filter(([, metric]) => metric.thresholds)
            .map(([key, metric]) => `${key} ${Object.values(metric.thresholds).every((t) => t.ok) ? 'ok' : 'FAILED'}`)
            .join(', ')}`,
    ];

    return { stdout: lines.join('\n') + '\n', [`/results/${name}.json`]: JSON.stringify(data, null, 2) };
}
