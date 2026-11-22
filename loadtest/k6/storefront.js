// Catalogue browsing and search/filtering at a constant arrival rate (open model: requests keep
// arriving even if the server slows down, so latency under load is not hidden).
import { check } from 'k6';
import http from 'k6/http';
import { BASE_URL, summary } from './lib.js';

const RATE = Number(__ENV.RATE || 30);
const DURATION = __ENV.DURATION || '60s';

export const options = {
    scenarios: {
        browse: { executor: 'constant-arrival-rate', exec: 'browse', rate: RATE, timeUnit: '1s', duration: DURATION, preAllocatedVUs: RATE, maxVUs: RATE * 4 },
        search: { executor: 'constant-arrival-rate', exec: 'search', rate: Math.ceil(RATE / 2), timeUnit: '1s', duration: DURATION, preAllocatedVUs: RATE, maxVUs: RATE * 2 },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{scenario:browse}': ['p(95)<1000'],
        'http_req_duration{scenario:search}': ['p(95)<1000'],
    },
};

const PAGES = ['/', '/games', '/games?page=2', '/games/neon-drift', '/games/cyber-arena', '/games/titan-forge'];
const QUERIES = ['search=neon', 'platform=PlayStation', 'platform=Steam&sort=price_asc', 'genre=RPG', 'in_stock=1&sort=newest', 'search=a&platform=Xbox&sort=name'];

export function browse() {
    const path = PAGES[Math.floor(Math.random() * PAGES.length)];
    const res = http.get(`${BASE_URL}${path}`, { tags: { name: path.startsWith('/games/') ? '/games/{slug}' : path } });
    check(res, { 'page 200': (r) => r.status === 200 });
}

export function search() {
    const query = QUERIES[Math.floor(Math.random() * QUERIES.length)];
    const res = http.get(`${BASE_URL}/games?${query}`, { tags: { name: '/games?filters' } });
    check(res, { 'search 200': (r) => r.status === 200 });
}

export function handleSummary(data) {
    return summary('storefront', data);
}
