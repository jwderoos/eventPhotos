# Event scale-prep — load test runbook

**Driver:** validate spec `2026-06-16-event-scale-prep-design.md` §6.9 end-to-end on the actual TrueNAS box before the real-world event.

**Pass criteria:** 200 concurrent VUs against a real gallery URL for 5 min, **p95 < 500 ms gallery HTML**, **p95 < 200 ms thumb**, **zero 5xx**.

---

## 1. Run *from your Mac*, not the NAS

The whole point is to measure the NAS-side stack under load. Driving the load from the NAS would double-count CPU and the numbers would be misleading. Use your Mac on the same LAN, hitting the NAS directly on `:8088`. Skip Nginx Proxy Manager / TLS for the first run — it isolates the app stack. Do a second pass via NPM (your real public hostname) once the direct test passes; that validates the full edge path including TLS + proxy buffering + `X-Forwarded-*` trust chain.

## 2. Seed realistic test data

The gallery query + thumb fan-out only stresses the stack if there are enough Ready photos in the window. SSH to the NAS, then either reuse an existing event with ~50–200 Ready photos in a single 15-minute window (the gallery's `WINDOW_BEFORE_MINUTES=10` + `WINDOW_AFTER_MINUTES=5`), or create a fresh event via `/admin` and upload ~50 JPEGs spaced minutes apart.

Note the slug + four `t=HH:mm` values that each fall inside the event window — the script picks one at random per VU iteration to exercise both warm (already-cached) and cold (new cache-key) paths. Without that diversity every VU would hit the same gallery URL and the test would mostly measure same-key cache hits, not the realistic mix.

## 3. Install k6 on your Mac

```sh
brew install k6
```

## 4. The script lives at `loadtest.js` (alongside this runbook)

Single source of truth — see [`loadtest.js`](./loadtest.js) in this directory. Edit there, not in the runbook. The script reads `BASE`, `SLUG`, and `T_VALUES` from the environment and enforces all four spec §6.9 thresholds via k6 `thresholds:`.

## 5. Run

From this directory on your Mac:

```sh
BASE=http://<nas-lan-ip>:8088 \
SLUG=<your-event-slug> \
T_VALUES=12:00,12:05,12:10,12:15 \
k6 run loadtest.js
```

## 6. In parallel, SSH to the NAS and watch four things

Open four tmux/iTerm panes — these are the diagnostics that tell you *what* broke if the test fails:

```sh
# Pane 1: resource pressure per service
docker stats eventfotos-prod-php-1 eventfotos-prod-worker-1 \
    eventfotos-prod-nginx-1 eventfotos-prod-database-1

# Pane 2: SIGSEGV watch. We left JIT on after the §3.8 verification; this is
#         the real test on the production image under production-shaped load.
docker logs -f eventfotos-prod-php-1 2>&1 | grep --line-buffered -iE 'sigsegv|signal 11|child .* exited'

# Pane 3: Postgres connection count under load. Spec §3.5 set max_connections=200.
watch -n 2 "docker exec eventfotos-prod-database-1 \
    psql -U eventfotos -d eventfotos -c \
    \"SELECT count(*), state FROM pg_stat_activity GROUP BY state\""

# Pane 4: nginx upstream timeouts / 5xx
docker logs -f eventfotos-prod-nginx-1 2>&1 | grep --line-buffered -iE 'recv|upstream|5[0-9]{2}'
```

## 7. How to read the result

k6's summary will say PASS or FAIL per threshold. Interpretation:

| symptom | likely cause | look at |
|---|---|---|
| `http_req_failed: rate=0` + all p95s under target | ship it | nothing |
| Gallery p95 climbing late in the run | cache key drifting (uploads landing mid-test?), or PG saturating | Pane 3 — is connection count >100? |
| Thumb p95 > 200 ms | thumbs still going through PHP somehow (§3.1 not working), or disk slow | `docker logs eventfotos-prod-php-1` — should see only gallery requests, no thumb traffic. If you see thumb traffic in PHP logs, X-Accel didn't take effect. |
| `SQLSTATE[08006] Cannot assign requested address` 5xx | ephemeral-port exhaustion (the side-finding from §3.8); known issue tracked in spec §8 | Pane 4. Mitigation is a separate ticket. |
| FPM master PID changes during test | SIGSEGV. JIT decision needs to revert. | Pane 2 will show signal-11 lines. Set `opcache.jit = off` and `opcache.jit_buffer_size = 0` in `docker/php/php-prod.ini`, redeploy. |
| Postgres connection count plateaus at exactly 200 | `max_connections` is the limiter, not the workload | Spec §3.5 set this to 200; if it's the bottleneck, bump to 300 and re-test. |
| Resource cap nearly maxed in `docker stats` | service under-sized for actual workload | Spec §3.6. Usually `php` is the candidate (FPM children × peak RSS). |
| All gallery responses are 304 | T_VALUES are all already-cached; cold path not being exercised | Add more T values (e.g. one per minute across the event window) so cache keys diverge. |
| All gallery responses are 200 | warm path not being exercised; ETag invalidating on every request | Check that nothing is uploading photos to that event during the test (uploads bump `lastReadyUpdatedAtForEvent`, invalidating the ETag every request). |

## 8. Second pass: through NPM

Once the direct test passes, re-run with `BASE=https://photos.example.com` (your NPM-fronted hostname) and the same SLUG/T_VALUES. This validates the full edge path — TLS handshake, NPM's proxy buffering, and the `TRUSTED_PROXIES=REMOTE_ADDR` chain. Compare numbers: a small p95 increase from TLS is normal; anything dramatic points at NPM mis-tuning.

## 9. One caveat about how realistic this is

200 VUs sounds like 200 visitors but it's not quite — each VU sleeps 1–3 s between cycles, so you're driving ~50–150 gallery loads/sec which dwarfs the actual 8000-visitor spike (after the staggered email blast, even at the worst minute you'll see at most a few hundred concurrent active visitors over a few minutes). The test is intentionally above the expected real spike — failing it doesn't mean prod will fail, but passing it gives you a comfortable buffer.

## 10. After the test

- Save k6's summary output (the table at the end) — paste into a comment on issue #79 as the verification record.
- If anything tripped, write up the diagnosis + remedy in the same comment. The spec doesn't change unless the architecture needs to; the runbook here is the source for *how to repeat the test* and shouldn't accumulate per-run results.
- Tear down nothing. The prod stack stays up.
