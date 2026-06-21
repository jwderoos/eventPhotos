# Mail SSRF Send-Time IP Pinning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two HIGH SSRF holes (DNS rebinding + IPv4-mapped-IPv6) and the CGNAT gap in the per-organizer SMTP transport by validating and pinning the connection to a literal public IP at send time, while preserving the hostname for TLS.

**Architecture:** A single hardened `PublicIpInspector` (positive allowlist, byte-level) becomes the one classifier. A new `PinnedTransportFactory` resolves the DSN host, validates **every** returned IP, rebuilds the `Dsn` with the literal IP, builds the transport through the container factory (`fromDsnObject`, preserving test interception), and sets the original hostname as TLS `peer_name`. Both send paths (`TransportBuilder`, `OrganizerMailerResolver`) route through it; the resolver hard-fails on rejection and auto-unverifies on the SSRF signal.

**Tech Stack:** PHP 8.5, Symfony 8 Mailer, Doctrine ORM 3, PHPUnit 13, PHPStan level 10, GrumPHP.

## Global Constraints

- PHP attributes only — no annotations.
- PHPStan level 10 across `src`, `tests`, `public`; PSR-12 (`phpcs`); no magic numbers in `src/` (`phpmnd` — name every numeric literal as a `const`); no 50-line/100-token duplication (`phpcpd`); `rector`; `doctrine:schema:validate`.
- All commands run on the **host** (PHP 8.5 via Homebrew): `vendor/bin/phpunit`, `vendor/bin/grumphp run`.
- No DB schema / migration changes (this work touches no mapped columns beyond nulling an existing one).
- Branch: `feature/87-mail-ssrf-pinning` (already created). Claude does not run `git commit`; each task ends by staging and proposing a one-line commit message for the user.
- When injecting a specific transport factory use `#[Autowire(service: 'mailer.transport_factory')] Transport $transports`.

---

## File Structure

- **Create** `src/Service/Mail/PublicIpInspector.php` — the single "provably public IP?" classifier.
- **Create** `src/Service/Mail/PinnedTransportFactory.php` — resolve → validate → pin → build transport.
- **Modify** `src/Service/Mail/DsnValidator.php` — delegate IP classification to `PublicIpInspector`.
- **Modify** `src/Service/Mail/TransportBuilder.php` — build via `PinnedTransportFactory`.
- **Modify** `src/Service/Mail/OrganizerMailerResolver.php` — build via `PinnedTransportFactory`; hard-fail + auto-unverify on `REASON_HOST`.
- **Modify** `src/Entity/UserMailConfig.php` — add `revokeVerification()`.
- **Create** `tests/Unit/Service/Mail/PublicIpInspectorTest.php`.
- **Create** `tests/Unit/Service/Mail/PinnedTransportFactoryTest.php`.
- **Modify** `tests/Unit/Service/Mail/DsnValidatorTest.php` — add mapped-v6 / CGNAT rejections.
- **Modify** `tests/Unit/Entity/UserMailConfigTest.php` — cover `revokeVerification()`.
- **Modify** `tests/Fake/PrebakedDnsResolver.php` — distinct public IPs + rebind hosts.
- **Modify** `tests/Integration/Service/Mail/OrganizerMailerResolverTest.php` — mapped hosts + send-time refusal/auto-unverify.
- **Modify** `tests/Functional/Admin/UserMailFlowTest.php`, `tests/Functional/Admin/AccountMailFlowTest.php` — capture-by-IP; add send-time refusal functional test.
- **Modify** `CLAUDE.md`, `docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md` — docs.

---

## Task 1: `PublicIpInspector` — the hardened classifier

**Files:**
- Create: `src/Service/Mail/PublicIpInspector.php`
- Test: `tests/Unit/Service/Mail/PublicIpInspectorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `final readonly class PublicIpInspector` with `public function isPublic(string $ip): bool`. Returns `true` only for provably-global-unicast IPv4/IPv6; `false` for anything private/reserved/loopback/link-local/CGNAT/multicast, any IPv6 carrying an embedded IPv4 that is itself non-public, and any unparseable input.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\PublicIpInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicIpInspectorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function publicIps(): iterable
    {
        yield 'v4 example.com' => ['93.184.216.34'];
        yield 'v4 google dns' => ['8.8.8.8'];
        yield 'v6 cloudflare' => ['2606:4700:4700::1111'];
    }

    #[DataProvider('publicIps')]
    public function testAcceptsPublicIps(string $ip): void
    {
        $this->assertTrue(new PublicIpInspector()->isPublic($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedIps(): iterable
    {
        yield 'v4 loopback' => ['127.0.0.1'];
        yield 'v4 rfc1918 10' => ['10.0.0.1'];
        yield 'v4 rfc1918 192.168' => ['192.168.1.1'];
        yield 'v4 rfc1918 172.16' => ['172.16.0.1'];
        yield 'v4 link-local' => ['169.254.169.254'];
        yield 'v4 cgnat' => ['100.64.0.1'];
        yield 'v4 cgnat top' => ['100.127.255.255'];
        yield 'v4 multicast' => ['224.0.0.1'];
        yield 'v4 reserved 240' => ['240.0.0.1'];
        yield 'v4 broadcast' => ['255.255.255.255'];
        yield 'v4 unspecified' => ['0.0.0.0'];
        yield 'v6 loopback' => ['::1'];
        yield 'v6 unspecified' => ['::'];
        yield 'v6 link-local' => ['fe80::1'];
        yield 'v6 ula' => ['fc00::1'];
        yield 'v6 multicast' => ['ff02::1'];
        yield 'v6 mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'v6 mapped metadata' => ['::ffff:169.254.169.254'];
        yield 'v6 mapped rfc1918' => ['::ffff:10.0.0.1'];
        yield 'v6 6to4 loopback' => ['2002:7f00:0001::'];
        yield 'v6 teredo rfc1918' => ['2001:0000:4136:e378:8000:63bf:3fff:fdd2'];
        yield 'garbage' => ['not-an-ip'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejectedIps')]
    public function testRejectsNonPublicIps(string $ip): void
    {
        $this->assertFalse(new PublicIpInspector()->isPublic($ip));
    }
}
```

Note on the Teredo case: `2001:0000:4136:e378:8000:63bf:3fff:fdd2` carries the obfuscated embedded IPv4 in the last 4 bytes (`3fff:fdd2` → bitwise-NOT → `192.0.2.45`-style); the embedded address resolves to a non-public/reserved address and must be rejected. The 6to4 case `2002:7f00:0001::` embeds `127.0.0.1` (`0x7f000001`).

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/PublicIpInspectorTest.php`
Expected: FAIL — `Class "App\Service\Mail\PublicIpInspector" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Positive-allowlist IP classifier: returns true only for addresses that are provably
 * global-unicast (safe to open an outbound SMTP socket to). Works on the packed bytes
 * (inet_pton), so it sees IPv4 embedded in IPv6 (mapped / 6to4 / Teredo) that textual
 * FILTER_FLAG_* checks miss.
 */
final readonly class PublicIpInspector
{
    private const int V4_BYTE_COUNT = 4;

    private const int V6_BYTE_COUNT = 16;

    public function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        return match (\strlen($packed)) {
            self::V4_BYTE_COUNT => $this->isPublicV4($packed),
            self::V6_BYTE_COUNT => $this->isPublicV6($packed),
            default => false,
        };
    }

    private function isPublicV4(string $packed): bool
    {
        /** @var list<int> $b */
        $b = array_values((array) unpack('C4', $packed));

        // 0.0.0.0/8 (incl. unspecified)
        if ($b[0] === 0) {
            return false;
        }
        // 10.0.0.0/8
        if ($b[0] === 10) {
            return false;
        }
        // 100.64.0.0/10 CGNAT (RFC 6598)
        if ($b[0] === 100 && $b[1] >= 64 && $b[1] <= 127) {
            return false;
        }
        // 127.0.0.0/8 loopback
        if ($b[0] === 127) {
            return false;
        }
        // 169.254.0.0/16 link-local
        if ($b[0] === 169 && $b[1] === 254) {
            return false;
        }
        // 172.16.0.0/12
        if ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31) {
            return false;
        }
        // 192.0.0.0/24, 192.0.2.0/24, 192.88.99.0/24, 192.168.0.0/16
        if ($b[0] === 192) {
            if ($b[1] === 0 && ($b[2] === 0 || $b[2] === 2)) {
                return false;
            }
            if ($b[1] === 88 && $b[2] === 99) {
                return false;
            }
            if ($b[1] === 168) {
                return false;
            }
        }
        // 198.18.0.0/15 benchmark, 198.51.100.0/24 TEST-NET-2
        if ($b[0] === 198) {
            if ($b[1] === 18 || $b[1] === 19) {
                return false;
            }
            if ($b[1] === 51 && $b[2] === 100) {
                return false;
            }
        }
        // 203.0.113.0/24 TEST-NET-3
        if ($b[0] === 203 && $b[1] === 0 && $b[2] === 113) {
            return false;
        }
        // 224.0.0.0/3 — multicast (224/4) + reserved (240/4) + 255.255.255.255
        return $b[0] < 224;
    }

    private function isPublicV6(string $packed): bool
    {
        /** @var list<int> $b */
        $b = array_values((array) unpack('C16', $packed));

        // IPv4-mapped ::ffff:0:0/96
        if ($this->zeroRange($b, 0, 9) && $b[10] === 0xff && $b[11] === 0xff) {
            return $this->isPublicV4(pack('C4', $b[12], $b[13], $b[14], $b[15]));
        }
        // deprecated IPv4-compatible ::/96 (non-zero tail) — also catches ::1
        if ($this->zeroRange($b, 0, 11) && !$this->zeroRange($b, 12, 15)) {
            return $this->isPublicV4(pack('C4', $b[12], $b[13], $b[14], $b[15]));
        }
        // 6to4 2002::/16 — embedded v4 in bytes 2..5
        if ($b[0] === 0x20 && $b[1] === 0x02) {
            return $this->isPublicV4(pack('C4', $b[2], $b[3], $b[4], $b[5]));
        }
        // Teredo 2001:0000::/32 — embedded v4 in bytes 12..15, bitwise-NOT obfuscated
        if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x00 && $b[3] === 0x00) {
            return $this->isPublicV4(
                pack('C4', $b[12] ^ 0xff, $b[13] ^ 0xff, $b[14] ^ 0xff, $b[15] ^ 0xff),
            );
        }
        // :: unspecified
        if ($this->zeroRange($b, 0, 15)) {
            return false;
        }
        // fe80::/10 link-local
        if ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80) {
            return false;
        }
        // fc00::/7 ULA
        if (($b[0] & 0xfe) === 0xfc) {
            return false;
        }
        // ff00::/8 multicast
        if ($b[0] === 0xff) {
            return false;
        }
        // require 2000::/3 global unicast
        return ($b[0] & 0xe0) === 0x20;
    }

    /** @param list<int> $b */
    private function zeroRange(array $b, int $from, int $to): bool
    {
        for ($i = $from; $i <= $to; ++$i) {
            if ($b[$i] !== 0) {
                return false;
            }
        }

        return true;
    }
}
```

PHPStan note: the `(array) unpack(...)` cast + `array_values` yields `list<int>` for the analyser; the `0xff` / `0xc0` masks and named `224` boundary keep `phpmnd` quiet for the structural constants while small bit masks are conventional. If `phpmnd` flags any literal, promote it to a named `private const`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/PublicIpInspectorTest.php`
Expected: PASS (all data rows green).

- [ ] **Step 5: Static analysis on the new file**

Run: `vendor/bin/phpstan analyse src/Service/Mail/PublicIpInspector.php tests/Unit/Service/Mail/PublicIpInspectorTest.php`
Expected: `[OK] No errors`. (If `phpmnd` later flags bit masks during `grumphp run`, add named consts.)

- [ ] **Step 6: Stage and propose commit**

```bash
git add src/Service/Mail/PublicIpInspector.php tests/Unit/Service/Mail/PublicIpInspectorTest.php
```
Proposed message: `87 - add hardened PublicIpInspector positive-allowlist IP classifier`

---

## Task 2: Refactor `DsnValidator` to the shared classifier

**Files:**
- Modify: `src/Service/Mail/DsnValidator.php`
- Test: `tests/Unit/Service/Mail/DsnValidatorTest.php`

**Interfaces:**
- Consumes: `PublicIpInspector::isPublic()` (Task 1), `DnsResolver`.
- Produces: unchanged public surface — `DsnValidator::__construct(DnsResolver $dns, PublicIpInspector $inspector)` and `validate(string $dsn): void`. Behaviour now rejects mapped-v6 / CGNAT too.

- [ ] **Step 1: Add failing rejection cases to the existing test**

In `tests/Unit/Service/Mail/DsnValidatorTest.php`, update **every** `new DsnValidator($dns)` / `new DsnValidator(new FakeDnsResolver())` construction to pass the inspector, e.g.:

```php
use App\Service\Mail\PublicIpInspector;
// ...
$validator = new DsnValidator($dns, new PublicIpInspector());
```

(There are six construction sites: lines ~20, 32, 52, 84, 101, 110, 123 in the current file — update each.)

Then extend the `rejectedHosts()` provider with the class that the old denylist missed:

```php
        yield 'cgnat v4' => ['100.64.0.1', '100.64.0.1'];
        yield 'mapped metadata v6' => ['::ffff:169.254.169.254', '::ffff:169.254.169.254'];
        yield 'mapped rfc1918 v6' => ['::ffff:10.0.0.1', '::ffff:10.0.0.1'];
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/DsnValidatorTest.php`
Expected: FAIL — constructor now needs a second argument / the new mapped rows are not yet rejected by the old `isPublicIp`.

- [ ] **Step 3: Refactor `DsnValidator` to delegate**

Replace the body of `src/Service/Mail/DsnValidator.php` with (remove the private `isPublicIp`, the multicast consts, and the unused `inet_pton`/`ord` logic — they now live in `PublicIpInspector`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerDsnException;
use Symfony\Component\Mailer\Transport\Dsn;

final readonly class DsnValidator
{
    /** @var list<string> */
    private const array ALLOWED_SCHEMES = ['smtp', 'smtps'];

    public function __construct(
        private DnsResolver $dns,
        private PublicIpInspector $inspector,
    ) {
    }

    public function validate(#[SensitiveParameter] string $dsn): void
    {
        try {
            $parsed = Dsn::fromString($dsn);
        } catch (MailerDsnException $mailerDsnException) {
            throw DsnRejected::malformed($mailerDsnException->getMessage());
        }

        $scheme = $parsed->getScheme();
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw DsnRejected::scheme($scheme);
        }

        $host = $parsed->getHost();
        if ($host === '') {
            throw DsnRejected::malformed('host is required');
        }

        $ips = $this->dns->resolve($host);
        if ($ips === []) {
            throw DsnRejected::unresolvable($host);
        }

        foreach ($ips as $ip) {
            if (!$this->inspector->isPublic($ip)) {
                throw DsnRejected::host($host, $ip);
            }
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/DsnValidatorTest.php`
Expected: PASS (including the three new rejection rows).

- [ ] **Step 5: Stage and propose commit**

```bash
git add src/Service/Mail/DsnValidator.php tests/Unit/Service/Mail/DsnValidatorTest.php
```
Proposed message: `87 - delegate DsnValidator IP checks to shared PublicIpInspector`

---

## Task 3: `UserMailConfig::revokeVerification()`

**Files:**
- Modify: `src/Entity/UserMailConfig.php`
- Test: `tests/Unit/Entity/UserMailConfigTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `UserMailConfig::revokeVerification(): void` — nulls `verifiedAt`, bumps `updatedAt`, leaves `verificationToken` untouched; idempotent (no-op when already unverified). Distinct from `regenerateVerificationToken()` (which issues a new token).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Entity/UserMailConfigTest.php` (match the file's existing construction helpers; the snippet below shows the assertions — adapt the constructor call to the test's existing pattern for building a verified config):

```php
public function testRevokeVerificationClearsVerifiedState(): void
{
    $user = new User('owner@example.com', 'Owner');
    $config = new UserMailConfig(
        $user,
        // reuse whatever EncryptedDsn fixture the other tests in this file use:
        $this->sampleEnvelope(),
        'owner@example.com',
        null,
    );
    $config->markVerified();
    $this->assertTrue($config->isVerified());

    $config->revokeVerification();

    $this->assertFalse($config->isVerified());
    $this->assertNull($config->getVerifiedAt());
}

public function testRevokeVerificationIsIdempotent(): void
{
    $user = new User('owner2@example.com', 'Owner');
    $config = new UserMailConfig(
        $user,
        $this->sampleEnvelope(),
        'owner2@example.com',
        null,
    );

    $config->revokeVerification();

    $this->assertFalse($config->isVerified());
}
```

If `UserMailConfigTest` has no `sampleEnvelope()` / `getVerifiedAt()` helpers, mirror the construction already used by the existing tests in that file (read it first) and assert via `isVerified()` only.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/UserMailConfigTest.php`
Expected: FAIL — `Call to undefined method ...::revokeVerification()`.

- [ ] **Step 3: Add the method**

In `src/Entity/UserMailConfig.php`, after `markVerified()` (around line 140), add:

```php
    public function revokeVerification(): void
    {
        if (!$this->verifiedAt instanceof DateTimeImmutable) {
            return;
        }

        $this->verifiedAt = null;
        $this->updatedAt = new DateTimeImmutable();
    }
```

(If `getVerifiedAt()` does not already exist and the test asserts on it, it does — see line ~109 `return $this->verifiedAt;`. Reuse it.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/UserMailConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Confirm schema unchanged**

Run: `bin/console doctrine:schema:validate --skip-sync`
Expected: mapping OK (no column change — `revokeVerification` only writes existing fields).

- [ ] **Step 6: Stage and propose commit**

```bash
git add src/Entity/UserMailConfig.php tests/Unit/Entity/UserMailConfigTest.php
```
Proposed message: `87 - add UserMailConfig::revokeVerification for send-time unverify`

---

## Task 4: `PinnedTransportFactory` — resolve, validate, pin

**Files:**
- Create: `src/Service/Mail/PinnedTransportFactory.php`
- Test: `tests/Unit/Service/Mail/PinnedTransportFactoryTest.php`

**Interfaces:**
- Consumes: `DnsResolver`, `PublicIpInspector` (Task 1), `mailer.transport_factory` (Symfony `Transport`), `DsnRejected`.
- Produces: `PinnedTransportFactory::create(string $dsn): TransportInterface`. Resolves the host, requires **every** IP `isPublic`, rebuilds the `Dsn` with the chosen literal IP (IPv4 preferred; IPv6 bracketed), builds via `Transport::fromDsnObject()`, and for an `EsmtpTransport` merges `ssl.peer_name = originalHost` into the `SocketStream` options. Throws `DsnRejected` (`malformed`/`scheme`/`unresolvable`/`host`).

- [ ] **Step 1: Write the failing test**

Built as a **pure unit test** with a real `EsmtpTransportFactory` (the container's test transport factory is the in-memory one — priority 100 — so we construct a real factory here to assert the pin against an actual `SocketStream`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\DsnRejected;
use App\Service\Mail\PinnedTransportFactory;
use App\Service\Mail\PublicIpInspector;
use App\Tests\Fake\FakeDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class PinnedTransportFactoryTest extends TestCase
{
    private function factory(FakeDnsResolver $dns): PinnedTransportFactory
    {
        return new PinnedTransportFactory(
            $dns,
            new PublicIpInspector(),
            new Transport([new EsmtpTransportFactory()]),
        );
    }

    public function testPinsToValidatedIpv4AndPreservesHostnameForTls(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('smtp.example.com', ['93.184.216.34']);

        $transport = $this->factory($dns)->create('smtp://user:pass@smtp.example.com:587');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('93.184.216.34', $stream->getHost());
        $this->assertSame(587, $stream->getPort());
        $this->assertSame('smtp.example.com', $stream->getStreamOptions()['ssl']['peer_name'] ?? null);
    }

    public function testPrefersIpv4WhenHostIsDualStack(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('mail.example.com', ['2606:4700:4700::1111', '93.184.216.34']);

        $transport = $this->factory($dns)->create('smtps://u:p@mail.example.com:465');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('93.184.216.34', $stream->getHost());
    }

    public function testBracketsLiteralIpv6Host(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('v6.example.com', ['2606:4700:4700::1111']);

        $transport = $this->factory($dns)->create('smtps://u:p@v6.example.com:465');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('[2606:4700:4700::1111]', $stream->getHost());
    }

    /** @return iterable<string, array{string}> */
    public static function reboundIps(): iterable
    {
        yield 'loopback (rebind)' => ['127.0.0.1'];
        yield 'metadata mapped v6' => ['::ffff:169.254.169.254'];
        yield 'cgnat' => ['100.64.0.1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reboundIps')]
    public function testRejectsHostResolvingToNonPublicIp(string $ip): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('evil.example.com', [$ip]);

        $this->expectException(DsnRejected::class);
        try {
            $this->factory($dns)->create('smtp://u:p@evil.example.com:25');
        } catch (DsnRejected $e) {
            $this->assertSame(DsnRejected::REASON_HOST, $e->reason);
            throw $e;
        }
    }

    public function testRejectsUnresolvableHost(): void
    {
        $this->expectException(DsnRejected::class);
        try {
            $this->factory(new FakeDnsResolver())->create('smtp://u:p@nope.example.com:25');
        } catch (DsnRejected $e) {
            $this->assertSame(DsnRejected::REASON_UNRESOLVABLE, $e->reason);
            throw $e;
        }
    }

    public function testRejectsNonSmtpScheme(): void
    {
        $this->expectException(DsnRejected::class);
        try {
            $this->factory(new FakeDnsResolver())->create('sendgrid+api://KEY@default');
        } catch (DsnRejected $e) {
            $this->assertSame(DsnRejected::REASON_SCHEME, $e->reason);
            throw $e;
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/PinnedTransportFactoryTest.php`
Expected: FAIL — `Class "App\Service\Mail\PinnedTransportFactory" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerDsnException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds an SMTP transport pinned to a validated literal IP, immediately before connect.
 *
 * Resolves the DSN host once, requires every returned IP to be provably public, then
 * connects to the chosen literal IP while passing the original hostname as the TLS
 * peer_name (SNI + cert validation). Nothing re-resolves the hostname afterwards, so the
 * validate-then-reconnect (DNS rebinding) window is closed by construction.
 */
final readonly class PinnedTransportFactory
{
    /** @var list<string> */
    private const array ALLOWED_SCHEMES = ['smtp', 'smtps'];

    public function __construct(
        private DnsResolver $dns,
        private PublicIpInspector $inspector,
        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transports,
    ) {
    }

    public function create(#[SensitiveParameter] string $dsn): TransportInterface
    {
        try {
            $parsed = Dsn::fromString($dsn);
        } catch (MailerDsnException $mailerDsnException) {
            throw DsnRejected::malformed($mailerDsnException->getMessage());
        }

        $scheme = $parsed->getScheme();
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw DsnRejected::scheme($scheme);
        }

        $host = $parsed->getHost();
        if ($host === '') {
            throw DsnRejected::malformed('host is required');
        }

        $ips = $this->dns->resolve($host);
        if ($ips === []) {
            throw DsnRejected::unresolvable($host);
        }

        foreach ($ips as $ip) {
            if (!$this->inspector->isPublic($ip)) {
                throw DsnRejected::host($host, $ip);
            }
        }

        $pinnedIp = $this->pick($ips);
        $pinnedHost = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;

        $transport = $this->transports->fromDsnObject(new Dsn(
            $scheme,
            $pinnedHost,
            $parsed->getUser(),
            $parsed->getPassword(),
            $parsed->getPort(),
            $parsed->getOptions(),
        ));

        $this->preserveTlsHostname($transport, $host);

        return $transport;
    }

    /**
     * @param non-empty-list<string> $ips
     */
    private function pick(array $ips): string
    {
        foreach ($ips as $ip) {
            if (!str_contains($ip, ':')) {
                return $ip;
            }
        }

        return $ips[0];
    }

    private function preserveTlsHostname(TransportInterface $transport, string $host): void
    {
        if (!$transport instanceof EsmtpTransport) {
            return;
        }

        $stream = $transport->getStream();
        if (!$stream instanceof SocketStream) {
            return;
        }

        $options = $stream->getStreamOptions();
        $options['ssl']['peer_name'] = $host;
        $stream->setStreamOptions($options);
    }
}
```

PHPStan note: `Dsn::getOptions()` returns `array`; `fromDsnObject()` returns `TransportInterface`. The `instanceof` guards keep level 10 happy. `$ips` from `resolve()` is `list<string>` and we have already thrown on `=== []`, so `pick()` receives `non-empty-list<string>`; if PHPStan cannot narrow it, add `\assert($ips !== [])` before the `pick($ips)` call.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/PinnedTransportFactoryTest.php`
Expected: PASS (all rows).

- [ ] **Step 5: Static analysis**

Run: `vendor/bin/phpstan analyse src/Service/Mail/PinnedTransportFactory.php tests/Unit/Service/Mail/PinnedTransportFactoryTest.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: Stage and propose commit**

```bash
git add src/Service/Mail/PinnedTransportFactory.php tests/Unit/Service/Mail/PinnedTransportFactoryTest.php
```
Proposed message: `87 - add PinnedTransportFactory pinning SMTP to validated literal IP`

---

## Task 5: Route `TransportBuilder` through `PinnedTransportFactory`

**Files:**
- Modify: `src/Service/Mail/TransportBuilder.php`

**Interfaces:**
- Consumes: `PinnedTransportFactory::create()` (Task 4), `BodyRendererInterface`.
- Produces: unchanged — `TransportBuilder::fromDsn(string $dsn): MailerInterface` (still wraps in `RenderingMailer`), but now pins. May throw `DsnRejected`.

- [ ] **Step 1: Replace the transport source**

Rewrite `src/Service/Mail/TransportBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\BodyRendererInterface;

final readonly class TransportBuilder
{
    public function __construct(
        private PinnedTransportFactory $pinnedTransports,
        private BodyRendererInterface $bodyRenderer,
    ) {
    }

    public function fromDsn(#[SensitiveParameter] string $dsn): MailerInterface
    {
        return new RenderingMailer($this->pinnedTransports->create($dsn), $this->bodyRenderer);
    }
}
```

- [ ] **Step 2: Verify existing mail unit/integration tests still pass**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail tests/Integration/Service/Mail`
Expected: PASS (the functional capture tests are updated in Task 8; resolver test in Task 7).

- [ ] **Step 3: Stage and propose commit**

```bash
git add src/Service/Mail/TransportBuilder.php
```
Proposed message: `87 - build verify-send transport through PinnedTransportFactory`

---

## Task 6: Extend `PrebakedDnsResolver` with distinct public IPs + rebind hosts

**Files:**
- Modify: `tests/Fake/PrebakedDnsResolver.php`

**Interfaces:**
- Consumes: nothing.
- Produces: deterministic test DNS — `smtp.example-organizer.test → 93.184.216.34`; `smtp.fail.example-organizer.test → 93.184.216.35` (distinct **public** IP so `throwOnHost` can target it without colliding); generic `*.example-organizer.test → 93.184.216.34`; rebind hosts `loopback.rebind.example-organizer.test → 127.0.0.1`, `mapped.rebind.example-organizer.test → ::ffff:169.254.169.254`, `cgnat.rebind.example-organizer.test → 100.64.0.1`; literal IPv4 passthrough retained.

- [ ] **Step 1: Rewrite the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Mail\DnsResolver;

final class PrebakedDnsResolver implements DnsResolver
{
    /** Host → resolved IPs. Most-specific suffixes first. */
    private const array MAP = [
        '.loopback.rebind.example-organizer.test' => ['127.0.0.1'],
        '.mapped.rebind.example-organizer.test' => ['::ffff:169.254.169.254'],
        '.cgnat.rebind.example-organizer.test' => ['100.64.0.1'],
        'smtp.fail.example-organizer.test' => ['93.184.216.35'],
    ];

    public function resolve(string $host): array
    {
        $host = strtolower($host);

        foreach (self::MAP as $needle => $ips) {
            if ($host === ltrim($needle, '.') || str_ends_with($host, $needle)) {
                return $ips;
            }
        }

        if (str_ends_with($host, '.example-organizer.test')) {
            return ['93.184.216.34'];
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host) === 1) {
            return [$host];
        }

        return [];
    }
}
```

Note: rebind hosts use sub-labels like `box.loopback.rebind.example-organizer.test`. `smtp.fail.example-organizer.test` is matched exactly (it also ends with `.example-organizer.test`, so it must be listed before the generic fallback — the loop handles that). The generic `.example-organizer.test` branch still serves `smtp.example-organizer.test`.

- [ ] **Step 2: Quick smoke check via the existing suite**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserMailFlowTest.php`
Expected: still FAIL on the capture-by-hostname assertion (updated in Task 8) — but NOT with a resolver/parse error. This confirms the resolver change is wiring-clean. (If the whole class errors before assertions, fix the resolver before moving on.)

- [ ] **Step 3: Stage and propose commit**

```bash
git add tests/Fake/PrebakedDnsResolver.php
```
Proposed message: `87 - extend PrebakedDnsResolver with distinct public IPs and rebind hosts`

---

## Task 7: `OrganizerMailerResolver` — pin, hard-fail, auto-unverify

**Files:**
- Modify: `src/Service/Mail/OrganizerMailerResolver.php`
- Test: `tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`

**Interfaces:**
- Consumes: `PinnedTransportFactory::create()` (Task 4), `DsnVault`, `EntityManagerInterface`, `UserMailConfig::revokeVerification()` (Task 3), `DsnRejected`.
- Produces: unchanged public surface — `forEvent(Event): MailerInterface`, `forUser(User): MailerInterface`, `isCustomActive(User): bool`. On `DsnRejected` with `REASON_HOST`, the config is auto-unverified and persisted, then the exception propagates. Other `DsnRejected` reasons propagate without unverifying. `SodiumException` still falls back to the platform mailer.

- [ ] **Step 1: Update the integration test (mapped hosts + new behaviours)**

In `tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`:

a) Change the three verified-config DSN hosts from `smtp.example.test` to `smtp.example-organizer.test` (lines ~73, 93, 120 — the unverified case at line ~59 may stay as-is since it never builds a transport). Without this, `forUser` now resolves at build time and throws `unresolvable`.

b) Add the send-time refusal + auto-unverify test:

```php
public function testRebindToInternalIpIsRefusedAndAutoUnverifiesAtSendTime(): void
{
    $user = $this->persistUser('rebind@example.com');
    $config = new UserMailConfig(
        $user,
        $this->vault->encrypt('smtp://u:p@box.loopback.rebind.example-organizer.test:25'),
        'rebind@example.com',
        null,
    );
    $config->markVerified();
    $this->em->persist($config);
    $this->em->flush();

    $this->expectException(DsnRejected::class);
    try {
        $this->resolver->forUser($user);
    } finally {
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        $reloadedConfig = $reloaded->getMailConfig();
        self::assertInstanceOf(UserMailConfig::class, $reloadedConfig);
        self::assertFalse($reloadedConfig->isVerified(), 'config must be auto-unverified after rebind refusal');
    }
}
```

Add the imports `use App\Service\Mail\DsnRejected;` to the test.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`
Expected: FAIL — `forUser` does not yet throw `DsnRejected`/auto-unverify (it currently builds via `transports->fromString`).

- [ ] **Step 3: Rewrite `OrganizerMailerResolver`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use App\Entity\UserMailConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SodiumException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;

final readonly class OrganizerMailerResolver
{
    public function __construct(
        private DsnVault $vault,
        private MailerInterface $platformMailer,
        private LoggerInterface $logger,
        private PinnedTransportFactory $pinnedTransports,
        private EntityManagerInterface $em,
    ) {
    }

    public function forEvent(Event $event): MailerInterface
    {
        return $this->forUser($event->getOwner());
    }

    public function forUser(User $user): MailerInterface
    {
        $config = $user->getMailConfig();
        if (!$config instanceof UserMailConfig || !$config->isVerified()) {
            return $this->platformMailer;
        }

        try {
            $dsn = $this->vault->decrypt($config->getEncryptedDsn());
        } catch (SodiumException $sodiumException) {
            $this->logger->error(
                'Falling back to platform mailer: cannot decrypt mail config DSN for user.',
                ['user_id' => $user->getId(), 'exception' => $sodiumException->getMessage()],
            );

            return $this->platformMailer;
        }

        try {
            return new Mailer($this->pinnedTransports->create($dsn));
        } catch (DsnRejected $dsnRejected) {
            if ($dsnRejected->reason === DsnRejected::REASON_HOST) {
                $this->logger->error(
                    'Auto-unverifying mail config: verified transport now resolves to a non-public address.',
                    ['user_id' => $user->getId(), 'reason' => $dsnRejected->getMessage()],
                );
                $config->revokeVerification();
                $this->em->flush();
            }

            throw $dsnRejected;
        }
    }

    public function isCustomActive(User $user): bool
    {
        $config = $user->getMailConfig();

        return $config instanceof UserMailConfig && $config->isVerified();
    }
}
```

Note: the `Autowire('mailer.transport_factory')` is gone — the resolver now depends on `PinnedTransportFactory` (which holds that wiring). `platformMailer` autowiring is unchanged.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`
Expected: PASS (verified-config cases return `Mailer`; rebind case throws + auto-unverifies).

- [ ] **Step 5: Stage and propose commit**

```bash
git add src/Service/Mail/OrganizerMailerResolver.php tests/Integration/Service/Mail/OrganizerMailerResolverTest.php
```
Proposed message: `87 - pin live-send transport, hard-fail and auto-unverify on rebind`

---

## Task 8: Functional tests — capture-by-IP + send-time refusal

**Files:**
- Modify: `tests/Functional/Admin/UserMailFlowTest.php`
- Modify: `tests/Functional/Admin/AccountMailFlowTest.php`

**Interfaces:**
- Consumes: `PrebakedDnsResolver` mappings (Task 6), `CapturedMail`.
- Produces: functional coverage that the verify-send connects to the pinned IP and that a verified config which rebinds to an internal IP is refused at live send.

- [ ] **Step 1: Update capture assertions to the pinned IP**

The pin rewrites the connect host to the resolved IP, so `InMemoryTransport` records under the IP, not the hostname. Make these edits:

`tests/Functional/Admin/UserMailFlowTest.php` line ~48:
```php
$this->assertCount(1, CapturedMail::messagesForHost('93.184.216.34'));
```

`tests/Functional/Admin/AccountMailFlowTest.php` line ~51:
```php
$messages = CapturedMail::messagesForHost('93.184.216.34');
```

`tests/Functional/Admin/AccountMailFlowTest.php` — `testVerificationEmailFailureKeepsConfigUnverified` (lines ~216-217): the fail host `smtp.fail.example-organizer.test` now resolves to `93.184.216.35`, and capture records under that IP, so target the IP:
```php
CapturedMail::throwOnHost(
    '93.184.216.35',
    new TransportException(
        'Authentication failed: 535 5.7.8 Username and Password not accepted',
    ),
);
```
(Leave the DSN host in the submitted form as `smtp.fail.example-organizer.test` — only the `throwOnHost` key changes.)

`testRejectsLoopbackHost` (line ~106) is unaffected — it asserts emptiness for `127.0.0.1` and the save is rejected (422) before any send.

- [ ] **Step 2: Run the two functional classes to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserMailFlowTest.php tests/Functional/Admin/AccountMailFlowTest.php`
Expected: PASS.

- [ ] **Step 3: Add the send-time refusal functional test**

This proves the control holds at **live send**, independent of save-time validation. Append to `tests/Functional/Admin/AccountMailFlowTest.php` (it already imports `User`, `UserMailConfig`, `CapturedMail`, `EntityManagerInterface`). It seeds a verified config whose host rebinds to loopback, then drives a live event send through `OrganizerMailerResolver` and asserts refusal + auto-unverify + nothing captured.

```php
public function testLiveSendRefusesRebindToInternalHostAndAutoUnverifies(): void
{
    /** @var \App\Service\Mail\DsnVault $vault */
    $vault = self::getContainer()->get(\App\Service\Mail\DsnVault::class);
    /** @var \App\Service\Mail\OrganizerMailerResolver $resolver */
    $resolver = self::getContainer()->get(\App\Service\Mail\OrganizerMailerResolver::class);

    $user = $this->createOrganizer('rebind-fn@example.com', 'secret');
    $config = new UserMailConfig(
        $user,
        $vault->encrypt('smtp://u:p@box.loopback.rebind.example-organizer.test:25'),
        'rebind-fn@example.com',
        null,
    );
    $config->markVerified();
    $this->em->persist($config);
    $this->em->flush();

    $threw = false;
    try {
        $resolver->forUser($user);
    } catch (\App\Service\Mail\DsnRejected $e) {
        $threw = true;
        self::assertSame(\App\Service\Mail\DsnRejected::REASON_HOST, $e->reason);
    }

    self::assertTrue($threw, 'live send to a rebound internal host must be refused');
    self::assertSame([], CapturedMail::messagesForHost('127.0.0.1'));

    $this->em->clear();
    $reloaded = $this->em->getRepository(User::class)->find($user->getId());
    self::assertInstanceOf(User::class, $reloaded);
    $reloadedConfig = $reloaded->getMailConfig();
    self::assertInstanceOf(UserMailConfig::class, $reloadedConfig);
    self::assertFalse($reloadedConfig->isVerified());
}
```

(`OrganizerMailerResolver` and `DsnVault` are already `public: true` under `when@test`, so `getContainer()->get(...)` works.)

- [ ] **Step 4: Run the new test**

Run: `vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php --filter testLiveSendRefusesRebindToInternalHostAndAutoUnverifies`
Expected: PASS.

- [ ] **Step 5: Stage and propose commit**

```bash
git add tests/Functional/Admin/UserMailFlowTest.php tests/Functional/Admin/AccountMailFlowTest.php
```
Proposed message: `87 - functional: capture pinned IP and refuse rebind at send time`

---

## Task 9: Documentation

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md`

**Interfaces:** none (docs only).

- [ ] **Step 1: Update the CLAUDE.md mail section**

In the "Per-organizer mail transport" section of `CLAUDE.md`, add a sentence describing the send-time control. Insert after the sentence about `DsnValidator` gating persistence:

```markdown
Both send paths build transports through `App\Service\Mail\PinnedTransportFactory`, which resolves the host, requires **every** resolved IP to pass `App\Service\Mail\PublicIpInspector` (a positive-allowlist classifier that decodes IPv4-mapped/6to4/Teredo IPv6 and rejects CGNAT), then connects to the literal validated IP while passing the original hostname as the TLS `peer_name`. This closes the validate-then-reconnect (DNS rebinding) SSRF: the stored `verified` flag is never trusted at connect time. On a live send, a verified transport that now resolves to a non-public address is hard-failed (Messenger retry/dead-letter) and the config is auto-unverified (#87).
```

- [ ] **Step 2: Annotate the #78 design spec**

Append to `docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md`:

```markdown

## Addendum (#87) — rebinding caveat

`DsnValidator` resolves and validates the host at config-save time only; the SMTP socket
re-resolves the hostname at connect time. **Hostname-validate-then-reconnect-by-name is DNS
rebinding-vulnerable by construction** — a stored "validated at save" / `verified` flag does
not hold at send time. The control is `PinnedTransportFactory`: resolve → validate every IP
→ connect to the literal validated IP with the hostname carried as TLS `peer_name`, applied
on every transport build. See `docs/superpowers/specs/2026-06-20-87-mail-ssrf-pinning-design.md`.
```

- [ ] **Step 3: Stage and propose commit**

```bash
git add CLAUDE.md docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md
```
Proposed message: `87 - document send-time pinning and rebinding caveat`

---

## Task 10: Full quality gate

**Files:** none (verification only).

- [ ] **Step 1: Run the full mail suite**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail tests/Integration/Service/Mail tests/Functional/Admin tests/Unit/Entity/UserMailConfigTest.php`
Expected: PASS, zero deprecations/notices/warnings.

- [ ] **Step 2: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 3: Run the complete GrumPHP gate (mirrors CI)**

Run: `vendor/bin/grumphp run`
Expected: all tasks green — `phpstan` (level 10), `phpcs` (PSR-12), `phpmnd`, `phpcpd`, `rector`, `securitychecker_roave`, `doctrine:schema:validate`.

If `phpcpd` flags duplication between `DsnValidator::validate()` and `PinnedTransportFactory::create()` (the parse/scheme/host/resolve preamble is similar), that is acceptable only if under the 50-line/100-token threshold; if it trips, extract the shared "parse + scheme + host + resolve-to-public-IPs" preamble into a small private helper or a shared method on a collaborator and re-run. Do not duplicate beyond the threshold.

- [ ] **Step 4: Propose final state to the user**

Summarize the staged commits (Tasks 1-9) and confirm the suite + GrumPHP are green. The user performs the commits and opens the PR.

---

## Self-Review

**Spec coverage:**
- AC "connection pinned to a validated IP, TLS hostname/SNI preserved" → Task 4 (`peer_name`, literal-IP host) + Task 4 unit assertions.
- AC "validation/re-pinning at send time, not only save" → Tasks 4, 5, 7 + Task 8 live-send test.
- AC "`::ffff:169.254.169.254` and mapped IPv6 rejected" → Task 1 (IPv6 embedded decode) + Tasks 2/4/7 rejection rows.
- AC "`100.64.0.0/10` rejected" → Task 1 (CGNAT branch) + Task 2 row.
- AC "functional test asserts rebound/mapped/CGNAT refused at send time" → Task 8 Step 3 (functional) + Task 7 (integration) + Task 4 (unit, all three vectors).
- Also-worth-doing "#78 spec annotated" → Task 9 Step 2.
- Spec component `PublicIpInspector` → Task 1; `PinnedTransportFactory` → Task 4; `DsnValidator` refactor → Task 2; `revokeVerification` → Task 3; call-site wiring → Tasks 5, 7; test fakes → Task 6; docs → Task 9.

**Placeholder scan:** No TBD/TODO; every code step carries complete code. The one "adapt to existing helpers" note (Task 3 Step 1) is bounded by the explicit assertion set and a fallback instruction.

**Type consistency:** `PublicIpInspector::isPublic(string): bool` used identically in Tasks 2/4/7. `PinnedTransportFactory::create(string): TransportInterface` used in Tasks 5/7. `UserMailConfig::revokeVerification(): void` defined in Task 3, called in Task 7. `DsnRejected::reason` / `REASON_HOST` consistent across Tasks 4/7/8. Constructor signatures: `DsnValidator(DnsResolver, PublicIpInspector)` (Task 2), `PinnedTransportFactory(DnsResolver, PublicIpInspector, Transport)` (Task 4), `OrganizerMailerResolver(DsnVault, MailerInterface, LoggerInterface, PinnedTransportFactory, EntityManagerInterface)` (Task 7), `TransportBuilder(PinnedTransportFactory, BodyRendererInterface)` (Task 5) — all match their call/instantiation sites.
