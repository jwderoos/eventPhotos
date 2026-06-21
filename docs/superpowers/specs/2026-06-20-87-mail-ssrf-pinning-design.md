# #87 — Per-organizer mail SSRF: send-time IP pinning

**Issue:** #87 — *SSRF in per-organizer mail transport: validation decoupled from connection*
**Date:** 2026-06-20
**Status:** design approved, pending spec review

## Problem

The per-organizer SMTP transport validates the DSN host at **config-save time** and
discards the result. Every send path rebuilds the transport from the raw DSN string and
lets the OS **re-resolve the hostname at connect time**, pinning nothing. Any
`ROLE_ORGANIZER` can therefore turn a "verified" transport into authenticated SSRF into
internal infra / cloud metadata.

Two concrete vulnerabilities, one root cause (validation decoupled from connection in
time and identity):

1. **Validate-then-reconnect (DNS rebinding) — HIGH.** Save a DSN whose host points at a
   public responder, pass validation, get `markVerified()`, then flip the A/AAAA record to
   `169.254.169.254` / `127.0.0.1` / an internal relay. Every later send re-resolves and
   connects to the new target. The stored `verified` flag cannot defend against this.
2. **IPv4-mapped IPv6 bypass — HIGH.** `isPublicIp()` uses `FILTER_FLAG_NO_PRIV_RANGE |
   NO_RES_RANGE` on the textual form, which does not decode `::ffff:a.b.c.d`. An
   attacker-controlled AAAA record returning `::ffff:169.254.169.254` is treated as public
   and connects (on dual-stack Linux) to the embedded IPv4.
3. **CGNAT `100.64.0.0/10` not excluded — MEDIUM (environment-dependent).** `NO_RES_RANGE`
   does not treat RFC 6598 space as reserved.

Findings 2/3 are not isolated bugs — they are members of a vulnerability *class*: a textual
denylist that fails to see IPv4 embedded in IPv6. The same class also covers 6to4
(`2002::/16`) and Teredo (`2001:0::/32`). A two-case patch would leave the class open.

## Strategy

Make the connection itself the security boundary:

> **Resolve the host once → validate *every* returned IP with a hardened
> "provably public" classifier → connect to the literal validated IP, carrying the
> original hostname as TLS `peer_name`/SNI** — on **every** transport build (verify send and
> live send), never relying on a stored flag.

Because the socket connects by literal IP and nothing re-resolves between validation and
connect, the rebinding window is closed by construction. The SSRF guarantee is independent
of TLS: even a DSN with `verify_peer=false` can only ever reach an IP we classified as
public.

## Components

### `PublicIpInspector` (new — `src/Service/Mail/PublicIpInspector.php`)

Single hardened source of truth for "is this IP safe to connect to". Positive allowlist
(provably public), byte-level via `inet_pton` — **not** the textual `FILTER_FLAG_*` denylist.

```php
public function isPublic(string $ip): bool
```

- **IPv4** (4 packed bytes): public only if global unicast. Reject:
  `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (CGNAT), `127.0.0.0/8`, `169.254.0.0/16`,
  `172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24`, `192.88.99.0/24`, `192.168.0.0/16`,
  `198.18.0.0/15`, `198.51.100.0/24`, `203.0.113.0/24`, `224.0.0.0/3` (multicast +
  reserved, covers `224/4` and `240/4`), `255.255.255.255`.
- **IPv6** (16 packed bytes):
  1. Decode embedded IPv4 and **recurse** on the embedded address:
     - IPv4-mapped `::ffff:0:0/96` (bytes 0–9 = 0, bytes 10–11 = `0xff`),
     - deprecated IPv4-compatible `::/96` (bytes 0–11 = 0, non-zero tail),
     - 6to4 `2002::/16` (embedded v4 in bytes 2–5),
     - Teredo `2001:0000::/32` (embedded v4 in bytes 12–15, bitwise-NOT obfuscated).
  2. Reject `::1` (loopback), `::` (unspecified), `fe80::/10` (link-local),
     `fc00::/7` (ULA), `ff00::/8` (multicast).
  3. Require `2000::/3` global unicast (first byte `0x20`–`0x3f`); else reject.
- Any input `inet_pton` cannot parse → `false`.

### `DsnValidator` (refactor — `src/Service/Mail/DsnValidator.php`)

Drops its private `isPublicIp()`; delegates IP classification to `PublicIpInspector`.
Remains the **save-time UX linter** — early rejection of bad DSNs with a clear message.
It is no longer relied on as a security control; the send-time factory is.

### `PinnedTransportFactory` (new — `src/Service/Mail/PinnedTransportFactory.php`)

The send-time control. Builds a transport pinned to a validated literal IP.

```php
public function create(#[SensitiveParameter] string $dsn): TransportInterface
```

Dependencies: `DnsResolver`, `PublicIpInspector`,
`#[Autowire(service: 'mailer.transport_factory')] Transport $transports`.

Flow:
1. `Dsn::fromString($dsn)` — `MailerDsnException` → `DsnRejected::malformed`.
2. Scheme must be `smtp`/`smtps` → else `DsnRejected::scheme`.
3. Host required → else `DsnRejected::malformed`.
4. `$ips = dns->resolve($host)`; empty → `DsnRejected::unresolvable`.
5. **Every** `$ip` must satisfy `inspector->isPublic($ip)` → else `DsnRejected::host($host, $ip)`.
6. Pick one IP, **preferring an IPv4** when the set is mixed (dual-stack reliability);
   otherwise the first entry.
7. `$pinnedHost = ` IPv6 wrapped in `[...]`, IPv4 as-is.
8. `$pinned = new Dsn($scheme, $pinnedHost, $user, $password, $port, $options)` and
   `$transport = $transports->fromDsnObject($pinned)`.
   - `fromDsnObject()` routes through the **container** factory chain, so the test-only
     `InMemoryTransportFactory` interception is preserved (see Testing).
9. If `$transport instanceof EsmtpTransport`, take its `SocketStream` and **merge**
   `['ssl' => ['peer_name' => $originalHost]]` into the existing stream options (does not
   clobber DSN-supplied `ssl` options such as `verify_peer`). This keeps TLS SNI + cert
   validation bound to the hostname even though the socket connects to the IP.

No re-resolution occurs after step 4 — the connection uses the exact validated IP.

### Call-site wiring

- **`TransportBuilder::fromDsn`** (verify-send path, used by `UserMailController` /
  `AccountMailController`): injects `PinnedTransportFactory`, wraps the result in the
  existing `RenderingMailer`. A `DsnRejected` here surfaces to the controller as a
  validation/flash error (same as today's save-time validation).
- **`OrganizerMailerResolver::forUser`** (live event-send path): uses
  `PinnedTransportFactory`, wraps in `new Mailer(...)`.
  - **`SodiumException`** (corrupt ciphertext): unchanged — log + fall back to platform.
  - **`DsnRejected`**: **hard fail, propagate** (Messenger retries / dead-letters) —
    consistent with the existing "no silent fallback at send time" rule. Additionally,
    when `reason === DsnRejected::REASON_HOST` (the genuine SSRF signal), **auto-unverify**
    the config before rethrowing so subsequent sends fall back to the platform mailer until
    the organizer re-verifies. Transient `REASON_UNRESOLVABLE` / `REASON_MALFORMED` do
    **not** unverify (avoid unverifying a legitimate organizer on a DNS hiccup).
  - Gains an `EntityManagerInterface` dependency to persist the auto-unverify.

### `UserMailConfig::revokeVerification()` (new method — `src/Entity/UserMailConfig.php`)

Minimal unverify distinct from `regenerateVerificationToken()` (which also issues a new
token + timestamp). Nulls `verifiedAt`, bumps `updatedAt`, leaves the token untouched.
Idempotent (no-op when already unverified). The organizer re-verifies through the normal
flow.

## Error handling / security notes

- SSRF closure does not depend on TLS. `peer_name` only preserves working cert validation
  for honest users.
- `fromDsnObject()` does not open a socket; connection is lazy (on send). So no DNS lookup
  happens at build other than our own `DnsResolver` call, and the chosen IP is what
  connects.
- Scheme is enforced to `smtp`/`smtps`, so in production `fromDsnObject` always yields an
  `EsmtpTransport` with a `SocketStream`; the `instanceof` guard around `peer_name` is
  defensive, not a fallback path.

## Testing

- **`tests/Unit/Service/Mail/PublicIpInspectorTest.php`** (new) — table-driven:
  - public: `93.184.216.34`, `8.8.8.8`, `2606:4700:4700::1111`.
  - rejected v4: `127.0.0.1`, `10.0.0.1`, `192.168.1.1`, `172.16.0.1`, `169.254.169.254`,
    `100.64.0.1` (CGNAT), `224.0.0.1`, `240.0.0.1`, `255.255.255.255`, `0.0.0.0`.
  - rejected v6: `::1`, `::`, `fe80::1`, `fc00::1`, `ff02::1`,
    `::ffff:127.0.0.1`, `::ffff:169.254.169.254`, `::ffff:10.0.0.1`,
    `2002:7f00:0001::` (6to4 → `127.0.0.1`), a Teredo address embedding an internal v4.
  - garbage input → `false`.
- **`tests/Integration/Service/Mail/PinnedTransportFactoryTest.php`** (new, real
  `EsmtpTransportFactory`):
  - public host → returns `EsmtpTransport` whose `getStream()->getHost()` is the literal IP
    and whose stream options carry `ssl.peer_name === originalHost`.
  - host resolving to `127.0.0.1` / `::ffff:169.254.169.254` / `100.64.0.1` → `DsnRejected`
    with `REASON_HOST` (rebound / mapped-v6 / CGNAT **refused at send time**).
- **`tests/Unit/Service/Mail/DsnValidatorTest.php`** — adjusted to the shared inspector;
  existing cases keep passing.
- **Functional** (`UserMailFlowTest`, `AccountMailFlowTest`):
  - existing capture assertions change from `messagesForHost('smtp.example-organizer.test')`
    to the resolved IP `93.184.216.34` (the pin rewrites the connect host — this now
    *proves* the pin end-to-end through the container factory chain).
  - **new send-time refusal test**: a config that is already `markVerified()` whose host
    resolves (via an extended `PrebakedDnsResolver`, e.g.
    `*.rebind.example-organizer.test → 127.0.0.1` and a mapped-v6 variant) to a bad IP is
    **refused on a live event send**, asserting the control holds independent of save, and
    that the config is auto-unverified afterwards.
- **`tests/Fake/PrebakedDnsResolver.php`** — extend with a "rebound" host pattern returning
  an internal / mapped-v6 / CGNAT address.

## Out of scope / non-changes

- No DB schema, entity field, or migration changes (`revokeVerification()` only nulls an
  existing column).
- No change to platform-level mail flows (invitations, password reset) — they have no event
  context and keep the autowired `MailerInterface`.

## Documentation follow-ups

- Append a note to `docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md`:
  hostname-validate-then-reconnect-by-name is rebinding-vulnerable by construction; the
  send-time pin in `PinnedTransportFactory` is the control, not the stored `verified` flag.
- Update the "Per-organizer mail transport" section of `CLAUDE.md` to mention
  `PinnedTransportFactory` (resolve → validate → pin) and `PublicIpInspector`.

## Branch

`feature/87-mail-ssrf-pinning` off `main`.

## Acceptance criteria (from #87)

- [x] SMTP connection pinned to a validated IP, TLS hostname/SNI preserved — step 7–9.
- [x] Validation / re-pinning happens at send time, not only at save — `PinnedTransportFactory`.
- [x] `::ffff:169.254.169.254` and other IPv4-mapped IPv6 rejected — `PublicIpInspector` IPv6 decode.
- [x] `100.64.0.0/10` rejected — `PublicIpInspector` IPv4 reject list.
- [x] Functional test asserts a rebound / mapped-v6 / CGNAT host refused at send time.
- [x] (also-worth-doing) #78 spec annotated with the rebinding caveat.
