# #78 Per-Organizer Mail Transport — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the subsystem that lets each organizer (and `ROLE_ADMIN` on their behalf) configure an SMTP transport so event-scoped emails go out from their own domain. Verified configs are resolved by `OrganizerMailerResolver::forEvent($event)`; unverified or absent configs fall back to the platform `MAILER_DSN`.

**Architecture:** New `UserMailConfig` Doctrine entity (one-to-one with `User`) stores an encrypted DSN, `from_addr`, `from_name`, and verification state. `App\Service\Mail\DsnVault` encrypts/decrypts via libsodium `crypto_secretbox` keyed by a platform-wide env var. `App\Service\Mail\DsnValidator` gates scheme (`smtp`/`smtps` only) and host (RFC1918/loopback/link-local/multicast rejected via `DnsResolver`). `OrganizerMailerResolver` returns the platform `MailerInterface` unless the user has a `verified_at` config, in which case it builds a per-call `Mailer` from the decrypted DSN. Verification is a click-through flow: save persists the config as unverified, the candidate transport is used to send the verification email to `from_addr`, the recipient clicks within 24h. Audit rows (`set` / `verified` / `cleared` / `verification_resent`) written to a thin local table designed to be folded into #75 later.

**Tech Stack:** PHP 8.5, Symfony 8 (Mailer + Form + Routing), Doctrine ORM 3 + DBAL 4, PostgreSQL 16, libsodium (PHP core), PHPUnit 13, `dama/doctrine-test-bundle`.

**Spec:** `docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md`

**Branch:** `feature/78-organizer-mail-transport` (must be cut before first commit; GrumPHP gates branch name `^(feature|hotfix|bugfix|release)/\d+-`).

**Commit message convention:** Every commit must contain the issue number `78`. Pattern used across the repo: `78 - <short imperative>`.

---

## Pre-flight

- [ ] **Cut the branch from main:**

```bash
git switch -c feature/78-organizer-mail-transport main
```

- [ ] **Confirm libsodium is loaded** (the runtime requirement; should be true on the host):

```bash
php -r "echo extension_loaded('sodium') && function_exists('sodium_crypto_secretbox') ? 'OK' : 'MISSING'; echo PHP_EOL;"
```

Expected output: `OK`. If `MISSING`, stop and fix the PHP install before proceeding — the whole feature is blocked.

- [ ] **Commit the design spec onto this branch** if not already there:

```bash
git add docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md
git commit -m "78 - design spec for per-organizer mail transport configuration"
```

---

## Task 1: Add `MAIL_CONFIG_ENCRYPTION_KEY` env var

**Files:**
- Modify: `.env`
- Modify: `.env.test` (create if missing — currently absent per `cat .env.test` returning empty earlier)

- [ ] **Step 1: Append the placeholder + generation hint to `.env`.**

Open `.env`. Find the existing `###> symfony/mailer ###` block. Immediately **after** the `###< symfony/mailer ###` closing line, append:

```dotenv

###> app/mail-config-encryption ###
# Platform-wide key used to encrypt per-organizer mail DSNs at rest.
# Generate ONCE per environment with:
#   php -r "echo base64_encode(sodium_crypto_secretbox_keygen()) . PHP_EOL;"
# DO NOT commit a real key. Real keys live in .env.local (dev) or the runtime env (prod).
MAIL_CONFIG_ENCRYPTION_KEY=
###< app/mail-config-encryption ###
```

- [ ] **Step 2: Generate a key for the local dev env and write it to `.env.local`.**

```bash
php -r "echo 'MAIL_CONFIG_ENCRYPTION_KEY=' . base64_encode(sodium_crypto_secretbox_keygen()) . PHP_EOL;" >> .env.local
```

Expected: `.env.local` now contains a line like `MAIL_CONFIG_ENCRYPTION_KEY=<43-char-base64>=`. Verify:

```bash
grep MAIL_CONFIG_ENCRYPTION_KEY .env.local
```

- [ ] **Step 3: Generate a *separate* deterministic-ish key for the test env.**

Create `.env.test` (or append to it if present) with a fixed-but-clearly-fake key. Tests must be reproducible, so a fixed key is correct here.

```dotenv
# .env.test — overrides for the test environment
MAIL_CONFIG_ENCRYPTION_KEY=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
```

(That's 32 zero bytes base64-encoded — a deterministic, clearly-fake fixture. Decode and verify length:)

```bash
php -r "echo strlen(base64_decode(getenv('MAIL_CONFIG_ENCRYPTION_KEY') ?: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='));"
```

Expected: `32`.

- [ ] **Step 4: Commit.**

```bash
git add .env .env.test
git commit -m "78 - add MAIL_CONFIG_ENCRYPTION_KEY env var scaffolding"
```

(`.env.local` is gitignored.)

---

## Task 2: `EncryptedDsn` value object

**Files:**
- Create: `src/Service/Mail/EncryptedDsn.php`
- Create: `tests/Unit/Service/Mail/EncryptedDsnTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\EncryptedDsn;
use PHPUnit\Framework\TestCase;

final class EncryptedDsnTest extends TestCase
{
    public function testHoldsCiphertextAndNonce(): void
    {
        $envelope = new EncryptedDsn(ciphertext: 'c', nonce: 'n');

        self::assertSame('c', $envelope->ciphertext);
        self::assertSame('n', $envelope->nonce);
    }

    public function testRejectsEmptyCiphertext(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncryptedDsn(ciphertext: '', nonce: 'n');
    }

    public function testRejectsEmptyNonce(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncryptedDsn(ciphertext: 'c', nonce: '');
    }
}
```

- [ ] **Step 2: Run the test, expect failure.**

```bash
vendor/bin/phpunit tests/Unit/Service/Mail/EncryptedDsnTest.php
```

Expected: errors about missing class `App\Service\Mail\EncryptedDsn`.

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use InvalidArgumentException;

final readonly class EncryptedDsn
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
    ) {
        if ($ciphertext === '') {
            throw new InvalidArgumentException('EncryptedDsn ciphertext cannot be empty.');
        }
        if ($nonce === '') {
            throw new InvalidArgumentException('EncryptedDsn nonce cannot be empty.');
        }
    }
}
```

- [ ] **Step 4: Re-run the test, expect pass.**

```bash
vendor/bin/phpunit tests/Unit/Service/Mail/EncryptedDsnTest.php
```

Expected: 3 tests, 0 failures.

- [ ] **Step 5: Commit.**

```bash
git add src/Service/Mail/EncryptedDsn.php tests/Unit/Service/Mail/EncryptedDsnTest.php
git commit -m "78 - add EncryptedDsn value object"
```

---

## Task 3: `DsnVault` (libsodium encryption-at-rest)

**Files:**
- Create: `src/Service/Mail/DsnVault.php`
- Create: `tests/Unit/Service/Mail/DsnVaultTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\DsnVault;
use App\Service\Mail\EncryptedDsn;
use PHPUnit\Framework\TestCase;
use SodiumException;

final class DsnVaultTest extends TestCase
{
    /** 32 zero bytes — the constructor takes the raw key, not base64. */
    private const string KEY_RAW = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    public function testRoundTrip(): void
    {
        $vault = new DsnVault(self::KEY_RAW);
        $dsn = 'smtp://user:pass@example.com:587';

        $envelope = $vault->encrypt($dsn);

        self::assertNotSame($dsn, $envelope->ciphertext);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen($envelope->nonce));
        self::assertSame($dsn, $vault->decrypt($envelope));
    }

    public function testTwoEncryptsProduceDifferentCiphertext(): void
    {
        $vault = new DsnVault(self::KEY_RAW);

        $a = $vault->encrypt('smtp://x@example.com');
        $b = $vault->encrypt('smtp://x@example.com');

        self::assertNotSame($a->ciphertext, $b->ciphertext);
        self::assertNotSame($a->nonce, $b->nonce);
    }

    public function testWrongKeyFailsToDecrypt(): void
    {
        $writer = new DsnVault(self::KEY_RAW);
        $reader = new DsnVault(str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $envelope = $writer->encrypt('smtp://x@example.com');

        $this->expectException(SodiumException::class);
        $reader->decrypt($envelope);
    }

    public function testTamperedCiphertextFailsToDecrypt(): void
    {
        $vault = new DsnVault(self::KEY_RAW);
        $envelope = $vault->encrypt('smtp://x@example.com');
        $tampered = new EncryptedDsn(
            ciphertext: $envelope->ciphertext . 'x',
            nonce: $envelope->nonce,
        );

        $this->expectException(SodiumException::class);
        $vault->decrypt($tampered);
    }

    public function testKeyMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DsnVault('too-short');
    }
}
```

(Note the test passes the *raw* 32-byte key. The `%env(base64:...)%` indirection in the production autowire decodes the base64 before constructing the service, so the constructor always sees raw bytes. Tests don't go through the env loader — they construct directly with raw bytes.)

- [ ] **Step 2: Run, expect failure.**

```bash
vendor/bin/phpunit tests/Unit/Service/Mail/DsnVaultTest.php
```

Expected: missing class `App\Service\Mail\DsnVault`.

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use InvalidArgumentException;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DsnVault
{
    private string $key;

    public function __construct(
        #[\SensitiveParameter]
        #[Autowire('%env(base64:MAIL_CONFIG_ENCRYPTION_KEY)%')]
        string $keyRaw,
    ) {
        if (strlen($keyRaw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'MAIL_CONFIG_ENCRYPTION_KEY must decode to %d bytes; got %d.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($keyRaw),
            ));
        }
        $this->key = $keyRaw;
    }

    public function encrypt(#[\SensitiveParameter] string $dsn): EncryptedDsn
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($dsn, $nonce, $this->key);

        return new EncryptedDsn(ciphertext: $ciphertext, nonce: $nonce);
    }

    public function decrypt(EncryptedDsn $envelope): string
    {
        $plaintext = sodium_crypto_secretbox_open($envelope->ciphertext, $envelope->nonce, $this->key);
        if ($plaintext === false) {
            throw new SodiumException('Mail-config DSN ciphertext could not be decrypted (wrong key or tampered).');
        }

        return $plaintext;
    }
}
```

- [ ] **Step 4: Re-run, expect pass.**

```bash
vendor/bin/phpunit tests/Unit/Service/Mail/DsnVaultTest.php
```

Expected: 5 tests, 0 failures.

- [ ] **Step 5: Commit.**

```bash
git add src/Service/Mail/DsnVault.php tests/Unit/Service/Mail/DsnVaultTest.php
git commit -m "78 - add DsnVault libsodium encryption service"
```

---

## Task 4: `DnsResolver` interface + system impl

**Files:**
- Create: `src/Service/Mail/DnsResolver.php`
- Create: `src/Service/Mail/SystemDnsResolver.php`
- Create: `tests/Fake/FakeDnsResolver.php`

(No unit test on the system impl — wrapping `dns_get_record` is too thin to verify meaningfully without integration to real DNS. The fake covers it for downstream tests; the system impl is exercised by manual smoke + functional tests.)

- [ ] **Step 1: Interface.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

interface DnsResolver
{
    /**
     * @return list<string> IPv4 + IPv6 addresses the host resolves to; empty for NXDOMAIN.
     */
    public function resolve(string $host): array;
}
```

- [ ] **Step 2: System impl.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

final readonly class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
```

- [ ] **Step 3: Fake (lives in `tests/Fake/` like `FakeGoogleOAuthClient`).**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Mail\DnsResolver;

final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $map = [];

    /**
     * @param list<string> $ips
     */
    public function setMapping(string $host, array $ips): void
    {
        $this->map[strtolower($host)] = $ips;
    }

    public function resolve(string $host): array
    {
        return $this->map[strtolower($host)] ?? [];
    }
}
```

- [ ] **Step 4: Commit.**

```bash
git add src/Service/Mail/DnsResolver.php src/Service/Mail/SystemDnsResolver.php tests/Fake/FakeDnsResolver.php
git commit -m "78 - add DnsResolver interface, system impl, and test fake"
```

---

## Task 5: `DsnRejected` exception

**Files:**
- Create: `src/Service/Mail/DsnRejected.php`

- [ ] **Step 1: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use RuntimeException;

final class DsnRejected extends RuntimeException
{
    public const string REASON_SCHEME = 'scheme';
    public const string REASON_HOST = 'host';
    public const string REASON_UNRESOLVABLE = 'unresolvable';
    public const string REASON_MALFORMED = 'malformed';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function scheme(string $scheme): self
    {
        return new self(
            self::REASON_SCHEME,
            sprintf('Unsupported mail transport scheme "%s". Only smtp and smtps are allowed.', $scheme),
        );
    }

    public static function host(string $host, string $rejectedIp): self
    {
        return new self(
            self::REASON_HOST,
            sprintf('Mail transport host "%s" resolves to a non-public address (%s).', $host, $rejectedIp),
        );
    }

    public static function unresolvable(string $host): self
    {
        return new self(
            self::REASON_UNRESOLVABLE,
            sprintf('Mail transport host "%s" does not resolve.', $host),
        );
    }

    public static function malformed(string $why): self
    {
        return new self(self::REASON_MALFORMED, sprintf('Mail DSN is malformed: %s.', $why));
    }
}
```

- [ ] **Step 2: Commit.**

```bash
git add src/Service/Mail/DsnRejected.php
git commit -m "78 - add DsnRejected exception"
```

---

## Task 6: `DsnValidator`

**Files:**
- Create: `src/Service/Mail/DsnValidator.php`
- Create: `tests/Unit/Service/Mail/DsnValidatorTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\DsnRejected;
use App\Service\Mail\DsnValidator;
use App\Tests\Fake\FakeDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DsnValidatorTest extends TestCase
{
    public function testAcceptsPublicSmtp(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('smtp.example.com', ['93.184.216.34']);
        $validator = new DsnValidator($dns);

        $validator->validate('smtp://user:pass@smtp.example.com:25');

        $this->expectNotToPerformAssertions();
    }

    public function testAcceptsPublicSmtps(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('smtp.example.com', ['93.184.216.34']);
        $validator = new DsnValidator($dns);

        $validator->validate('smtps://user:pass@smtp.example.com:465');

        $this->expectNotToPerformAssertions();
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedSchemes(): iterable
    {
        yield 'http' => ['http://x@example.com'];
        yield 'null' => ['null://null'];
        yield 'gmail+smtp' => ['gmail+smtp://user@gmail.com'];
        yield 'sendgrid+api' => ['sendgrid+api://KEY@default'];
        yield 'mailto' => ['mailto://x@example.com'];
    }

    #[DataProvider('rejectedSchemes')]
    public function testRejectsNonSmtpSchemes(string $dsn): void
    {
        $validator = new DsnValidator(new FakeDnsResolver());

        $this->expectException(DsnRejected::class);
        try {
            $validator->validate($dsn);
        } catch (DsnRejected $e) {
            self::assertSame(DsnRejected::REASON_SCHEME, $e->reason);
            throw $e;
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedHosts(): iterable
    {
        yield 'loopback v4' => ['127.0.0.1', '127.0.0.1'];
        yield 'rfc1918 10/8' => ['10.0.0.5', '10.0.0.5'];
        yield 'rfc1918 192.168' => ['192.168.1.10', '192.168.1.10'];
        yield 'rfc1918 172.16' => ['172.16.0.1', '172.16.0.1'];
        yield 'link-local v4' => ['169.254.1.1', '169.254.1.1'];
        yield 'multicast v4' => ['224.0.0.1', '224.0.0.1'];
        yield 'unspecified v4' => ['0.0.0.0', '0.0.0.0'];
        yield 'loopback v6' => ['::1', '::1'];
        yield 'ula v6' => ['fc00::1', 'fc00::1'];
        yield 'link-local v6' => ['fe80::1', 'fe80::1'];
    }

    #[DataProvider('rejectedHosts')]
    public function testRejectsPrivateOrReservedHosts(string $ip, string $expectedRejection): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('attacker.example', [$ip]);
        $validator = new DsnValidator($dns);

        $this->expectException(DsnRejected::class);
        try {
            $validator->validate('smtp://x@attacker.example:25');
        } catch (DsnRejected $e) {
            self::assertSame(DsnRejected::REASON_HOST, $e->reason);
            self::assertStringContainsString($expectedRejection, $e->getMessage());
            throw $e;
        }
    }

    public function testRejectsMixedPublicAndPrivate(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('split-horizon.example', ['93.184.216.34', '10.0.0.1']);
        $validator = new DsnValidator($dns);

        $this->expectException(DsnRejected::class);
        $validator->validate('smtp://x@split-horizon.example:25');
    }

    public function testRejectsUnresolvable(): void
    {
        $dns = new FakeDnsResolver();
        $validator = new DsnValidator($dns);

        $this->expectException(DsnRejected::class);
        try {
            $validator->validate('smtp://x@nx.example:25');
        } catch (DsnRejected $e) {
            self::assertSame(DsnRejected::REASON_UNRESOLVABLE, $e->reason);
            throw $e;
        }
    }

    public function testRejectsHostlessDsn(): void
    {
        $validator = new DsnValidator(new FakeDnsResolver());

        $this->expectException(DsnRejected::class);
        $validator->validate('smtp://');
    }
}
```

- [ ] **Step 2: Run, expect failure.**

```bash
vendor/bin/phpunit tests/Unit/Service/Mail/DsnValidatorTest.php
```

Expected: missing `App\Service\Mail\DsnValidator`.

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerDsnException;
use Symfony\Component\Mailer\Transport\Dsn;

final readonly class DsnValidator
{
    /** @var list<string> */
    private const array ALLOWED_SCHEMES = ['smtp', 'smtps'];

    public function __construct(private DnsResolver $dns)
    {
    }

    public function validate(#[\SensitiveParameter] string $dsn): void
    {
        try {
            $parsed = Dsn::fromString($dsn);
        } catch (MailerDsnException $e) {
            throw DsnRejected::malformed($e->getMessage());
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
            if (!$this->isPublicIp($ip)) {
                throw DsnRejected::host($host, $ip);
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
```

**Note:** `FILTER_FLAG_NO_PRIV_RANGE` covers RFC1918; `FILTER_FLAG_NO_RES_RANGE` covers loopback, link-local, multicast, broadcast, and IPv6 ULA/link-local. Together they satisfy the host allow-list.

- [ ] **Step 4: Re-run, expect pass.**

```bash
vendor/bin/phpunit tests/Unit/Service/Mail/DsnValidatorTest.php
```

Expected: all data-provided cases green, ~20 assertions across ~16 tests.

- [ ] **Step 5: Commit.**

```bash
git add src/Service/Mail/DsnValidator.php tests/Unit/Service/Mail/DsnValidatorTest.php
git commit -m "78 - add DsnValidator with scheme + host allow-list"
```

---

## Task 7: `MailConfigAuditAction` enum

**Files:**
- Create: `src/Enum/MailConfigAuditAction.php`

- [ ] **Step 1: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum MailConfigAuditAction: string
{
    case Set = 'set';
    case Verified = 'verified';
    case Cleared = 'cleared';
    case VerificationResent = 'verification_resent';
}
```

- [ ] **Step 2: Commit.**

```bash
git add src/Enum/MailConfigAuditAction.php
git commit -m "78 - add MailConfigAuditAction enum"
```

---

## Task 8: `UserMailConfig` entity + domain tests

**Files:**
- Create: `src/Entity/UserMailConfig.php`
- Create: `tests/Unit/Entity/UserMailConfigTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\EncryptedDsn;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UserMailConfigTest extends TestCase
{
    private function newUser(): User
    {
        return new User('owner@example.com', 'Owner');
    }

    private function newConfig(): UserMailConfig
    {
        return new UserMailConfig(
            $this->newUser(),
            new EncryptedDsn(ciphertext: 'cipher', nonce: 'nonce-bytes'),
            'sender@example.com',
            'Sender Display',
        );
    }

    public function testNewConfigIsUnverifiedAndHasToken(): void
    {
        $config = $this->newConfig();

        self::assertNull($config->getVerifiedAt());
        self::assertNotNull($config->getVerificationToken());
        self::assertNotNull($config->getVerificationSentAt());
        self::assertSame(43, strlen((string) $config->getVerificationToken()));
    }

    public function testMarkVerifiedClearsTokenAndSetsTimestamp(): void
    {
        $config = $this->newConfig();

        $config->markVerified();

        self::assertNotNull($config->getVerifiedAt());
        self::assertNull($config->getVerificationToken());
    }

    public function testCannotVerifyTwice(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $this->expectException(DomainException::class);
        $config->markVerified();
    }

    public function testRegenerateVerificationTokenRotates(): void
    {
        $config = $this->newConfig();
        $oldToken = $config->getVerificationToken();
        $oldSentAt = $config->getVerificationSentAt();

        usleep(1000);
        $config->regenerateVerificationToken();

        self::assertNotSame($oldToken, $config->getVerificationToken());
        self::assertNotSame($oldSentAt, $config->getVerificationSentAt());
        self::assertNull($config->getVerifiedAt());
    }

    public function testRegenerateAfterVerifyResetsVerifiedAt(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $config->regenerateVerificationToken();

        self::assertNull($config->getVerifiedAt());
        self::assertNotNull($config->getVerificationToken());
    }

    public function testApplyConfigReturnsTrueWhenDsnChanges(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $requiresReverify = $config->applyConfig(
            new EncryptedDsn(ciphertext: 'cipher2', nonce: 'nonce2-bytes'),
            'sender@example.com',
            'Sender Display',
        );

        self::assertTrue($requiresReverify);
        self::assertNull($config->getVerifiedAt());
    }

    public function testApplyConfigReturnsTrueWhenFromAddrChanges(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $requiresReverify = $config->applyConfig(
            new EncryptedDsn(ciphertext: 'cipher', nonce: 'nonce-bytes'),
            'newsender@example.com',
            'Sender Display',
        );

        self::assertTrue($requiresReverify);
    }

    public function testApplyConfigReturnsFalseWhenOnlyFromNameChanges(): void
    {
        $config = $this->newConfig();
        $config->markVerified();
        $verifiedAt = $config->getVerifiedAt();

        $requiresReverify = $config->applyConfig(
            new EncryptedDsn(ciphertext: 'cipher', nonce: 'nonce-bytes'),
            'sender@example.com',
            'New Display Name',
        );

        self::assertFalse($requiresReverify);
        self::assertSame($verifiedAt, $config->getVerifiedAt());
    }

    public function testFromAddrRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserMailConfig(
            $this->newUser(),
            new EncryptedDsn(ciphertext: 'c', nonce: 'n'),
            '',
            null,
        );
    }
}
```

- [ ] **Step 2: Run, expect failure.**

```bash
vendor/bin/phpunit tests/Unit/Entity/UserMailConfigTest.php
```

Expected: missing class.

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserMailConfigRepository;
use App\Service\Mail\EncryptedDsn;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: UserMailConfigRepository::class)]
#[ORM\Table(name: 'user_mail_configs')]
class UserMailConfig
{
    private const int VERIFICATION_TOKEN_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'mailConfig')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private User $user;

    /** base64-encoded libsodium ciphertext (entity boundary converts to/from raw bytes) */
    #[ORM\Column(type: Types::TEXT)]
    private string $dsnCiphertext;

    /** base64-encoded 24-byte nonce */
    #[ORM\Column(type: Types::TEXT)]
    private string $dsnNonce;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $fromAddr;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $fromName;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verificationSentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        EncryptedDsn $envelope,
        string $fromAddr,
        ?string $fromName,
    ) {
        if ($fromAddr === '') {
            throw new InvalidArgumentException('UserMailConfig from_addr cannot be empty.');
        }
        $this->user = $user;
        $this->dsnCiphertext = base64_encode($envelope->ciphertext);
        $this->dsnNonce = base64_encode($envelope->nonce);
        $this->fromAddr = $fromAddr;
        $this->fromName = $fromName;
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->regenerateVerificationToken();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEncryptedDsn(): EncryptedDsn
    {
        $ciphertext = base64_decode($this->dsnCiphertext, true);
        $nonce = base64_decode($this->dsnNonce, true);
        if ($ciphertext === false || $nonce === false) {
            throw new \RuntimeException('UserMailConfig stored DSN payload is not valid base64.');
        }
        return new EncryptedDsn(ciphertext: $ciphertext, nonce: $nonce);
    }

    public function getFromAddr(): string
    {
        return $this->fromAddr;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getVerifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function getVerificationSentAt(): ?DateTimeImmutable
    {
        return $this->verificationSentAt;
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    public function markVerified(): void
    {
        if ($this->verifiedAt !== null) {
            throw new DomainException('UserMailConfig already verified.');
        }
        if ($this->verificationToken === null) {
            throw new DomainException('UserMailConfig has no pending verification token.');
        }
        $this->verifiedAt = new DateTimeImmutable();
        $this->verificationToken = null;
        $this->updatedAt = $this->verifiedAt;
    }

    public function regenerateVerificationToken(): void
    {
        $this->verificationToken = self::generateToken();
        $this->verificationSentAt = new DateTimeImmutable();
        $this->verifiedAt = null;
        $this->updatedAt = $this->verificationSentAt;
    }

    /**
     * Returns true when DSN or from_addr changed (re-verification required), false when only
     * cosmetic (from_name) differs.
     */
    public function applyConfig(EncryptedDsn $envelope, string $fromAddr, ?string $fromName): bool
    {
        if ($fromAddr === '') {
            throw new InvalidArgumentException('UserMailConfig from_addr cannot be empty.');
        }

        $incomingCiphertext = base64_encode($envelope->ciphertext);
        $incomingNonce = base64_encode($envelope->nonce);
        $dsnChanged = !hash_equals($this->dsnCiphertext, $incomingCiphertext)
            || !hash_equals($this->dsnNonce, $incomingNonce);
        $fromAddrChanged = $this->fromAddr !== $fromAddr;

        $this->dsnCiphertext = $incomingCiphertext;
        $this->dsnNonce = $incomingNonce;
        $this->fromAddr = $fromAddr;
        $this->fromName = $fromName;
        $this->updatedAt = new DateTimeImmutable();

        if ($dsnChanged || $fromAddrChanged) {
            $this->regenerateVerificationToken();
            return true;
        }

        return false;
    }

    private static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::VERIFICATION_TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
```

**Note on `applyConfig`'s DSN-comparison semantics:** the caller passes a freshly-encrypted envelope (new nonce every time), so this will *always* think the DSN changed even when the plaintext is identical. That's the desired behaviour at the controller layer — the controller has the plaintext and can decide whether to re-encrypt at all. We'll handle the "is plaintext identical" check in the controller via a separate path that doesn't touch the entity. The entity's job is "apply what the controller decided." Document this above the method:

Add the comment immediately above `public function applyConfig(...)`:

```php
    /**
     * Replace stored DSN/from fields. Caller is responsible for deciding whether to encrypt a new
     * envelope; the entity treats any new envelope as a DSN change (nonces never repeat).
     *
     * Returns true when re-verification is required (DSN or from_addr changed), false when only
     * the cosmetic from_name differs.
     */
```

- [ ] **Step 4: Re-run, expect pass.**

```bash
vendor/bin/phpunit tests/Unit/Entity/UserMailConfigTest.php
```

Expected: 9 tests pass.

- [ ] **Step 5: Commit.**

```bash
git add src/Entity/UserMailConfig.php tests/Unit/Entity/UserMailConfigTest.php
git commit -m "78 - add UserMailConfig entity with verification state machine"
```

---

## Task 9: `UserMailConfigRepository`

**Files:**
- Create: `src/Repository/UserMailConfigRepository.php`

- [ ] **Step 1: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMailConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMailConfig>
 */
final class UserMailConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMailConfig::class);
    }

    public function findOneByUser(User $user): ?UserMailConfig
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOneByVerificationToken(string $token): ?UserMailConfig
    {
        if ($token === '') {
            return null;
        }
        return $this->findOneBy(['verificationToken' => $token]);
    }
}
```

- [ ] **Step 2: Commit.**

```bash
git add src/Repository/UserMailConfigRepository.php
git commit -m "78 - add UserMailConfigRepository"
```

---

## Task 10: `UserMailConfigAudit` entity + repository

**Files:**
- Create: `src/Entity/UserMailConfigAudit.php`
- Create: `src/Repository/UserMailConfigAuditRepository.php`

- [ ] **Step 1: Entity.**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailConfigAuditAction;
use App\Repository\UserMailConfigAuditRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: UserMailConfigAuditRepository::class)]
#[ORM\Table(name: 'user_mail_config_audits')]
#[ORM\Index(name: 'idx_mail_audit_user', columns: ['user_id'])]
class UserMailConfigAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $actorEmailSnapshot;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: MailConfigAuditAction::class)]
    private MailConfigAuditAction $action;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $fromAddrSnapshot;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        ?User $actor,
        string $actorEmailSnapshot,
        MailConfigAuditAction $action,
        string $fromAddrSnapshot,
    ) {
        if ($actorEmailSnapshot === '') {
            throw new InvalidArgumentException('actor_email_snapshot cannot be empty.');
        }
        if ($fromAddrSnapshot === '') {
            throw new InvalidArgumentException('from_addr_snapshot cannot be empty.');
        }
        $this->user = $user;
        $this->actor = $actor;
        $this->actorEmailSnapshot = $actorEmailSnapshot;
        $this->action = $action;
        $this->fromAddrSnapshot = $fromAddrSnapshot;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorEmailSnapshot(): string
    {
        return $this->actorEmailSnapshot;
    }

    public function getAction(): MailConfigAuditAction
    {
        return $this->action;
    }

    public function getFromAddrSnapshot(): string
    {
        return $this->fromAddrSnapshot;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 2: Repository.**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMailConfigAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMailConfigAudit>
 */
final class UserMailConfigAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMailConfigAudit::class);
    }

    /** @return list<UserMailConfigAudit> */
    public function findForUserOrderedDesc(User $user): array
    {
        /** @var list<UserMailConfigAudit> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        return $rows;
    }
}
```

- [ ] **Step 3: Commit.**

```bash
git add src/Entity/UserMailConfigAudit.php src/Repository/UserMailConfigAuditRepository.php
git commit -m "78 - add UserMailConfigAudit entity + repository"
```

---

## Task 11: Wire `User::$mailConfig` inverse side

**Files:**
- Modify: `src/Entity/User.php`

- [ ] **Step 1: Add the inverse-side mapping + getter to `User`.**

Open `src/Entity/User.php`. After the `private Collection $identities;` block (around line 41), add:

```php
    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?UserMailConfig $mailConfig = null;
```

`UserMailConfig` is in the same namespace (`App\Entity`), so no `use` import needed.

Append a getter/setter near the bottom (before `eraseCredentials`):

```php
    public function getMailConfig(): ?UserMailConfig
    {
        return $this->mailConfig;
    }

    public function setMailConfig(?UserMailConfig $mailConfig): void
    {
        $this->mailConfig = $mailConfig;
    }
```

- [ ] **Step 2: Verify mapping with `doctrine:schema:validate --skip-sync`.**

```bash
bin/console doctrine:schema:validate --skip-sync
```

Expected: "Mapping is correct." (Sync will still show drift — that's normal pre-migration.)

- [ ] **Step 3: Commit.**

```bash
git add src/Entity/User.php
git commit -m "78 - wire User to UserMailConfig (inverse side)"
```

---

## Task 12: Generate migration

**Files:**
- Create: `migrations/Version<timestamp>.php` (auto-generated; do NOT hand-edit anything beyond `getDescription()`)

- [ ] **Step 1: Generate.**

```bash
bin/console doctrine:migrations:diff
```

Expected: a new file `migrations/Version<timestamp>.php` containing `CREATE TABLE user_mail_configs ...` and `CREATE TABLE user_mail_config_audits ...` plus foreign key constraints. **Do not hand-edit the SQL.** Only modify `getDescription()` text (see step 2).

- [ ] **Step 2: Edit only `getDescription()`.**

Open the new migration file. Replace the auto-generated description with:

```php
    public function getDescription(): string
    {
        return '#78 — adds user_mail_configs (per-organizer SMTP DSN, encrypted at rest) '
            . 'and user_mail_config_audits (change history).';
    }
```

- [ ] **Step 3: Run the migration locally.**

```bash
bin/console doctrine:migrations:migrate --no-interaction
```

Expected: migration applies cleanly, no errors.

- [ ] **Step 4: Verify schema is in sync.**

```bash
bin/console doctrine:schema:validate
```

Expected: "Mapping is correct." AND "Schema is in sync with the mapping files."

- [ ] **Step 5: Apply against the test DB too.**

```bash
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test --no-interaction
bin/console doctrine:schema:validate --env=test
```

Expected: green on both.

- [ ] **Step 6: Commit.**

```bash
git add migrations/
git commit -m "78 - migration: user_mail_configs + user_mail_config_audits"
```

---

## Task 13: `TransportBuilder` (bypass for verification)

**Files:**
- Create: `src/Service/Mail/TransportBuilder.php`

- [ ] **Step 1: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

final readonly class TransportBuilder
{
    public function fromDsn(#[\SensitiveParameter] string $dsn): MailerInterface
    {
        return new Mailer(Transport::fromDsn($dsn));
    }
}
```

No test — pure delegation to framework code; meaningfully exercised by functional tests via the verification flow.

- [ ] **Step 2: Commit.**

```bash
git add src/Service/Mail/TransportBuilder.php
git commit -m "78 - add TransportBuilder for ad-hoc mailer construction"
```

---

## Task 14: `OrganizerMailerResolver` + integration test

**Files:**
- Create: `src/Service/Mail/OrganizerMailerResolver.php`
- Create: `tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`

- [ ] **Step 1: Write the failing integration test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\DsnVault;
use App\Service\Mail\OrganizerMailerResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;

final class OrganizerMailerResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OrganizerMailerResolver $resolver;
    private DsnVault $vault;
    private MailerInterface $platformMailer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->resolver = $container->get(OrganizerMailerResolver::class);
        $this->vault = $container->get(DsnVault::class);
        $this->platformMailer = $container->get(MailerInterface::class);
    }

    public function testReturnsPlatformMailerWhenUserHasNoConfig(): void
    {
        $user = $this->persistUser('no-config@example.com');

        self::assertSame($this->platformMailer, $this->resolver->forUser($user));
    }

    public function testReturnsPlatformMailerWhenConfigIsUnverified(): void
    {
        $user = $this->persistUser('pending@example.com');
        $config = new UserMailConfig(
            $user,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'pending@example.com',
            null,
        );
        $this->em->persist($config);
        $this->em->flush();

        self::assertSame($this->platformMailer, $this->resolver->forUser($user));
    }

    public function testReturnsCustomMailerWhenConfigIsVerified(): void
    {
        $user = $this->persistUser('verified@example.com');
        $config = new UserMailConfig(
            $user,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'verified@example.com',
            null,
        );
        $config->markVerified();
        $this->em->persist($config);
        $this->em->flush();

        $resolved = $this->resolver->forUser($user);

        self::assertNotSame($this->platformMailer, $resolved);
        self::assertInstanceOf(Mailer::class, $resolved);
    }

    public function testForEventDelegatesToOwner(): void
    {
        $owner = $this->persistUser('event-owner@example.com');
        $config = new UserMailConfig(
            $owner,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'event-owner@example.com',
            null,
        );
        $config->markVerified();
        $this->em->persist($config);

        $event = new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00'),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00'),
            owner: $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        self::assertNotSame($this->platformMailer, $this->resolver->forEvent($event));
    }

    public function testIsCustomActive(): void
    {
        $u1 = $this->persistUser('a@example.com');
        $u2 = $this->persistUser('b@example.com');
        $config = new UserMailConfig(
            $u2,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'b@example.com',
            null,
        );
        $config->markVerified();
        $this->em->persist($config);
        $this->em->flush();

        self::assertFalse($this->resolver->isCustomActive($u1));
        self::assertTrue($this->resolver->isCustomActive($u2));
    }

    private function persistUser(string $email): User
    {
        $user = new User($email, 'Display');
        $user->addRole('ROLE_ORGANIZER');
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
```

(The `Event` constructor is `(string $slug, string $name, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, User $owner)` per `src/Entity/Event.php`. Using named arguments to stay robust against future field-order changes.)

- [ ] **Step 2: Run, expect failure.**

```bash
vendor/bin/phpunit tests/Integration/Service/Mail/OrganizerMailerResolverTest.php
```

Expected: missing `App\Service\Mail\OrganizerMailerResolver` class.

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use SodiumException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

final readonly class OrganizerMailerResolver
{
    public function __construct(
        private DsnVault $vault,
        private MailerInterface $platformMailer,
        private LoggerInterface $logger,
    ) {
    }

    public function forEvent(Event $event): MailerInterface
    {
        return $this->forUser($event->getOwner());
    }

    public function forUser(User $user): MailerInterface
    {
        $config = $user->getMailConfig();
        if ($config === null || !$config->isVerified()) {
            return $this->platformMailer;
        }

        try {
            $dsn = $this->vault->decrypt($config->getEncryptedDsn());
        } catch (SodiumException $e) {
            $this->logger->error(
                'Falling back to platform mailer: cannot decrypt mail config DSN for user.',
                ['user_id' => $user->getId(), 'exception' => $e->getMessage()],
            );
            return $this->platformMailer;
        }

        return new Mailer(Transport::fromDsn($dsn));
    }

    public function isCustomActive(User $user): bool
    {
        $config = $user->getMailConfig();
        return $config !== null && $config->isVerified();
    }
}
```

- [ ] **Step 4: Re-run, expect pass.**

```bash
vendor/bin/phpunit tests/Integration/Service/Mail/OrganizerMailerResolverTest.php
```

Expected: 5 tests pass.

- [ ] **Step 5: Commit.**

```bash
git add src/Service/Mail/OrganizerMailerResolver.php tests/Integration/Service/Mail/OrganizerMailerResolverTest.php
git commit -m "78 - add OrganizerMailerResolver with platform fallback"
```

---

## Task 15: Wire `DnsResolver` autowiring alias

**Files:**
- Modify: `config/services.yaml`

- [ ] **Step 1: Add the alias under the prod `services:` block (after the existing `App\Service\Auth\GoogleOAuthClient` alias around line 25).**

```yaml
    App\Service\Mail\DnsResolver:
        alias: App\Service\Mail\SystemDnsResolver
```

- [ ] **Step 2: Verify the container compiles.**

```bash
bin/console cache:clear
bin/console debug:container App\\Service\\Mail\\DnsResolver
```

Expected: shows the alias resolves to `App\Service\Mail\SystemDnsResolver`.

- [ ] **Step 3: Commit.**

```bash
git add config/services.yaml
git commit -m "78 - alias App\\Service\\Mail\\DnsResolver to SystemDnsResolver"
```

---

## Task 16: Test infrastructure — `InMemoryTransport` + factory + facade

**Files:**
- Create: `tests/Mail/InMemoryTransport.php`
- Create: `tests/Mail/InMemoryTransportFactory.php`
- Create: `tests/Mail/CapturedMail.php`

(These live under `tests/Mail/` and are registered only in `when@test:` — never reachable in prod.)

- [ ] **Step 1: `CapturedMail` static facade.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use RuntimeException;
use Symfony\Component\Mime\RawMessage;
use Throwable;

final class CapturedMail
{
    /** @var array<string, list<RawMessage>> */
    private static array $byHost = [];

    /** @var array<string, Throwable> */
    private static array $throwOnHost = [];

    /** @return list<RawMessage> */
    public static function messagesForHost(string $host): array
    {
        return self::$byHost[strtolower($host)] ?? [];
    }

    public static function record(string $host, RawMessage $message): void
    {
        $key = strtolower($host);
        if (isset(self::$throwOnHost[$key])) {
            throw self::$throwOnHost[$key];
        }
        self::$byHost[$key][] = $message;
    }

    public static function throwOnHost(string $host, Throwable $e): void
    {
        self::$throwOnHost[strtolower($host)] = $e;
    }

    public static function reset(): void
    {
        self::$byHost = [];
        self::$throwOnHost = [];
    }

    public static function assertCapturedForHost(string $host, int $expected): void
    {
        $actual = count(self::messagesForHost($host));
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Expected %d messages for host %s, got %d.',
                $expected,
                $host,
                $actual,
            ));
        }
    }
}
```

- [ ] **Step 2: `InMemoryTransport`.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

final class InMemoryTransport extends AbstractTransport
{
    public function __construct(private readonly string $host)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return sprintf('in-memory://%s', $this->host);
    }

    protected function doSend(SentMessage $message): void
    {
        CapturedMail::record($this->host, $message->getOriginalMessage());
    }
}
```

- [ ] **Step 3: `InMemoryTransportFactory`.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class InMemoryTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        return new InMemoryTransport($dsn->getHost());
    }

    /** @return list<string> */
    protected function getSupportedSchemes(): array
    {
        return ['smtp', 'smtps'];
    }
}
```

- [ ] **Step 4: Register the factory under `when@test:` in `config/services.yaml`.**

Inside the `when@test:` block, alongside the existing test service definitions, append:

```yaml
        App\Tests\Mail\InMemoryTransportFactory:
            tags:
                - { name: 'mailer.transport_factory', priority: 100 }

        App\Tests\Mail\CapturedMail:
            public: true
```

The `priority: 100` ensures our factory wins over Symfony's built-in `EsmtpTransportFactory` for `smtp://` / `smtps://` DSNs in tests.

- [ ] **Step 5: Verify container compiles in test env.**

```bash
bin/console cache:clear --env=test
bin/console debug:container --env=test App\\Tests\\Mail\\InMemoryTransportFactory
```

Expected: factory is registered.

- [ ] **Step 6: Commit.**

```bash
git add tests/Mail/ config/services.yaml
git commit -m "78 - add in-memory test transport factory + CapturedMail facade"
```

---

## Task 17: `UserMailConfigType` form

**Files:**
- Create: `src/Form/UserMailConfigType.php`

- [ ] **Step 1: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UserMailConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dsn', TextareaType::class, [
                'label' => 'SMTP DSN',
                'help' => 'Format: smtp://user:password@smtp.example.com:587 or smtps://...',
                'attr' => ['rows' => 2, 'spellcheck' => 'false', 'autocomplete' => 'off'],
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 1024),
                ],
            ])
            ->add('fromAddr', TextType::class, [
                'label' => 'From address',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                    new Length(max: 254),
                ],
            ])
            ->add('fromName', TextType::class, [
                'label' => 'From display name',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new Length(max: 120),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'user_mail_config',
        ]);
    }
}
```

- [ ] **Step 2: Commit.**

```bash
git add src/Form/UserMailConfigType.php
git commit -m "78 - add UserMailConfigType form"
```

---

## Task 18: `Admin\AccountMailController` — edit + update

**Files:**
- Create: `src/Controller/Admin/AccountMailController.php`
- Create: `templates/admin/account/mail/edit.html.twig`
- Create: `templates/email/mail-config/verify.html.twig`
- Create: `templates/email/mail-config/verify.txt.twig`

- [ ] **Step 1: Controller skeleton with `edit` + `update` actions.**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Entity\UserMailConfigAudit;
use App\Enum\MailConfigAuditAction;
use App\Form\UserMailConfigType;
use App\Repository\UserMailConfigRepository;
use App\Service\Mail\DsnRejected;
use App\Service\Mail\DsnValidator;
use App\Service\Mail\DsnVault;
use App\Service\Mail\TransportBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin/account/mail')]
final class AccountMailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserMailConfigRepository $configs,
        private readonly DsnValidator $validator,
        private readonly DsnVault $vault,
        private readonly TransportBuilder $transports,
    ) {
    }

    #[Route('', name: 'admin_account_mail_edit', methods: ['GET'])]
    public function edit(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(UserMailConfigType::class, null, [
            'action' => $this->generateUrl('admin_account_mail_update'),
        ]);

        return $this->render('admin/account/mail/edit.html.twig', [
            'form' => $form->createView(),
            'config' => $user->getMailConfig(),
        ]);
    }

    #[Route('', name: 'admin_account_mail_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(UserMailConfigType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/account/mail/edit.html.twig', [
                'form' => $form->createView(),
                'config' => $user->getMailConfig(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $dsn = (string) $form->get('dsn')->getData();
        $fromAddr = (string) $form->get('fromAddr')->getData();
        $fromName = $form->get('fromName')->getData();
        $fromName = is_string($fromName) && $fromName !== '' ? $fromName : null;

        try {
            $this->validator->validate($dsn);
        } catch (DsnRejected $e) {
            $form->get('dsn')->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
            return $this->render('admin/account/mail/edit.html.twig', [
                'form' => $form->createView(),
                'config' => $user->getMailConfig(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $envelope = $this->vault->encrypt($dsn);
        $config = $user->getMailConfig();
        if ($config === null) {
            $config = new UserMailConfig($user, $envelope, $fromAddr, $fromName);
            $user->setMailConfig($config);
            $this->em->persist($config);
        } else {
            $config->applyConfig($envelope, $fromAddr, $fromName);
        }

        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::Set,
            fromAddrSnapshot: $fromAddr,
        ));
        $this->em->flush();

        // DB committed; now attempt the verification email through the candidate transport.
        try {
            $this->sendVerification($config, $dsn);
            $this->addFlash('success', sprintf(
                'Verification email sent to %s. Click the link within 24 hours.',
                $fromAddr,
            ));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('warning', sprintf(
                'Mail configuration saved but verification email could not be delivered: %s. Click "Resend verification" to retry.',
                $e->getMessage(),
            ));
        }

        return $this->redirectToRoute('admin_account_mail_edit');
    }

    private function sendVerification(UserMailConfig $config, string $dsn): void
    {
        $token = (string) $config->getVerificationToken();
        $verifyUrl = $this->generateUrl(
            'admin_account_mail_verify',
            ['token' => $token],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $email = (new TemplatedEmail())
            ->from($config->getFromName() !== null
                ? new Address($config->getFromAddr(), $config->getFromName())
                : new Address($config->getFromAddr()))
            ->to($config->getFromAddr())
            ->subject('Verify your eventFotos mail configuration')
            ->htmlTemplate('email/mail-config/verify.html.twig')
            ->textTemplate('email/mail-config/verify.txt.twig')
            ->context(['verifyUrl' => $verifyUrl]);

        $mailer = $this->transports->fromDsn($dsn);
        $mailer->send($email);
    }
}
```

- [ ] **Step 2: Twig — edit page.**

`templates/admin/account/mail/edit.html.twig`:

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Mail configuration{% endblock %}

{% block body %}
  <h1 class="text-2xl font-semibold mb-4">Mail configuration</h1>

  <p class="mb-4 text-sm text-gray-600">
    Configure an SMTP transport so event emails are sent from your own domain.
    Without a verified configuration, event mail goes out from the platform default.
  </p>

  <div class="mb-6">
    {% if config is null %}
      <span class="inline-block px-2 py-1 rounded bg-gray-100 text-gray-700 text-xs">Not configured</span>
    {% elseif config.verified %}
      <span class="inline-block px-2 py-1 rounded bg-green-100 text-green-800 text-xs">Verified</span>
      <span class="text-xs text-gray-500 ml-2">since {{ config.verifiedAt|date('Y-m-d H:i') }}</span>
    {% else %}
      <span class="inline-block px-2 py-1 rounded bg-yellow-100 text-yellow-800 text-xs">Pending verification</span>
      <span class="text-xs text-gray-500 ml-2">sent {{ config.verificationSentAt|date('Y-m-d H:i') }}</span>
    {% endif %}
  </div>

  {{ form_start(form, {attr: {class: 'space-y-4 max-w-xl'}}) }}
    {{ form_row(form.dsn) }}
    {{ form_row(form.fromAddr) }}
    {{ form_row(form.fromName) }}
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save and send verification</button>
  {{ form_end(form) }}

  {% if config is not null %}
    <div class="mt-6 flex gap-3">
      <form method="post" action="{{ path('admin_account_mail_resend') }}">
        <input type="hidden" name="_token" value="{{ csrf_token('mail_config_resend') }}">
        <button type="submit" class="px-3 py-1 text-sm border rounded">Resend verification</button>
      </form>
      <form method="post" action="{{ path('admin_account_mail_clear') }}"
            data-turbo-confirm="Clear mail configuration? Event mail will fall back to the platform default.">
        <input type="hidden" name="_token" value="{{ csrf_token('mail_config_clear') }}">
        <button type="submit" class="px-3 py-1 text-sm border rounded text-red-700">Clear configuration</button>
      </form>
    </div>
  {% endif %}
{% endblock %}
```

- [ ] **Step 3: Verification email — HTML.**

`templates/email/mail-config/verify.html.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="referrer" content="no-referrer">
  <title>Verify your eventFotos mail configuration</title>
</head>
<body style="font-family: sans-serif;">
  <p>Hi,</p>
  <p>You (or an admin acting on your behalf) configured a custom mail transport for your eventFotos account.</p>
  <p>To activate it, click the link below within 24 hours:</p>
  <p><a href="{{ verifyUrl }}">{{ verifyUrl }}</a></p>
  <p>If you didn't expect this email, you can ignore it.</p>
</body>
</html>
```

- [ ] **Step 4: Verification email — text.**

`templates/email/mail-config/verify.txt.twig`:

```twig
Hi,

You (or an admin acting on your behalf) configured a custom mail transport for your eventFotos account.

To activate it, click the link below within 24 hours:

{{ verifyUrl }}

If you didn't expect this email, you can ignore it.
```

- [ ] **Step 5: Boot the kernel to surface any wiring errors.**

```bash
bin/console cache:clear
bin/console debug:router admin_account_mail_edit
bin/console debug:router admin_account_mail_update
```

Expected: both routes resolve to `App\Controller\Admin\AccountMailController`.

- [ ] **Step 6: Commit.**

```bash
git add src/Controller/Admin/AccountMailController.php \
        templates/admin/account/mail/ \
        templates/email/mail-config/
git commit -m "78 - add AccountMailController edit/update + templates"
```

---

## Task 19: `AccountMailController` — verify

**Files:**
- Modify: `src/Controller/Admin/AccountMailController.php`

- [ ] **Step 1: Add the `verify` action method to the controller.**

Append inside the class, after `sendVerification(...)`:

```php
    #[Route('/verify/{token}', name: 'admin_account_mail_verify', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{16,128}'])]
    public function verify(string $token): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $config = $this->configs->findOneByVerificationToken($token);

        if ($config === null || $config->getVerificationSentAt() === null) {
            throw $this->createNotFoundException();
        }

        if ($config->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $age = (new \DateTimeImmutable())->getTimestamp() - $config->getVerificationSentAt()->getTimestamp();
        if ($age > 86_400) {
            $this->addFlash('warning', 'Verification link expired. Use "Resend verification" to generate a new one.');
            return $this->redirectToRoute('admin_account_mail_edit');
        }

        $config->markVerified();
        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::Verified,
            fromAddrSnapshot: $config->getFromAddr(),
        ));
        $this->em->flush();

        $this->addFlash('success', 'Mail configuration verified. Event emails will now be sent from your configured address.');
        return $this->redirectToRoute('admin_account_mail_edit');
    }
```

- [ ] **Step 2: Verify route is registered.**

```bash
bin/console debug:router admin_account_mail_verify
```

Expected: shows the route with `{token}` placeholder.

- [ ] **Step 3: Commit.**

```bash
git add src/Controller/Admin/AccountMailController.php
git commit -m "78 - AccountMailController: add verify action"
```

---

## Task 20: `AccountMailController` — resend + clear

**Files:**
- Modify: `src/Controller/Admin/AccountMailController.php`

- [ ] **Step 1: Add the two actions.**

Append inside the controller class:

```php
    #[Route('/resend', name: 'admin_account_mail_resend', methods: ['POST'])]
    public function resendVerification(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('mail_config_resend', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $config = $user->getMailConfig();
        if ($config === null) {
            throw $this->createNotFoundException();
        }

        $config->regenerateVerificationToken();
        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::VerificationResent,
            fromAddrSnapshot: $config->getFromAddr(),
        ));
        $this->em->flush();

        try {
            $this->sendVerification($config, $this->vault->decrypt($config->getEncryptedDsn()));
            $this->addFlash('success', sprintf('Verification email resent to %s.', $config->getFromAddr()));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('warning', sprintf('Could not deliver verification email: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('admin_account_mail_edit');
    }

    #[Route('/clear', name: 'admin_account_mail_clear', methods: ['POST'])]
    public function clear(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('mail_config_clear', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $config = $user->getMailConfig();
        if ($config === null) {
            return $this->redirectToRoute('admin_account_mail_edit');
        }

        $fromAddrSnapshot = $config->getFromAddr();
        $user->setMailConfig(null);
        $this->em->remove($config);
        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::Cleared,
            fromAddrSnapshot: $fromAddrSnapshot,
        ));
        $this->em->flush();

        $this->addFlash('success', 'Mail configuration cleared. Event emails will be sent from the platform default.');
        return $this->redirectToRoute('admin_account_mail_edit');
    }
```

- [ ] **Step 2: Verify routes are registered.**

```bash
bin/console debug:router | grep admin_account_mail
```

Expected: 5 routes (`edit`, `update`, `verify`, `resend`, `clear`).

- [ ] **Step 3: Commit.**

```bash
git add src/Controller/Admin/AccountMailController.php
git commit -m "78 - AccountMailController: add resend + clear actions"
```

---

## Task 21: Functional test — full self-service happy path

**Files:**
- Create: `tests/Functional/Admin/AccountMailFlowTest.php`

- [ ] **Step 1: Write the test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Mail\CapturedMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountMailFlowTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        CapturedMail::reset();
    }

    public function testFullVerifyCycle(): void
    {
        $user = $this->createOrganizer('flow@example.com', 'secret');
        $this->client->loginUser($user);

        // 1) Submit a valid DSN.
        $crawler = $this->client->request('GET', '/admin/account/mail');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
            'user_mail_config[fromAddr]' => 'press@example-organizer.test',
            'user_mail_config[fromName]' => 'Example Press',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');

        // 2) Verification email captured by the custom transport, not the platform default.
        $messages = CapturedMail::messagesForHost('smtp.example-organizer.test');
        self::assertCount(1, $messages);
        self::assertSame([], self::getMailerMessages());  // platform null transport untouched

        // 3) Extract the verify URL from the message body.
        $body = $messages[0]->toString();
        self::assertMatchesRegularExpression(
            '#http://localhost/admin/account/mail/verify/[A-Za-z0-9_-]+#',
            $body,
            $matchesOut,
        );
        preg_match('#http://localhost(/admin/account/mail/verify/[A-Za-z0-9_-]+)#', $body, $m);
        self::assertNotEmpty($m, 'verify URL must appear in message body');
        $verifyPath = $m[1];

        // 4) Hit the verify link → config marked verified.
        $this->client->request('GET', $verifyPath);
        self::assertResponseRedirects('/admin/account/mail');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded?->getMailConfig());
        self::assertTrue($reloaded->getMailConfig()->isVerified());
    }

    private function createOrganizer(string $email, string $password): User
    {
        $user = new User($email, 'Flow');
        $user->addRole('ROLE_ORGANIZER');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
```

- [ ] **Step 2: Run, expect pass.**

```bash
vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php
```

Expected: test passes. If it fails on `self::getMailerMessages()` assertion (platform mailer was hit), check `config/services.yaml`'s `when@test:` block for the `priority: 100` tag on the factory.

- [ ] **Step 3: Commit.**

```bash
git add tests/Functional/Admin/AccountMailFlowTest.php
git commit -m "78 - functional test: full self-service mail config verify cycle"
```

---

## Task 22: Functional test — DSN rejection + CSRF + expiry + one-shot + cross-user

**Files:**
- Modify: `tests/Functional/Admin/AccountMailFlowTest.php`

- [ ] **Step 1: Append cases.**

Add these methods inside `AccountMailFlowTest`:

```php
    public function testRejectsLoopbackHost(): void
    {
        $user = $this->createOrganizer('reject@example.com', 'secret');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@127.0.0.1:25',
            'user_mail_config[fromAddr]' => 'x@example.com',
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], CapturedMail::messagesForHost('127.0.0.1'));

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNull($reloaded?->getMailConfig());
    }

    public function testCsrfRejectionOnClear(): void
    {
        $user = $this->createOrganizer('csrf@example.com', 'secret');
        $this->client->loginUser($user);
        $this->saveValidConfig($user);

        $this->client->request('POST', '/admin/account/mail/clear', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded?->getMailConfig());  // still there
    }

    public function testVerifyTokenExpired(): void
    {
        $user = $this->createOrganizer('expired@example.com', 'secret');
        $this->client->loginUser($user);
        $this->saveValidConfig($user);

        // Age the verification_sent_at past 24h via raw SQL (the test transaction holds).
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'UPDATE user_mail_configs SET verification_sent_at = verification_sent_at - INTERVAL \'25 hours\' WHERE user_id = :uid',
            ['uid' => $user->getId()],
        );

        $token = $this->grabPendingToken($user);
        $this->client->request('GET', '/admin/account/mail/verify/' . $token);
        self::assertResponseRedirects('/admin/account/mail');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertFalse($reloaded?->getMailConfig()->isVerified());
    }

    public function testVerifyTokenOneShot(): void
    {
        $user = $this->createOrganizer('once@example.com', 'secret');
        $this->client->loginUser($user);
        $this->saveValidConfig($user);

        $token = $this->grabPendingToken($user);
        $this->client->request('GET', '/admin/account/mail/verify/' . $token);
        self::assertResponseRedirects('/admin/account/mail');

        $this->client->request('GET', '/admin/account/mail/verify/' . $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCrossUserVerifyForbidden(): void
    {
        $owner = $this->createOrganizer('owner@example.com', 'secret');
        $this->saveValidConfig($owner);
        $token = $this->grabPendingToken($owner);

        $attacker = $this->createOrganizer('attacker@example.com', 'secret');
        $this->client->loginUser($attacker);

        $this->client->request('GET', '/admin/account/mail/verify/' . $token);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($owner->getId());
        self::assertFalse($reloaded?->getMailConfig()->isVerified());
    }

    private function saveValidConfig(User $user): void
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
            'user_mail_config[fromAddr]' => $user->getEmail(),
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');
    }

    private function grabPendingToken(User $user): string
    {
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $token = $reloaded?->getMailConfig()?->getVerificationToken();
        if ($token === null) {
            self::fail('No pending verification token for user.');
        }
        return $token;
    }
```

**Note about `FakeDnsResolver` in functional tests:** the DSN validator in functional tests will call `SystemDnsResolver` against `smtp.example-organizer.test` (which won't resolve) unless we override. Add a `when@test:` alias to bind `App\Service\Mail\DnsResolver` to a configured fake.

Edit `config/services.yaml`, inside `when@test:` services, append:

```yaml
        App\Tests\Fake\PrebakedDnsResolver:
            public: true

        App\Service\Mail\DnsResolver:
            alias: App\Tests\Fake\PrebakedDnsResolver
```

And create `tests/Fake/PrebakedDnsResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Mail\DnsResolver;

final class PrebakedDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $host = strtolower($host);
        return match (true) {
            str_ends_with($host, '.example-organizer.test') => ['93.184.216.34'],
            $host === '127.0.0.1' => ['127.0.0.1'],
            preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host) === 1 => [$host],
            default => [],
        };
    }
}
```

This makes `smtp.example-organizer.test` resolve to a public IP for happy-path tests, while `127.0.0.1` (as literal host) still gets rejected by the validator. Other literal IPs pass through as-is (they're resolved as themselves), letting the rejection test cases work without changing them.

- [ ] **Step 2: Run, expect pass.**

```bash
vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php
```

Expected: 6 tests pass.

- [ ] **Step 3: Commit.**

```bash
git add tests/Functional/Admin/AccountMailFlowTest.php tests/Fake/PrebakedDnsResolver.php config/services.yaml
git commit -m "78 - functional tests: rejection, csrf, expiry, one-shot, cross-user"
```

---

## Task 23: Functional test — verification send failure keeps config unverified

**Files:**
- Modify: `tests/Functional/Admin/AccountMailFlowTest.php`

- [ ] **Step 1: Append the test.**

Add inside `AccountMailFlowTest`:

```php
    public function testVerificationEmailFailureKeepsConfigUnverified(): void
    {
        $user = $this->createOrganizer('fail@example.com', 'secret');
        $this->client->loginUser($user);

        CapturedMail::throwOnHost(
            'smtp.fail.example-organizer.test',
            new \Symfony\Component\Mailer\Exception\TransportException(
                'Authentication failed: 535 5.7.8 Username and Password not accepted',
            ),
        );

        $crawler = $this->client->request('GET', '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:bad@smtp.fail.example-organizer.test:25',
            'user_mail_config[fromAddr]' => 'press@example-organizer.test',
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');

        // Config persisted (DB-first), but unverified, with the SMTP error in the flash.
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $config = $reloaded?->getMailConfig();
        self::assertNotNull($config);
        self::assertFalse($config->isVerified());

        $this->client->followRedirect();
        self::assertStringContainsString('Authentication failed', $this->client->getResponse()->getContent() ?: '');
    }
```

The `PrebakedDnsResolver` already returns a public IP for any `*.example-organizer.test` host, so the validator lets this through and the failure happens at send time — which is what we want to test.

- [ ] **Step 2: Run, expect pass.**

```bash
vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php::testVerificationEmailFailureKeepsConfigUnverified
```

Expected: pass.

- [ ] **Step 3: Commit.**

```bash
git add tests/Functional/Admin/AccountMailFlowTest.php
git commit -m "78 - functional test: send failure keeps config unverified"
```

---

## Task 24: `Admin\UserMailController` for admin-edit-other-user

**Files:**
- Create: `src/Controller/Admin/UserMailController.php`
- Create: `tests/Functional/Admin/UserMailFlowTest.php`

- [ ] **Step 1: Controller.**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Entity\UserMailConfigAudit;
use App\Enum\MailConfigAuditAction;
use App\Form\UserMailConfigType;
use App\Repository\UserMailConfigRepository;
use App\Service\Mail\DsnRejected;
use App\Service\Mail\DsnValidator;
use App\Service\Mail\DsnVault;
use App\Service\Mail\TransportBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users/{id}/mail', requirements: ['id' => '\d+'])]
final class UserMailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserMailConfigRepository $configs,
        private readonly DsnValidator $validator,
        private readonly DsnVault $vault,
        private readonly TransportBuilder $transports,
    ) {
    }

    #[Route('', name: 'admin_user_mail_edit', methods: ['GET'])]
    public function edit(User $target): Response
    {
        $form = $this->createForm(UserMailConfigType::class, null, [
            'action' => $this->generateUrl('admin_user_mail_update', ['id' => $target->getId()]),
        ]);

        return $this->render('admin/account/mail/edit.html.twig', [
            'form' => $form->createView(),
            'config' => $target->getMailConfig(),
            'target' => $target,
        ]);
    }

    #[Route('', name: 'admin_user_mail_update', methods: ['POST'])]
    public function update(User $target, Request $request): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $form = $this->createForm(UserMailConfigType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/account/mail/edit.html.twig', [
                'form' => $form->createView(),
                'config' => $target->getMailConfig(),
                'target' => $target,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $dsn = (string) $form->get('dsn')->getData();
        $fromAddr = (string) $form->get('fromAddr')->getData();
        $fromName = $form->get('fromName')->getData();
        $fromName = is_string($fromName) && $fromName !== '' ? $fromName : null;

        try {
            $this->validator->validate($dsn);
        } catch (DsnRejected $e) {
            $form->get('dsn')->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
            return $this->render('admin/account/mail/edit.html.twig', [
                'form' => $form->createView(),
                'config' => $target->getMailConfig(),
                'target' => $target,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $envelope = $this->vault->encrypt($dsn);
        $config = $target->getMailConfig();
        if ($config === null) {
            $config = new UserMailConfig($target, $envelope, $fromAddr, $fromName);
            $target->setMailConfig($config);
            $this->em->persist($config);
        } else {
            $config->applyConfig($envelope, $fromAddr, $fromName);
        }

        $this->em->persist(new UserMailConfigAudit(
            user: $target,
            actor: $actor,
            actorEmailSnapshot: $actor->getEmail(),
            action: MailConfigAuditAction::Set,
            fromAddrSnapshot: $fromAddr,
        ));
        $this->em->flush();

        try {
            $token = (string) $config->getVerificationToken();
            $verifyUrl = $this->generateUrl(
                'admin_account_mail_verify',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $email = (new TemplatedEmail())
                ->from($config->getFromName() !== null
                ? new Address($config->getFromAddr(), $config->getFromName())
                : new Address($config->getFromAddr()))
                ->to($config->getFromAddr())
                ->subject('Verify your eventFotos mail configuration')
                ->htmlTemplate('email/mail-config/verify.html.twig')
                ->textTemplate('email/mail-config/verify.txt.twig')
                ->context(['verifyUrl' => $verifyUrl]);
            $this->transports->fromDsn($dsn)->send($email);
            $this->addFlash('success', sprintf('Verification email sent to %s.', $fromAddr));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('warning', sprintf(
                'Mail configuration saved but verification email could not be delivered: %s',
                $e->getMessage(),
            ));
        }

        return $this->redirectToRoute('admin_user_mail_edit', ['id' => $target->getId()]);
    }

    #[Route('/clear', name: 'admin_user_mail_clear', methods: ['POST'])]
    public function clear(User $target, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('mail_config_clear', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $config = $target->getMailConfig();
        if ($config === null) {
            return $this->redirectToRoute('admin_user_mail_edit', ['id' => $target->getId()]);
        }

        $fromAddrSnapshot = $config->getFromAddr();
        $target->setMailConfig(null);
        $this->em->remove($config);
        $this->em->persist(new UserMailConfigAudit(
            user: $target,
            actor: $actor,
            actorEmailSnapshot: $actor->getEmail(),
            action: MailConfigAuditAction::Cleared,
            fromAddrSnapshot: $fromAddrSnapshot,
        ));
        $this->em->flush();

        $this->addFlash('success', 'Cleared mail configuration for ' . $target->getEmail());
        return $this->redirectToRoute('admin_user_mail_edit', ['id' => $target->getId()]);
    }
}
```

- [ ] **Step 2: Functional test for admin path.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Entity\UserMailConfigAudit;
use App\Tests\Mail\CapturedMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserMailFlowTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        CapturedMail::reset();
    }

    public function testAdminCanConfigureMailForOtherUser(): void
    {
        $admin = $this->createUser('admin@example.com', 'ROLE_ADMIN');
        $target = $this->createUser('target@example.com', 'ROLE_ORGANIZER');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId() . '/mail');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
            'user_mail_config[fromAddr]' => 'target@example-organizer.test',
            'user_mail_config[fromName]' => 'Target',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $target->getId() . '/mail');

        $messages = CapturedMail::messagesForHost('smtp.example-organizer.test');
        self::assertCount(1, $messages);

        // Audit row records admin as actor, target as subject.
        $this->em->clear();
        $audits = $this->em->getRepository(UserMailConfigAudit::class)->findAll();
        self::assertCount(1, $audits);
        $audit = $audits[0];
        self::assertSame($target->getEmail(), $audit->getUser()->getEmail());
        self::assertSame($admin->getEmail(), $audit->getActor()?->getEmail());
        self::assertSame($admin->getEmail(), $audit->getActorEmailSnapshot());
    }

    public function testOrganizerCannotEditOtherUsersMail(): void
    {
        $organizer = $this->createUser('organizer@example.com', 'ROLE_ORGANIZER');
        $target = $this->createUser('victim@example.com', 'ROLE_ORGANIZER');
        $this->client->loginUser($organizer);

        $this->client->request('GET', '/admin/users/' . $target->getId() . '/mail');
        self::assertResponseStatusCodeSame(403);
    }

    private function createUser(string $email, string $role): User
    {
        $user = new User($email, 'Display');
        $user->addRole($role);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'secret'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
```

- [ ] **Step 3: Run, expect pass.**

```bash
vendor/bin/phpunit tests/Functional/Admin/UserMailFlowTest.php
```

Expected: 2 tests pass.

- [ ] **Step 4: Commit.**

```bash
git add src/Controller/Admin/UserMailController.php tests/Functional/Admin/UserMailFlowTest.php
git commit -m "78 - add UserMailController + admin-edit-other-user functional tests"
```

---

## Task 25: Navigation link from admin shell

**Files:**
- Modify: `templates/admin/_base.html.twig`

- [ ] **Step 1: Locate the nav block.**

```bash
grep -n "account\|password\|sessions" templates/admin/_base.html.twig | head -20
```

This will show existing account-area links (e.g. password change, sessions). The Mail link should sit alongside them.

- [ ] **Step 2: Add a "Mail" entry next to the existing account links.**

Edit `templates/admin/_base.html.twig`. Find the existing account-menu block (look for the dropdown / link group that holds "Change password" or "Sessions"). Add immediately after the existing account-sessions link:

```twig
            <a href="{{ path('admin_account_mail_edit') }}" class="block px-3 py-2 text-sm hover:bg-gray-100">Mail</a>
```

Adjust the class names to match the surrounding nav style.

- [ ] **Step 3: Smoke-test the link renders.**

```bash
bin/console cache:clear
```

Then visit `/admin/account/mail` in the browser (after `docker compose up -d`); confirm the page loads and the nav link is present.

- [ ] **Step 4: Commit.**

```bash
git add templates/admin/_base.html.twig
git commit -m "78 - add Mail link to admin account nav"
```

---

## Task 26: CLAUDE.md note + spec runbook reference

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a short section.**

Open `CLAUDE.md`. After the existing "### Federated identity (Google SSO)" section, insert:

```markdown
### Per-organizer mail transport

Organizers can configure their own SMTP transport (encrypted at rest with libsodium, keyed by `MAIL_CONFIG_ENCRYPTION_KEY`). `App\Service\Mail\OrganizerMailerResolver::forEvent($event)` returns the organizer's verified mailer or falls back to the platform `MAILER_DSN`. Event-scoped senders inject the resolver and call `$this->resolver->forEvent($event)->send($email)`. Platform-level flows (invitations, password reset) keep using the autowired `MailerInterface` — they have no event context. The resolver hard-fails (no silent fallback) when a verified transport throws at send time; Messenger's retry/dead-letter handles the recovery. Operational prerequisites (key generation, backup) are in `docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md`.
```

- [ ] **Step 2: Commit.**

```bash
git add CLAUDE.md
git commit -m "78 - document per-organizer mail transport in CLAUDE.md"
```

---

## Task 27: Full quality + test gate

- [ ] **Step 1: Run the full test suite.**

```bash
vendor/bin/phpunit
```

Expected: all green, including pre-existing tests.

- [ ] **Step 2: Run GrumPHP (mirrors CI).**

```bash
vendor/bin/grumphp run
```

Expected: PHPStan level 10 clean, PHPCS clean, Rector clean, doctrine:schema:validate green, no copy-paste violations.

- [ ] **Step 3: Run schema-validate explicitly for both envs.**

```bash
bin/console doctrine:schema:validate
bin/console doctrine:schema:validate --env=test
```

Expected: "Mapping is correct" + "Schema is in sync" on both.

- [ ] **Step 4: If anything fails, fix in place and commit per-failure with `78 - fix: <what>`.** Do NOT bundle fixes into a generic "fix CI" commit.

---

## Task 28: Manual smoke (host browser)

- [ ] **Step 1: Boot the stack and log in.**

```bash
docker compose up -d
```

Visit `http://localhost:8080/admin/account/mail` as an organizer.

- [ ] **Step 2: Submit a deliberately invalid DSN (e.g. `smtp://x@localhost:25`).**

Expected: page re-renders with a field-level error mentioning "non-public address". No row written.

- [ ] **Step 3: Submit a real public DSN you control** (e.g. your personal SMTP) **with a `from_addr` you own.**

Expected: redirect back, flash "Verification email sent to ...". Check your inbox — the message should have arrived **from your configured `from_addr`**, not from `no-reply@eventfotos.local`. Click the link.

Expected: redirect back, flash "Mail configuration verified." Badge changes to "Verified".

- [ ] **Step 4: Hit "Clear configuration".**

Expected: row deleted, badge back to "Not configured", audit row written.

- [ ] **Step 5: Verify no schema regression.**

```bash
bin/console doctrine:schema:validate
```

- [ ] **Step 6: Final branch state check.**

```bash
git status
git log --oneline main..HEAD
```

Expected: clean working tree, commits all prefixed with `78 -`.

---

## Out of band

- Open a PR with the title `78 - per-organizer mail transport configuration` and include the spec link + the acceptance checklist from the spec in the PR description.
- Document `MAIL_CONFIG_ENCRYPTION_KEY` in the deploy runbook (separate from this branch): how to generate, where it lives in TrueNAS env, backup procedure.
- File a follow-up issue (or annotate #75) noting that `user_mail_config_audits` is the first audit table and should be folded into the generic audit table when #75 lands.
