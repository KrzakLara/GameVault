// Limited-inventory contention: VUS customers race for a title with STOCK keys at the same
// moment. Correct behaviour: exactly STOCK orders (201), everybody else a clean "sold out" (409),
// no 5xx. scripts/loadtest.sh then checks the database for oversell and double allocation.
import { check } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { checkout, login, summary, useSession, uuid } from './lib.js';

const VUS = Number(__ENV.VUS || 60);
const STOCK = Number(__ENV.STOCK || 10);
const GAME = __ENV.GAME || 'limited-drop';

const created = new Counter('checkout_created');
const soldOut = new Counter('checkout_sold_out');
const unexpected = new Counter('checkout_unexpected');
const checkoutLatency = new Trend('checkout_latency_ms', true);

export const options = {
    setupTimeout: '5m',
    scenarios: { race: { executor: 'per-vu-iterations', vus: VUS, iterations: 1, maxDuration: '2m' } },
    thresholds: {
        checkout_created: [`count==${STOCK}`],
        checkout_sold_out: [`count==${VUS - STOCK}`],
        checkout_unexpected: ['count==0'],
    },
};

export function setup() {
    // Accounts after the burst scenario's, so nobody has an order for this title yet.
    return Array.from({ length: VUS }, (_, i) => login(`load${i + 101}@e2e.test`));
}

export default function (sessions) {
    useSession(sessions[__VU - 1]);

    const res = checkout(GAME, uuid());
    checkoutLatency.add(res.timings.duration);
    if (res.status === 201) created.add(1);
    else if (res.status === 409) soldOut.add(1);
    else unexpected.add(1);
    check(res, { 'created or sold out': (r) => r.status === 201 || r.status === 409 });
}

export function handleSummary(data) {
    return summary('contention', data, ['checkout_created', 'checkout_sold_out', 'checkout_unexpected', 'checkout_latency_ms']);
}
