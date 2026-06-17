// k6 load test for issue #79's event scale-prep verification.
// Runbook: ./2026-06-17-event-scale-prep-load-test-runbook.md
//
// Usage:
//   BASE=http://<nas-lan-ip>:8088 \
//   SLUG=<your-event-slug> \
//   T_VALUES=12:00,12:05,12:10,12:15 \
//   k6 run loadtest.js
//
// Pass criteria (spec §6.9): p95 gallery < 500 ms, p95 thumb < 200 ms, zero 5xx.

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE     = __ENV.BASE;
const SLUG     = __ENV.SLUG;
const T_VALUES = (__ENV.T_VALUES || '12:00').split(',').map((s) => s.trim()).filter(Boolean);

if (!BASE || !SLUG || T_VALUES.length === 0) {
    throw new Error('BASE, SLUG, and T_VALUES (comma-separated) env vars are required.');
}

export const options = {
    scenarios: {
        spike: {
            executor: 'constant-vus',
            vus: 200,
            duration: '5m',
        },
    },
    thresholds: {
        'http_req_failed':                 ['rate<0.001'],   // zero 5xx target
        'http_req_duration{kind:gallery}': ['p(95)<500'],    // §6.9 HTML
        'http_req_duration{kind:thumb}':   ['p(95)<200'],    // §6.9 thumb
        'http_req_duration{kind:preview}': ['p(95)<400'],    // larger payload, looser
    },
};

export default function () {
    // 1) Pick one of N gallery URLs at random. Different `t=` → different ETag
    //    cache key → mix of cold (full render) and warm (304) responses, which
    //    is what the post-notification spike actually looks like in production.
    const t          = T_VALUES[Math.floor(Math.random() * T_VALUES.length)];
    const galleryUrl = `${BASE}/e/${SLUG}/photos?t=${t}`;
    const r          = http.get(galleryUrl, { tags: { kind: 'gallery', t: t } });
    check(r, { 'gallery 200 or 304': (res) => res.status === 200 || res.status === 304 });

    // 304 has no body to scrape ids from; just stop here for those iterations.
    // (Real browsers wouldn't re-request thumbs on a 304 either — they already
    // have them cached too, because thumb responses carry `immutable` headers.)
    if (r.status !== 200) {
        sleep(1 + Math.random() * 2);
        return;
    }

    // 2) Extract a handful of photo ids from the rendered HTML.
    const ids = [...r.body.matchAll(/\/p\/(\d+)\/thumb\.jpg/g)]
        .map((m) => m[1])
        .slice(0, 20); // cap so VUs don't all fan out to 200 thumbs

    // 3) Browser fetches thumbs in parallel; approximate with batch().
    const thumbReqs = ids.map((id) => ({
        method: 'GET',
        url: `${BASE}/e/${SLUG}/p/${id}/thumb.jpg`,
        params: { tags: { kind: 'thumb' } },
    }));
    http.batch(thumbReqs);

    // 4) Lightbox open: one preview.
    if (ids.length > 0) {
        const id = ids[Math.floor(Math.random() * ids.length)];
        http.get(`${BASE}/e/${SLUG}/p/${id}/preview.jpg`, { tags: { kind: 'preview' } });
    }

    // Visitor reads for a beat.
    sleep(1 + Math.random() * 2);
}
