// Checkout burst: VUS distinct customers are signed in during setup, then all check out at the
// same moment (per-vu-iterations starts every VU at once). Each then polls its order until it is
// delivered, which measures the client-observed fulfillment delay through the real Horizon queue.
import { check, sleep } from 'k6';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { BASE_URL, checkout, login, summary, useSession, uuid } from './lib.js';

const VUS = Number(__ENV.VUS || 100);
const GAME = __ENV.GAME || 'load-test-arena';

const created = new Counter('checkout_created');
const replayed = new Counter('checkout_replayed');
const soldOut = new Counter('checkout_sold_out');
const unexpected = new Counter('checkout_unexpected');
const checkoutLatency = new Trend('checkout_latency_ms', true);
const fulfillmentDelay = new Trend('fulfillment_delay_ms', true);
const notDelivered = new Counter('orders_not_delivered_in_time');

export const options = {
    setupTimeout: '5m',
    scenarios: { burst: { executor: 'per-vu-iterations', vus: VUS, iterations: 1, maxDuration: '5m' } },
    thresholds: {
        checkout_unexpected: ['count==0'],
        checkout_created: [`count==${VUS}`],
        orders_not_delivered_in_time: ['count==0'],
    },
};

export function setup() {
    return Array.from({ length: VUS }, (_, i) => login(`load${i + 1}@e2e.test`));
}

export default function (sessions) {
    useSession(sessions[__VU - 1]);

    const res = checkout(GAME, uuid());
    checkoutLatency.add(res.timings.duration);
    if (res.status === 201) created.add(1);
    else if (res.status === 200) replayed.add(1);
    else if (res.status === 409) soldOut.add(1);
    else unexpected.add(1);
    check(res, { 'order created': (r) => r.status === 201 });
    if (res.status !== 201) return;

    const orderId = res.json('id');
    const start = Date.now();
    while (Date.now() - start < 180_000) {
        const poll = http.get(`${BASE_URL}/my-orders/${orderId}`, { headers: { Accept: 'application/json' }, tags: { name: '/my-orders/{id} (poll)' } });
        if (poll.status === 200 && poll.json('status') === 'delivered') {
            fulfillmentDelay.add(Date.now() - start);
            return;
        }
        sleep(0.5);
    }
    notDelivered.add(1);
}

export function handleSummary(data) {
    return summary('checkout-burst', data, ['checkout_created', 'checkout_replayed', 'checkout_sold_out', 'checkout_unexpected', 'checkout_latency_ms', 'fulfillment_delay_ms', 'orders_not_delivered_in_time']);
}
