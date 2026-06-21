# Visitor Email Notification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let event visitors double-opt-in to a single "photos are live" email, sent through the organizer's own verified mail transport at a throttled rate when the organizer publishes the event.

**Architecture:** A new `EventNotificationSubscription` entity holds the double-opt-in state machine. Public sessionless routes handle signup/confirm/unsubscribe; an admin publish endpoint flips a one-shot `Event::publishedAt` and dispatches a fan-out that emits one throttled Messenger message per confirmed subscriber. All mail goes through a now-strict `OrganizerMailerResolver` that throws instead of ever falling back to platform mail.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 / DBAL 4, PostgreSQL 16, Symfony Messenger (Doctrine transport), Symfony RateLimiter, Twig, PHPUnit 13.

## Global Constraints

- PHP attributes only — never annotations.
- Spec: `docs/superpowers/specs/2026-06-21-77-visitor-email-notification-design.md`.
- All entity IDs are bigint autoincrement (`#[ORM\GeneratedValue] #[ORM\Column(type: Types::INTEGER)]`).
- Every file starts with `declare(strict_types=1);`.
- Domain state-machine methods throw `\DomainException` on illegal transitions (mirror `Photo`).
- Public routes (`Public\*`) are anonymous and **must not touch the session** — no flashes, no CSRF tokens, no `getSession()`. Re-verify after implementation with: `grep -rn "addFlash\|getSession\|csrf_token" src/Controller/Public/ templates/public/`.
- Tokens: `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` (URL-safe base64, no padding) — the `UserMailConfig` convention.
- Times: construct "now" as `new DateTimeImmutable('now', new DateTimeZone('UTC'))`; domain methods accept `$now` as a parameter so they are deterministic in tests. Columns map via `Types::DATETIME_IMMUTABLE` (matches existing `Event::$startsAt`).
- **Never hand-write migrations** — generate via `bin/console doctrine:migrations:diff`, edit only `getDescription()`.
- Run PHP/Composer/`bin/console`/`vendor/bin/*` on the host.
- Quality gate before declaring done: `vendor/bin/grumphp run` (phpstan level 10, phpcs PSR-12, phpmnd, phpcpd, rector, schema:validate).
- Commits: this repo blocks direct commits to `main`; work happens on branch `feature/77-visitor-email-notification` (already created). Every commit message must contain the issue number `77`. The human runs the actual commits — propose messages, do not auto-commit unless executing under a workflow that the user drives.
- No `MAIL_FROM` env. The organizer transport owns its sender identity.

---

### Task 1: Strict `OrganizerMailerResolver` contract

Remove the platform-mail fallback. `forEvent`/`forUser` now return the organizer's verified mailer or throw. This is the foundation the send paths rely on.

**Files:**
- Create: `src/Service/Mail/OrganizerMailNotConfiguredException.php`
- Modify: `src/Service/Mail/OrganizerMailerResolver.php`
- Modify (test): `tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`

**Interfaces:**
- Consumes: `UserMailConfig::isVerified()`, `DsnVault::decrypt()`, `PinnedTransportFactory::create()`, `DsnRejected`.
- Produces:
  - `OrganizerMailerResolver::forEvent(Event $event): MailerInterface` — returns custom `Mailer`, or throws `OrganizerMailNotConfiguredException`.
  - `OrganizerMailerResolver::forUser(User $user): MailerInterface` — same.
  - `OrganizerMailerResolver::isCustomActive(User $user): bool` — unchanged.
  - `OrganizerMailNotConfiguredException extends \RuntimeException`.

- [ ] **Step 1: Update the integration test for the no-config / unverified cases**

In `tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`, replace the two platform-fallback assertions and add a corrupted-ciphertext case. Add `use App\Service\Mail\OrganizerMailNotConfiguredException;`.

```php
public function testThrowsWhenUserHasNoConfig(): void
{
    $user = $this->persistUser('no-config@example.com');

    $this->expectException(OrganizerMailNotConfiguredException::class);
    $this->resolver->forUser($user);
}

public function testThrowsWhenConfigIsUnverified(): void
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

    $this->expectException(OrganizerMailNotConfiguredException::class);
    $this->resolver->forUser($user);
}

public function testThrowsOnCorruptedCiphertextInsteadOfFallingBack(): void
{
    $user = $this->persistUser('corrupt@example.com');
    $config = new UserMailConfig($user, 'not-valid-ciphertext', 'corrupt@example.com', null);
    $config->markVerified();
    $this->em->persist($config);
    $this->em->flush();

    $this->expectException(OrganizerMailNotConfiguredException::class);
    $this->resolver->forUser($user);
}
```

The `testReturnsCustomMailerWhenConfigIsVerified`, `testForEventDelegatesToOwner`, `testIsCustomActive`, and `testRebindToInternalIpIsRefusedAndAutoUnverifiesAtSendTime` tests stay as-is (the rebind path is unchanged).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`
Expected: FAIL — `OrganizerMailNotConfiguredException` class not found / current code returns the platform mailer.

- [ ] **Step 3: Create the exception**

`src/Service/Mail/OrganizerMailNotConfiguredException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

final class OrganizerMailNotConfiguredException extends \RuntimeException
{
}
```

- [ ] **Step 4: Rewrite the resolver to be strict**

Replace `src/Service/Mail/OrganizerMailerResolver.php` with:

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
            throw new OrganizerMailNotConfiguredException(
                'Organizer has no verified mail transport.',
            );
        }

        try {
            $dsn = $this->vault->decrypt($config->getEncryptedDsn());
        } catch (SodiumException $sodiumException) {
            $this->logger->error(
                'Cannot decrypt mail config DSN for user; refusing to fall back to platform mail.',
                ['user_id' => $user->getId(), 'exception' => $sodiumException->getMessage()],
            );

            throw new OrganizerMailNotConfiguredException(
                'Stored mail transport ciphertext is corrupted.',
                0,
                $sodiumException,
            );
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

Note: the `MailerInterface $platformMailer` constructor argument is removed. If `config/services.yaml` wires this service explicitly with that argument, drop it; if it is autowired, no config change is needed (verify with `grep -n "OrganizerMailerResolver" config/services.yaml`).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`
Expected: PASS (all cases).

- [ ] **Step 6: Verify no other production caller depended on the fallback**

Run: `grep -rn "forEvent\|forUser" src/ | grep -v "function forEvent\|function forUser"`
Expected: only the internal `forEvent → forUser` delegation in the resolver itself. (Other call sites are added by later tasks.)

- [ ] **Step 7: Commit**

```bash
git add src/Service/Mail/OrganizerMailNotConfiguredException.php src/Service/Mail/OrganizerMailerResolver.php tests/Integration/Service/Mail/OrganizerMailerResolverTest.php
git commit -m "77 - make OrganizerMailerResolver strict: throw instead of platform fallback"
```

---

### Task 2: `Event` publish + notifications-enabled state

**Files:**
- Modify: `src/Entity/Event.php`
- Test: `tests/Unit/Entity/EventNotificationStateTest.php`

**Interfaces:**
- Produces:
  - `Event::markPublished(DateTimeImmutable $now): void` — one-shot, throws `\DomainException` if already published.
  - `Event::isPublished(): bool`
  - `Event::getPublishedAt(): ?DateTimeImmutable`
  - `Event::enableNotifications(): void` / `disableNotifications(): void`
  - `Event::areNotificationsEnabled(): bool`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Entity/EventNotificationStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class EventNotificationStateTest extends TestCase
{
    public function testNewEventIsNotPublishedAndNotificationsDisabled(): void
    {
        $event = $this->makeEvent();

        self::assertFalse($event->isPublished());
        self::assertNull($event->getPublishedAt());
        self::assertFalse($event->areNotificationsEnabled());
    }

    public function testMarkPublishedSetsTimestamp(): void
    {
        $event = $this->makeEvent();
        $now = new DateTimeImmutable('2026-06-21 12:00:00', new DateTimeZone('UTC'));

        $event->markPublished($now);

        self::assertTrue($event->isPublished());
        self::assertEquals($now, $event->getPublishedAt());
    }

    public function testMarkPublishedIsOneShot(): void
    {
        $event = $this->makeEvent();
        $now = new DateTimeImmutable('2026-06-21 12:00:00', new DateTimeZone('UTC'));
        $event->markPublished($now);

        $this->expectException(DomainException::class);
        $event->markPublished($now);
    }

    public function testNotificationsToggle(): void
    {
        $event = $this->makeEvent();

        $event->enableNotifications();
        self::assertTrue($event->areNotificationsEnabled());

        $event->disableNotifications();
        self::assertFalse($event->areNotificationsEnabled());
    }

    private function makeEvent(): Event
    {
        return new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: new User('owner@example.com', 'Owner'),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventNotificationStateTest.php`
Expected: FAIL — `markPublished` / `areNotificationsEnabled` not defined.

- [ ] **Step 3: Add the fields and methods to `Event`**

In `src/Entity/Event.php`, add two mapped properties (place them with the other `#[ORM\Column]` properties; `DateTimeImmutable` is already imported). Use `nullable: false` with a default for the bool:

```php
#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?DateTimeImmutable $publishedAt = null;

#[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
private bool $notificationsEnabled = false;
```

Add the methods (near the other domain methods):

```php
public function markPublished(DateTimeImmutable $now): void
{
    if ($this->publishedAt instanceof DateTimeImmutable) {
        throw new \DomainException('Event is already published.');
    }

    $this->publishedAt = $now;
}

public function isPublished(): bool
{
    return $this->publishedAt instanceof DateTimeImmutable;
}

public function getPublishedAt(): ?DateTimeImmutable
{
    return $this->publishedAt;
}

public function enableNotifications(): void
{
    $this->notificationsEnabled = true;
}

public function disableNotifications(): void
{
    $this->notificationsEnabled = false;
}

public function areNotificationsEnabled(): bool
{
    return $this->notificationsEnabled;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventNotificationStateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Entity/Event.php tests/Unit/Entity/EventNotificationStateTest.php
git commit -m "77 - add Event publishedAt one-shot and notifications-enabled flag"
```

---

### Task 3: `EventNotificationStatus` enum

**Files:**
- Create: `src/Entity/EventNotificationStatus.php`

**Interfaces:**
- Produces: `enum EventNotificationStatus: string { case Pending = 'pending'; case Confirmed = 'confirmed'; case Unsubscribed = 'unsubscribed'; }`

- [ ] **Step 1: Create the enum**

`src/Entity/EventNotificationStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

enum EventNotificationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
}
```

- [ ] **Step 2: Sanity check it loads**

Run: `php -r "require 'vendor/autoload.php'; var_dump(App\Entity\EventNotificationStatus::Pending->value);"`
Expected: `string(7) "pending"`.

- [ ] **Step 3: Commit**

```bash
git add src/Entity/EventNotificationStatus.php
git commit -m "77 - add EventNotificationStatus enum"
```

---

### Task 4: `EventNotificationSubscription` entity + state machine

**Files:**
- Create: `src/Entity/EventNotificationSubscription.php`
- Test: `tests/Unit/Entity/EventNotificationSubscriptionTest.php`

**Interfaces:**
- Consumes: `Event`, `EventNotificationStatus`.
- Produces (`EventNotificationSubscription`):
  - `__construct(Event $event, string $email, DateTimeImmutable $now, int $ttlDays = 7)`
  - `confirm(DateTimeImmutable $now): void` — only from `Pending` & not expired; nulls confirmation token + expiry.
  - `unsubscribe(DateTimeImmutable $now): void` — from `Pending`/`Confirmed`.
  - `restartPending(DateTimeImmutable $now, int $ttlDays = 7): void` — reset to `Pending`, fresh token + expiry.
  - `markNotified(DateTimeImmutable $now): void` — only from `Confirmed`.
  - `isConfirmationExpired(DateTimeImmutable $now): bool`
  - Getters: `getId(): ?int`, `getEvent(): Event`, `getEmail(): string`, `getStatus(): EventNotificationStatus`, `getConfirmationToken(): ?string`, `getUnsubscribeToken(): string`, `getNotifiedAt(): ?DateTimeImmutable`.
  - Const `DEFAULT_TTL_DAYS = 7`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Entity/EventNotificationSubscriptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class EventNotificationSubscriptionTest extends TestCase
{
    private const string TZ = 'UTC';

    public function testConstructionLowercasesEmailAndStartsPending(): void
    {
        $sub = $this->make('Visitor@Example.COM', $this->at('2026-06-21 10:00:00'));

        self::assertSame('visitor@example.com', $sub->getEmail());
        self::assertSame(EventNotificationStatus::Pending, $sub->getStatus());
        self::assertNotNull($sub->getConfirmationToken());
        self::assertNotSame('', $sub->getUnsubscribeToken());
        self::assertNull($sub->getNotifiedAt());
    }

    public function testTokensAreUrlSafeAndDistinct(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', (string) $sub->getConfirmationToken());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $sub->getUnsubscribeToken());
        self::assertNotSame($sub->getConfirmationToken(), $sub->getUnsubscribeToken());
        self::assertGreaterThanOrEqual(43, strlen((string) $sub->getConfirmationToken()));
    }

    public function testConfirmFromPendingClearsToken(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        $sub->confirm($this->at('2026-06-22 10:00:00'));

        self::assertSame(EventNotificationStatus::Confirmed, $sub->getStatus());
        self::assertNull($sub->getConfirmationToken());
        self::assertFalse($sub->isConfirmationExpired($this->at('2026-07-01 10:00:00')));
    }

    public function testConfirmRejectedWhenExpired(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        self::assertTrue($sub->isConfirmationExpired($this->at('2026-06-28 10:00:01')));

        $this->expectException(DomainException::class);
        $sub->confirm($this->at('2026-06-28 10:00:01'));
    }

    public function testExpiryBoundaryIsSevenDays(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        self::assertFalse($sub->isConfirmationExpired($this->at('2026-06-28 10:00:00')));
        self::assertTrue($sub->isConfirmationExpired($this->at('2026-06-28 10:00:01')));
    }

    public function testConfirmRejectedWhenNotPending(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));
        $sub->confirm($this->at('2026-06-22 10:00:00'));

        $this->expectException(DomainException::class);
        $sub->confirm($this->at('2026-06-22 11:00:00'));
    }

    public function testUnsubscribeFromConfirmed(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));
        $sub->confirm($this->at('2026-06-22 10:00:00'));

        $sub->unsubscribe($this->at('2026-06-23 10:00:00'));

        self::assertSame(EventNotificationStatus::Unsubscribed, $sub->getStatus());
    }

    public function testRestartPendingFromUnsubscribedIssuesFreshToken(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));
        $firstToken = $sub->getConfirmationToken();
        $sub->confirm($this->at('2026-06-22 10:00:00'));
        $sub->unsubscribe($this->at('2026-06-23 10:00:00'));

        $sub->restartPending($this->at('2026-06-24 10:00:00'));

        self::assertSame(EventNotificationStatus::Pending, $sub->getStatus());
        self::assertNotNull($sub->getConfirmationToken());
        self::assertNotSame($firstToken, $sub->getConfirmationToken());
        self::assertFalse($sub->isConfirmationExpired($this->at('2026-06-30 10:00:00')));
    }

    public function testMarkNotifiedOnlyFromConfirmed(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        $this->expectException(DomainException::class);
        $sub->markNotified($this->at('2026-06-22 10:00:00'));
    }

    private function make(string $email, DateTimeImmutable $now): EventNotificationSubscription
    {
        $event = new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: $this->at('2026-01-01 10:00:00'),
            endsAt: $this->at('2026-01-01 18:00:00'),
            owner: new User('owner@example.com', 'Owner'),
        );

        return new EventNotificationSubscription($event, $email, $now);
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone(self::TZ));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventNotificationSubscriptionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the entity**

`src/Entity/EventNotificationSubscription.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventNotificationSubscriptionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity(repositoryClass: EventNotificationSubscriptionRepository::class)]
#[ORM\Table(name: 'event_notification_subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_event_notif_event_email', columns: ['event_id', 'email'])]
#[ORM\Index(name: 'idx_event_notif_event_status', columns: ['event_id', 'status'])]
class EventNotificationSubscription
{
    public const int DEFAULT_TTL_DAYS = 7;

    private const int TOKEN_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $confirmationToken;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $unsubscribeToken;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: EventNotificationStatus::class)]
    private EventNotificationStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmationExpiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $unsubscribedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $notifiedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Event $event,
        string $email,
        DateTimeImmutable $now,
        int $ttlDays = self::DEFAULT_TTL_DAYS,
    ) {
        $this->email = strtolower($email);
        $this->status = EventNotificationStatus::Pending;
        $this->createdAt = $now;
        $this->confirmationToken = self::generateToken();
        $this->unsubscribeToken = self::generateToken();
        $this->confirmationExpiresAt = $now->modify(sprintf('+%d days', $ttlDays));
    }

    public function confirm(DateTimeImmutable $now): void
    {
        if ($this->status !== EventNotificationStatus::Pending) {
            throw new DomainException('Only pending subscriptions can be confirmed.');
        }
        if ($this->isConfirmationExpired($now)) {
            throw new DomainException('Confirmation window has expired.');
        }

        $this->status = EventNotificationStatus::Confirmed;
        $this->confirmedAt = $now;
        $this->confirmationToken = null;
        $this->confirmationExpiresAt = null;
    }

    public function unsubscribe(DateTimeImmutable $now): void
    {
        if ($this->status === EventNotificationStatus::Unsubscribed) {
            throw new DomainException('Subscription is already unsubscribed.');
        }

        $this->status = EventNotificationStatus::Unsubscribed;
        $this->unsubscribedAt = $now;
        $this->confirmationToken = null;
        $this->confirmationExpiresAt = null;
    }

    public function restartPending(DateTimeImmutable $now, int $ttlDays = self::DEFAULT_TTL_DAYS): void
    {
        $this->status = EventNotificationStatus::Pending;
        $this->confirmationToken = self::generateToken();
        $this->confirmationExpiresAt = $now->modify(sprintf('+%d days', $ttlDays));
        $this->confirmedAt = null;
        $this->unsubscribedAt = null;
        $this->notifiedAt = null;
    }

    public function markNotified(DateTimeImmutable $now): void
    {
        if ($this->status !== EventNotificationStatus::Confirmed) {
            throw new DomainException('Only confirmed subscriptions can be marked notified.');
        }

        $this->notifiedAt = $now;
    }

    public function isConfirmationExpired(DateTimeImmutable $now): bool
    {
        if (!$this->confirmationExpiresAt instanceof DateTimeImmutable) {
            return false;
        }

        return $now > $this->confirmationExpiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getStatus(): EventNotificationStatus
    {
        return $this->status;
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function getUnsubscribeToken(): string
    {
        return $this->unsubscribeToken;
    }

    public function getNotifiedAt(): ?DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    private static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventNotificationSubscriptionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Entity/EventNotificationSubscription.php tests/Unit/Entity/EventNotificationSubscriptionTest.php
git commit -m "77 - add EventNotificationSubscription entity with opt-in state machine"
```

---

### Task 5: Repository + migration

**Files:**
- Create: `src/Repository/EventNotificationSubscriptionRepository.php`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Test: `tests/Integration/Repository/EventNotificationSubscriptionRepositoryTest.php`

**Interfaces:**
- Produces (`EventNotificationSubscriptionRepository extends ServiceEntityRepository`):
  - `findOneByEventAndEmail(Event $event, string $email): ?EventNotificationSubscription`
  - `findByConfirmationToken(string $token): ?EventNotificationSubscription`
  - `findByUnsubscribeToken(string $token): ?EventNotificationSubscription`
  - `findConfirmedByEvent(Event $event): array<int, EventNotificationSubscription>` (ordered by id ASC)
  - `countByEvent(Event $event): int`

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/Repository/EventNotificationSubscriptionRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Repository\EventNotificationSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventNotificationSubscriptionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private EventNotificationSubscriptionRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = $container->get(EventNotificationSubscriptionRepository::class);
        $this->em = $em;
        $this->repo = $repo;
    }

    public function testFindOneByEventAndEmailIsCaseInsensitive(): void
    {
        $event = $this->persistEvent('case-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'Person@Example.com', $now);
        $this->em->persist($sub);
        $this->em->flush();

        $found = $this->repo->findOneByEventAndEmail($event, 'PERSON@EXAMPLE.COM');

        self::assertNotNull($found);
        self::assertSame($sub->getId(), $found->getId());
    }

    public function testCountAndConfirmedQuery(): void
    {
        $event = $this->persistEvent('count-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $confirmed = new EventNotificationSubscription($event, 'a@example.com', $now);
        $confirmed->confirm($now);
        $pending = new EventNotificationSubscription($event, 'b@example.com', $now);
        $this->em->persist($confirmed);
        $this->em->persist($pending);
        $this->em->flush();

        self::assertSame(2, $this->repo->countByEvent($event));
        $confirmedList = $this->repo->findConfirmedByEvent($event);
        self::assertCount(1, $confirmedList);
        self::assertSame('a@example.com', $confirmedList[0]->getEmail());
    }

    public function testUniqueConstraintPerEventEmail(): void
    {
        $event = $this->persistEvent('unique-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->em->persist(new EventNotificationSubscription($event, 'dup@example.com', $now));
        $this->em->flush();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->persist(new EventNotificationSubscription($event, 'DUP@example.com', $now));
        $this->em->flush();
    }

    private function persistEvent(string $slug): Event
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $this->em->persist($owner);
        $event = new Event(
            slug: $slug,
            name: 'Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Repository/EventNotificationSubscriptionRepositoryTest.php`
Expected: FAIL — repository class not found.

- [ ] **Step 3: Create the repository**

`src/Repository/EventNotificationSubscriptionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventNotificationSubscription>
 */
final class EventNotificationSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventNotificationSubscription::class);
    }

    public function findOneByEventAndEmail(Event $event, string $email): ?EventNotificationSubscription
    {
        return $this->findOneBy(['event' => $event, 'email' => strtolower($email)]);
    }

    public function findByConfirmationToken(string $token): ?EventNotificationSubscription
    {
        return $this->findOneBy(['confirmationToken' => $token]);
    }

    public function findByUnsubscribeToken(string $token): ?EventNotificationSubscription
    {
        return $this->findOneBy(['unsubscribeToken' => $token]);
    }

    /**
     * @return array<int, EventNotificationSubscription>
     */
    public function findConfirmedByEvent(Event $event): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.event = :event')
            ->andWhere('s.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', EventNotificationStatus::Confirmed)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByEvent(Event $event): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
```

- [ ] **Step 4: Generate the migration**

Run:
```bash
bin/console doctrine:migrations:diff
```
Expected: a new `migrations/VersionYYYYMMDDHHMMSS.php` creating `event_notification_subscriptions` (with the unique constraint + index) and adding `published_at` + `notifications_enabled` to `events`. Edit only `getDescription()` to: `"#77 visitor email notification subscriptions + event publish state"`. Do not hand-edit the SQL.

- [ ] **Step 5: Apply the migration to dev + test DBs and validate schema**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate --env=test
```
Expected: schema validate reports mapping and database in sync.

- [ ] **Step 6: Run the integration test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Repository/EventNotificationSubscriptionRepositoryTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Repository/EventNotificationSubscriptionRepository.php migrations/ tests/Integration/Repository/EventNotificationSubscriptionRepositoryTest.php
git commit -m "77 - add subscription repository and migration"
```

---

### Task 6: Live-notification messages, handlers, mail templates, routing

**Files:**
- Create: `src/Message/SendEventLiveNotifications.php`
- Create: `src/Message/SendEventLiveEmail.php`
- Create: `src/MessageHandler/SendEventLiveNotificationsHandler.php`
- Create: `src/MessageHandler/SendEventLiveEmailHandler.php`
- Create: `templates/email/event-notification/live.html.twig`
- Create: `templates/email/event-notification/live.txt.twig`
- Modify: `config/packages/messenger.yaml` (routing)
- Modify: `.env` (add `EVENT_LIVE_NOTIFICATION_RATE_PER_MIN`)
- Modify: `config/services.yaml` (bind the rate env to the fan-out handler if not autowired via `%env%`)
- Test: `tests/Functional/Notification/EventLiveFanOutTest.php`

**Interfaces:**
- Consumes: `EventRepository::find()`, `EventNotificationSubscriptionRepository::findConfirmedByEvent()`, `OrganizerMailerResolver::forEvent()`, `MessageBusInterface`, `UrlGeneratorInterface`.
- Produces:
  - `SendEventLiveNotifications` with `public int $eventId`.
  - `SendEventLiveEmail` with `public int $subscriptionId`.
  - Both handlers (`#[AsMessageHandler]`).

- [ ] **Step 1: Write the failing fan-out test**

`tests/Functional/Notification/EventLiveFanOutTest.php` — asserts N confirmed subscribers produce N `SendEventLiveEmail` messages with monotonically increasing delays. Uses the in-memory transport (`messenger.transport.async` is `in-memory` under `when@test` — verify in `config/packages/messenger.yaml`; if the test env still uses Doctrine, route to `messenger.transport.async` and read it via `getMessengerTransport('async')`).

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Message\SendEventLiveEmail;
use App\Message\SendEventLiveNotifications;
use App\MessageHandler\SendEventLiveNotificationsHandler;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EventLiveFanOutTest extends KernelTestCase
{
    public function testFanOutDispatchesOneDelayedMessagePerConfirmedSubscriber(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('fanout-owner@example.com', 'Owner');
        $em->persist($owner);
        $event = new Event(
            slug: 'fanout-event',
            name: 'Fan Out',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->markPublished(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $sub = new EventNotificationSubscription($event, sprintf('sub%d@example.com', $i), $now);
            $sub->confirm($now);
            $em->persist($sub);
        }
        $em->flush();

        /** @var SendEventLiveNotificationsHandler $handler */
        $handler = $container->get(SendEventLiveNotificationsHandler::class);
        $handler(new SendEventLiveNotifications($event->getId()));

        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        $sent = $transport->getSent();

        self::assertCount(3, $sent);
        $previousDelay = -1;
        foreach ($sent as $envelope) {
            self::assertInstanceOf(SendEventLiveEmail::class, $envelope->getMessage());
            /** @var DelayStamp|null $stamp */
            $stamp = $envelope->last(DelayStamp::class);
            self::assertNotNull($stamp);
            self::assertGreaterThan($previousDelay, $stamp->getDelay());
            $previousDelay = $stamp->getDelay();
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Notification/EventLiveFanOutTest.php`
Expected: FAIL — message/handler classes not found.

- [ ] **Step 3: Create the message classes**

`src/Message/SendEventLiveNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendEventLiveNotifications
{
    public function __construct(public int $eventId)
    {
    }
}
```

`src/Message/SendEventLiveEmail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendEventLiveEmail
{
    public function __construct(public int $subscriptionId)
    {
    }
}
```

- [ ] **Step 4: Create the fan-out handler**

`src/MessageHandler/SendEventLiveNotificationsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendEventLiveEmail;
use App\Message\SendEventLiveNotifications;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\EventRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SendEventLiveNotificationsHandler
{
    private const int MS_PER_MINUTE = 60_000;

    public function __construct(
        private EventRepository $events,
        private EventNotificationSubscriptionRepository $subscriptions,
        private MessageBusInterface $bus,
        #[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')]
        private int $ratePerMinute,
    ) {
    }

    public function __invoke(SendEventLiveNotifications $message): void
    {
        $event = $this->events->find($message->eventId);
        if ($event === null || !$event->isPublished()) {
            return;
        }

        $rate = max(1, $this->ratePerMinute);
        $intervalMs = intdiv(self::MS_PER_MINUTE, $rate);

        $index = 0;
        foreach ($this->subscriptions->findConfirmedByEvent($event) as $subscription) {
            $this->bus->dispatch(
                new SendEventLiveEmail((int) $subscription->getId()),
                [new DelayStamp($index * $intervalMs)],
            );
            ++$index;
        }
    }
}
```

- [ ] **Step 5: Create the per-recipient handler**

`src/MessageHandler/SendEventLiveEmailHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\EventNotificationStatus;
use App\Message\SendEventLiveEmail;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Service\Mail\OrganizerMailerResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendEventLiveEmailHandler
{
    public function __construct(
        private EventNotificationSubscriptionRepository $subscriptions,
        private OrganizerMailerResolver $mailerResolver,
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SendEventLiveEmail $message): void
    {
        $subscription = $this->subscriptions->find($message->subscriptionId);
        if ($subscription === null
            || $subscription->getStatus() !== EventNotificationStatus::Confirmed
            || $subscription->getNotifiedAt() !== null
        ) {
            return;
        }

        $event = $subscription->getEvent();

        // Strict resolver: throws if the organizer transport vanished. The thrown
        // exception hard-fails the message into Messenger's retry/dead-letter path —
        // never a platform-mail fallback.
        $mailer = $this->mailerResolver->forEvent($event);

        $eventUrl = $this->urlGenerator->generate(
            'public_event_landing',
            ['slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $unsubscribeUrl = $this->urlGenerator->generate(
            'public_event_notify_unsubscribe',
            ['slug' => $event->getSlug(), 'token' => $subscription->getUnsubscribeToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->to($subscription->getEmail())
            ->subject(sprintf('Photos from %s are live', $event->getName()))
            ->htmlTemplate('email/event-notification/live.html.twig')
            ->textTemplate('email/event-notification/live.txt.twig')
            ->context([
                'eventName' => $event->getName(),
                'eventUrl' => $eventUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);

        $mailer->send($email);

        $subscription->markNotified(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->em->flush();
    }
}
```

- [ ] **Step 6: Create the live mail templates**

`templates/email/event-notification/live.html.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="referrer" content="no-referrer">
    <title>Photos from {{ eventName }} are live</title>
</head>
<body style="font-family: sans-serif;">
    <p>Hi,</p>
    <p>The photos from <strong>{{ eventName }}</strong> are now available.</p>
    <p><a href="{{ eventUrl }}">View the photos</a></p>
    <p style="font-size: 12px; color: #666;">
        Don't want these emails? <a href="{{ unsubscribeUrl }}">Unsubscribe</a>.
    </p>
</body>
</html>
```

`templates/email/event-notification/live.txt.twig`:

```twig
Hi,

The photos from {{ eventName }} are now available.

View the photos: {{ eventUrl }}

Don't want these emails? Unsubscribe: {{ unsubscribeUrl }}
```

- [ ] **Step 7: Add Messenger routing and the env var**

In `config/packages/messenger.yaml`, under `framework.messenger.routing`, add:

```yaml
            'App\Message\SendEventLiveNotifications': async
            'App\Message\SendEventLiveEmail': async
```

In `.env`, under the mail section, add:

```dotenv
###> app/notifications ###
# Steady send rate for "photos are live" emails (emails/minute), throttled to
# absorb the inbound traffic spike when subscribers click the link.
EVENT_LIVE_NOTIFICATION_RATE_PER_MIN=30
###< app/notifications ###
```

- [ ] **Step 8: Run the fan-out test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Notification/EventLiveFanOutTest.php`
Expected: PASS. If `messenger.transport.async` is not `InMemoryTransport` in test, adjust `config/packages/messenger.yaml` `when@test` to use `in-memory://` for `async` (this is the standard Symfony test setup) and re-run.

- [ ] **Step 9: Commit**

```bash
git add src/Message/ src/MessageHandler/SendEventLive* templates/email/event-notification/live.* config/packages/messenger.yaml .env tests/Functional/Notification/EventLiveFanOutTest.php
git commit -m "77 - add throttled live-notification fan-out messages and handlers"
```

---

### Task 7: Public `EventNotificationController` — subscribe / confirm / unsubscribe

**Files:**
- Create: `src/Controller/Public/EventNotificationController.php`
- Create: `templates/email/event-notification/confirm.html.twig`
- Create: `templates/email/event-notification/confirm.txt.twig`
- Create: `templates/public/event_notification/confirmed.html.twig`
- Create: `templates/public/event_notification/invalid.html.twig`
- Create: `templates/public/event_notification/unsubscribed.html.twig`
- Create: `templates/public/event_notification/check_inbox.html.twig`
- Modify: `config/packages/rate_limiter.yaml`
- Test: `tests/Functional/Public/EventNotificationControllerTest.php`

**Interfaces:**
- Consumes: `EventRepository::findOneBySlug()`, `EventNotificationSubscriptionRepository`, `OrganizerMailerResolver` (`isCustomActive`, `forEvent`), the two rate-limiter factories, `EntityManagerInterface`, `UrlGeneratorInterface`, `LoggerInterface`.
- Produces routes:
  - `public_event_notify_subscribe` — `POST /e/{slug}/notify`
  - `public_event_notify_confirm` — `GET /e/{slug}/notify/confirm/{token}`
  - `public_event_notify_unsubscribe` — `GET /e/{slug}/notify/unsubscribe/{token}`

- [ ] **Step 1: Add the rate limiters**

In `config/packages/rate_limiter.yaml`, under `framework.rate_limiter`, add:

```yaml
        visitor_email_signup:
            policy: 'sliding_window'
            limit: 5
            interval: '1 hour'
            cache_pool: 'rate_limiter.cache_pool'
        confirm_email_resend:
            policy: 'sliding_window'
            limit: 1
            interval: '10 minutes'
            cache_pool: 'rate_limiter.cache_pool'
```

- [ ] **Step 2: Write the failing functional test**

`tests/Functional/Public/EventNotificationControllerTest.php`. Covers: signup → confirmation email sent; honeypot drops silently with no email; confirmed re-submit sends no email; confirm link confirms; expired/invalid token shows invalid page; unsubscribe works.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Service\Mail\DsnVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventNotificationControllerTest extends WebTestCase
{
    public function testSignupSendsConfirmationEmail(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'notify-event');

        $client->request('POST', '/e/notify-event/notify', [
            'email' => 'visitor@example.com',
            'website' => '',
        ]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        $sub = $repo->findOneByEventAndEmail($event, 'visitor@example.com');
        self::assertNotNull($sub);
        self::assertSame(EventNotificationStatus::Pending, $sub->getStatus());
    }

    public function testHoneypotDropsSilently(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->makeEventWithMail($em, 'honeypot-event');

        $client->request('POST', '/e/honeypot-event/notify', [
            'email' => 'bot@example.com',
            'website' => 'http://spam.example',
        ]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);

        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        self::assertNull($repo->findOneByEventAndEmail(
            $em->getRepository(Event::class)->findOneBy(['slug' => 'honeypot-event']),
            'bot@example.com',
        ));
    }

    public function testConfirmedResubmitSendsNoEmail(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'resub-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'already@example.com', $now);
        $sub->confirm($now);
        $em->persist($sub);
        $em->flush();

        $client->request('POST', '/e/resub-event/notify', [
            'email' => 'already@example.com',
            'website' => '',
        ]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testConfirmTokenConfirms(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'confirm-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'c@example.com', $now);
        $token = (string) $sub->getConfirmationToken();
        $em->persist($sub);
        $em->flush();

        $client->request('GET', '/e/confirm-event/notify/confirm/' . $token);
        self::assertResponseIsSuccessful();

        $em->clear();
        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        $reloaded = $repo->findOneByEventAndEmail(
            $em->getRepository(Event::class)->findOneBy(['slug' => 'confirm-event']),
            'c@example.com',
        );
        self::assertSame(EventNotificationStatus::Confirmed, $reloaded->getStatus());
    }

    public function testInvalidConfirmTokenShowsInvalidPage(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->makeEventWithMail($em, 'badtoken-event');

        $client->request('GET', '/e/badtoken-event/notify/confirm/does-not-exist');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'invalid');
    }

    private function makeEventWithMail(EntityManagerInterface $em, string $slug): Event
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');
        $em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            $slug . '-owner@example.com',
            null,
        );
        $config->markVerified();
        $em->persist($config);

        $event = new Event(
            slug: $slug,
            name: 'Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();
        $em->persist($event);
        $em->flush();

        return $event;
    }
}
```

Note: `assertEmailCount` works because the per-DSN organizer transport routes through `InMemoryTransportFactory` under `when@test` (per CLAUDE.md). If the confirmation send path uses `RenderingMailer`/`PinnedTransportFactory`, confirm the test harness captures it the same way `AccountMailFlowTest` does; otherwise assert via the same `CapturedMail` helper that test uses.

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/EventNotificationControllerTest.php`
Expected: FAIL — routes 404 (controller not defined).

- [ ] **Step 4: Create the controller**

`src/Controller/Public/EventNotificationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\EventRepository;
use App\Service\Mail\OrganizerMailerResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class EventNotificationController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventNotificationSubscriptionRepository $subscriptions,
        private readonly OrganizerMailerResolver $mailerResolver,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.visitor_email_signup')]
        private readonly RateLimiterFactoryInterface $signupLimiter,
        #[Autowire(service: 'limiter.confirm_email_resend')]
        private readonly RateLimiterFactoryInterface $confirmResendLimiter,
    ) {
    }

    #[Route('/e/{slug}/notify', name: 'public_event_notify_subscribe', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function subscribe(string $slug, Request $request): Response
    {
        $event = $this->resolve($slug);

        // Honeypot: a filled hidden field means a bot — accept and drop silently.
        if ((string) $request->request->get('website', '') !== '') {
            return $this->checkInbox($event);
        }

        if (!$this->signupLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        // Feature must be enabled, owner mail active, and event not yet published.
        if (!$event->areNotificationsEnabled()
            || $event->isPublished()
            || !$this->mailerResolver->isCustomActive($event->getOwner())
        ) {
            return $this->checkInbox($event);
        }

        $email = strtolower(trim((string) $request->request->get('email', '')));
        if ($email === '' || count($this->validator->validate($email, [new Email()])) > 0) {
            return $this->checkInbox($event);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $existing = $this->subscriptions->findOneByEventAndEmail($event, $email);

        $shouldSend = false;
        if ($existing === null) {
            $subscription = new EventNotificationSubscription($event, $email, $now);
            $this->em->persist($subscription);
            $shouldSend = true;
        } elseif ($existing->getStatus() === EventNotificationStatus::Confirmed) {
            // Already confirmed: no state change, no email (closes the mail-bomb vector).
            $subscription = $existing;
        } else {
            // pending (possibly expired) or unsubscribed: reset and re-send.
            $existing->restartPending($now);
            $subscription = $existing;
            $shouldSend = true;
        }

        $this->em->flush();

        if ($shouldSend && $this->confirmResendLimiter->create($email)->consume()->isAccepted()) {
            $this->sendConfirmation($event, $subscription);
        }

        return $this->checkInbox($event);
    }

    #[Route('/e/{slug}/notify/confirm/{token}', name: 'public_event_notify_confirm', requirements: ['slug' => '[a-z0-9-]+', 'token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function confirm(string $slug, string $token): Response
    {
        $this->resolve($slug);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $subscription = $this->subscriptions->findByConfirmationToken($token);

        if ($subscription === null
            || $subscription->getStatus() !== EventNotificationStatus::Pending
            || $subscription->isConfirmationExpired($now)
        ) {
            return $this->minimalPage('public/event_notification/invalid.html.twig');
        }

        $subscription->confirm($now);
        $this->em->flush();

        return $this->minimalPage('public/event_notification/confirmed.html.twig');
    }

    #[Route('/e/{slug}/notify/unsubscribe/{token}', name: 'public_event_notify_unsubscribe', requirements: ['slug' => '[a-z0-9-]+', 'token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function unsubscribe(string $slug, string $token): Response
    {
        $this->resolve($slug);
        $subscription = $this->subscriptions->findByUnsubscribeToken($token);

        if ($subscription !== null && $subscription->getStatus() !== EventNotificationStatus::Unsubscribed) {
            $subscription->unsubscribe(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $this->em->flush();
        }

        return $this->minimalPage('public/event_notification/unsubscribed.html.twig');
    }

    private function sendConfirmation(Event $event, EventNotificationSubscription $subscription): void
    {
        try {
            $mailer = $this->mailerResolver->forEvent($event);
        } catch (\Throwable $throwable) {
            $this->logger->error('Could not resolve organizer mailer for confirmation.', [
                'event_id' => $event->getId(),
                'exception' => $throwable->getMessage(),
            ]);

            return;
        }

        $confirmUrl = $this->generateUrl('public_event_notify_confirm', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getConfirmationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $unsubscribeUrl = $this->generateUrl('public_event_notify_unsubscribe', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getUnsubscribeToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->to($subscription->getEmail())
            ->subject(sprintf('Confirm notifications for %s', $event->getName()))
            ->htmlTemplate('email/event-notification/confirm.html.twig')
            ->textTemplate('email/event-notification/confirm.txt.twig')
            ->context([
                'eventName' => $event->getName(),
                'confirmUrl' => $confirmUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);

        try {
            $mailer->send($email);
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed sending confirmation email.', [
                'event_id' => $event->getId(),
                'exception' => $throwable->getMessage(),
            ]);
        }
    }

    private function checkInbox(Event $event): Response
    {
        return $this->minimalPage('public/event_notification/check_inbox.html.twig', ['event' => $event]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function minimalPage(string $template, array $context = []): Response
    {
        $response = $this->render($template, $context);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function resolve(string $slug): Event
    {
        $event = $this->events->findOneBySlug($slug);
        if (!$event instanceof Event) {
            throw new NotFoundHttpException(sprintf('No event for slug "%s".', $slug));
        }

        return $event;
    }
}
```

- [ ] **Step 5: Create the confirmation email templates**

`templates/email/event-notification/confirm.html.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="referrer" content="no-referrer">
    <title>Confirm notifications for {{ eventName }}</title>
</head>
<body style="font-family: sans-serif;">
    <p>Hi,</p>
    <p>You asked to be notified when photos from <strong>{{ eventName }}</strong> are live.</p>
    <p>Confirm within 7 days:</p>
    <p><a href="{{ confirmUrl }}">Confirm my email</a></p>
    <p style="font-size: 12px; color: #666;">
        Didn't request this? Ignore this email, or <a href="{{ unsubscribeUrl }}">unsubscribe</a>.
    </p>
</body>
</html>
```

`templates/email/event-notification/confirm.txt.twig`:

```twig
Hi,

You asked to be notified when photos from {{ eventName }} are live.

Confirm within 7 days: {{ confirmUrl }}

Didn't request this? Ignore this email, or unsubscribe: {{ unsubscribeUrl }}
```

- [ ] **Step 6: Create the four minimal public pages**

Each extends the existing public base if there is one; otherwise self-contained minimal HTML. Check `templates/public/` for a base (e.g. `templates/public/base.html.twig` or the root `templates/base.html.twig`). Keep them text-light and free of third-party embeds (token-leak risk).

`templates/public/event_notification/check_inbox.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block body %}
    <main class="mx-auto max-w-md p-6 text-center">
        <h1 class="text-xl font-semibold">Almost there</h1>
        <p class="mt-2">If your email isn't already signed up, check your inbox to confirm your subscription.</p>
    </main>
{% endblock %}
```

`templates/public/event_notification/confirmed.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block body %}
    <main class="mx-auto max-w-md p-6 text-center">
        <h1 class="text-xl font-semibold">You're confirmed</h1>
        <p class="mt-2">We'll email you once the photos are live.</p>
    </main>
{% endblock %}
```

`templates/public/event_notification/invalid.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block body %}
    <main class="mx-auto max-w-md p-6 text-center">
        <h1 class="text-xl font-semibold">This link is invalid or has expired</h1>
        <p class="mt-2">Please sign up again from the event page.</p>
    </main>
{% endblock %}
```

`templates/public/event_notification/unsubscribed.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block body %}
    <main class="mx-auto max-w-md p-6 text-center">
        <h1 class="text-xl font-semibold">You've been unsubscribed</h1>
        <p class="mt-2">You won't receive further emails for this event.</p>
    </main>
{% endblock %}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/EventNotificationControllerTest.php`
Expected: PASS. (If `assertEmailCount` doesn't capture the per-DSN send, switch those assertions to the `CapturedMail` helper used by `AccountMailFlowTest` — read that test first.)

- [ ] **Step 8: Verify the routes stay sessionless**

Run: `grep -rn "addFlash\|getSession\|csrf_token" src/Controller/Public/EventNotificationController.php templates/public/event_notification/`
Expected: no matches.

- [ ] **Step 9: Commit**

```bash
git add src/Controller/Public/EventNotificationController.php templates/email/event-notification/confirm.* templates/public/event_notification/ config/packages/rate_limiter.yaml tests/Functional/Public/EventNotificationControllerTest.php
git commit -m "77 - add public signup/confirm/unsubscribe notification flow"
```

---

### Task 8: Admin publish + enable-notifications toggle

**Files:**
- Modify: `src/Controller/Admin/EventController.php`
- Modify: the admin event template (find via `grep -rln "events/{id}" templates/admin/ || grep -rln "admin_event" templates/`)
- Test: `tests/Functional/Admin/EventPublishTest.php`

**Interfaces:**
- Consumes: `EventVoter::EDIT`, `OrganizerMailerResolver::isCustomActive()`, `PhotoRepository` (count of `Ready`), `EventNotificationSubscriptionRepository::countByEvent()`, `MessageBusInterface`, `EntityManagerInterface`.
- Produces routes:
  - `admin_event_publish` — `POST /admin/events/{id}/publish`
  - `admin_event_toggle_notifications` — `POST /admin/events/{id}/notifications`

- [ ] **Step 1: Write the failing functional test**

`tests/Functional/Admin/EventPublishTest.php`. Covers: publish requires a Ready photo; publish blocked without active mail; CSRF rejection; happy publish dispatches the fan-out and sets `publishedAt`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendEventLiveNotifications;
use App\Service\Mail\DsnVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EventPublishTest extends WebTestCase
{
    public function testPublishRejectedWithoutReadyPhoto(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$owner, $event] = $this->makeOrganizerAndEvent($em, 'pub-noready', withMail: true);
        $client->loginUser($owner);

        $client->request('POST', '/admin/events/' . $event->getId() . '/publish', [
            '_token' => $this->csrf($client, 'publish' . $event->getId()),
        ]);

        self::assertResponseStatusCodeSame(422); // or redirect with error — match controller choice
        $em->clear();
        self::assertFalse($em->getRepository(Event::class)->find($event->getId())->isPublished());
    }

    public function testHappyPublishDispatchesFanOut(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$owner, $event] = $this->makeOrganizerAndEvent($em, 'pub-happy', withMail: true);
        $this->addReadyPhoto($em, $event);
        $client->loginUser($owner);

        $client->request('POST', '/admin/events/' . $event->getId() . '/publish', [
            '_token' => $this->csrf($client, 'publish' . $event->getId()),
        ]);

        self::assertResponseRedirects();
        $em->clear();
        self::assertTrue($em->getRepository(Event::class)->find($event->getId())->isPublished());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($e) => $e->getMessage(), $transport->getSent());
        self::assertContainsOnlyInstancesOf(SendEventLiveNotifications::class, $messages);
        self::assertCount(1, $messages);
    }

    public function testPublishRejectedWithInvalidCsrf(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$owner, $event] = $this->makeOrganizerAndEvent($em, 'pub-csrf', withMail: true);
        $this->addReadyPhoto($em, $event);
        $client->loginUser($owner);

        $client->request('POST', '/admin/events/' . $event->getId() . '/publish', ['_token' => 'bogus']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPublishBlockedWithoutActiveMail(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$owner, $event] = $this->makeOrganizerAndEvent($em, 'pub-nomail', withMail: false);
        $this->addReadyPhoto($em, $event);
        $client->loginUser($owner);

        $client->request('POST', '/admin/events/' . $event->getId() . '/publish', [
            '_token' => $this->csrf($client, 'publish' . $event->getId()),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    private function csrf($client, string $id): string
    {
        return $client->getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function addReadyPhoto(EntityManagerInterface $em, Event $event): void
    {
        // Follow the Photo construction + markReady pattern used in existing Photo tests.
        // Read tests/Unit/Entity/PhotoTest.php for the exact constructor signature, then
        // build a Photo, markReady(...), persist, flush.
    }

    /**
     * @return array{0: User, 1: Event}
     */
    private function makeOrganizerAndEvent(EntityManagerInterface $em, string $slug, bool $withMail): array
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');
        $em->persist($owner);

        if ($withMail) {
            /** @var DsnVault $vault */
            $vault = self::getContainer()->get(DsnVault::class);
            $config = new UserMailConfig(
                $owner,
                $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
                $slug . '-owner@example.com',
                null,
            );
            $config->markVerified();
            $em->persist($config);
        }

        $event = new Event(
            slug: $slug,
            name: 'Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();
        $em->persist($event);
        $em->flush();

        return [$owner, $event];
    }
}
```

Note: `addReadyPhoto` and the exact rejection status (422 vs redirect-with-flash) must match the controller. Read `tests/Unit/Entity/PhotoTest.php` for the `Photo` constructor and `markReady` signature before filling it in. Pick one rejection convention in the controller and make the test assert that same convention.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventPublishTest.php`
Expected: FAIL — routes 404.

- [ ] **Step 3: Add the publish + toggle actions to `Admin\EventController`**

Read the existing `src/Controller/Admin/EventController.php` to match its constructor injection style and how it loads the `Event` (route param `{id}` → `EntityValueResolver` or explicit repo lookup) and how it returns errors elsewhere. Then add:

```php
#[Route('/admin/events/{id}/publish', name: 'admin_event_publish', methods: ['POST'])]
public function publish(Event $event, Request $request): Response
{
    $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

    if (!$this->isCsrfTokenValid('publish' . $event->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('Invalid CSRF token.');
    }

    if ($event->isPublished()
        || $this->photos->countReadyByEvent($event) < 1
        || !$this->mailerResolver->isCustomActive($event->getOwner())
    ) {
        // Use the same error convention as the rest of this controller.
        return new Response('Cannot publish this event.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $event->markPublished(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $this->em->flush();

    $this->bus->dispatch(new SendEventLiveNotifications((int) $event->getId()));

    return $this->redirectToRoute('admin_event_show', ['id' => $event->getId()]);
}

#[Route('/admin/events/{id}/notifications', name: 'admin_event_toggle_notifications', methods: ['POST'])]
public function toggleNotifications(Event $event, Request $request): Response
{
    $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

    if (!$this->isCsrfTokenValid('notifications' . $event->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('Invalid CSRF token.');
    }

    if (!$this->mailerResolver->isCustomActive($event->getOwner())) {
        return new Response('Mail transport not configured.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    if ($request->request->getBoolean('enabled')) {
        $event->enableNotifications();
    } else {
        $event->disableNotifications();
    }
    $this->em->flush();

    return $this->redirectToRoute('admin_event_show', ['id' => $event->getId()]);
}
```

Add the needed constructor dependencies (`OrganizerMailerResolver $mailerResolver`, `PhotoRepository $photos`, `MessageBusInterface $bus`, `EntityManagerInterface $em`) following the controller's existing injection pattern. Add a `countReadyByEvent(Event): int` method to `PhotoRepository` if one doesn't already exist (check first; there may already be a status-count helper).

Replace `admin_event_show` with the actual route name the controller uses for the event detail page (verify with `grep -n "name: 'admin_event" src/Controller/Admin/EventController.php`).

- [ ] **Step 4: Add `countReadyByEvent` to `PhotoRepository` if missing**

```php
public function countReadyByEvent(Event $event): int
{
    return (int) $this->createQueryBuilder('p')
        ->select('COUNT(p.id)')
        ->andWhere('p.event = :event')
        ->andWhere('p.status = :status')
        ->setParameter('event', $event)
        ->setParameter('status', \App\Entity\PhotoStatus::Ready)
        ->getQuery()
        ->getSingleScalarResult();
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventPublishTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Admin/EventController.php src/Repository/PhotoRepository.php tests/Functional/Admin/EventPublishTest.php
git commit -m "77 - add admin publish and notifications toggle endpoints"
```

---

### Task 9: Admin + public template integration

**Files:**
- Modify: the admin event detail template (from Task 8 grep)
- Modify: the public event landing template (find via `grep -rln "public_event_landing" templates/ || grep -rln "block body" templates/public/event`)

**Interfaces:**
- Consumes: `Event::areNotificationsEnabled()`, `Event::isPublished()`, `OrganizerMailerResolver::isCustomActive()` (exposed to the template via the controller), `EventNotificationSubscriptionRepository::countByEvent()`, projected-duration value.

- [ ] **Step 1: Admin — subscriber count + publish/toggle controls**

In the admin event detail controller action, pass to the template: `subscriberCount` (`countByEvent`), `mailActive` (`isCustomActive($event->getOwner())`), `readyPhotoCount`, and `projectedMinutes = (int) ceil(confirmedCount / rate)`. In the template, render:
- the notifications toggle form (POST `admin_event_toggle_notifications`, hidden `_token` = `csrf_token('notifications' ~ event.id)`, `enabled` checkbox) — only when `mailActive`; otherwise a hint linking to mail setup.
- the publish form (POST `admin_event_publish`, `_token` = `csrf_token('publish' ~ event.id)`), with the submit button `disabled` when `event.isPublished` or `readyPhotoCount < 1` or not `mailActive`. Show `Notifying {{ subscriberCount }} subscribers — sends will complete in ~{{ projectedMinutes }} minutes` next to it, and **subscriber count only** (no list).
- once `event.isPublished`, replace the publish control with a "Published {{ event.publishedAt|date }}" line.

- [ ] **Step 2: Public — signup form / closed notice**

In the public landing controller action, expose `notificationsOpen = event.areNotificationsEnabled and not event.isPublished and mailActive` and `notificationsPublished = event.isPublished`. In the landing template add:

```twig
{% if notificationsOpen %}
    <form method="post" action="{{ path('public_event_notify_subscribe', {slug: event.slug}) }}" class="mt-6">
        <label>Email me when photos are live
            <input type="email" name="email" required>
        </label>
        {# honeypot: must stay visually hidden but present in the DOM #}
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
        <button type="submit">Notify me</button>
    </form>
{% elseif notificationsPublished %}
    <p class="mt-6 text-sm text-gray-500">Notifications already sent for this event.</p>
{% endif %}
```

No CSRF token in this form (public routes are sessionless).

- [ ] **Step 3: Manual smoke check**

Run: `docker compose up -d` then visit `http://localhost:8080/e/<slug>` for an event with notifications enabled and an organizer with verified mail — confirm the form renders; visit an admin event page — confirm the publish button + subscriber count render.

- [ ] **Step 4: Verify public template stays sessionless**

Run: `grep -rn "csrf_token\|getSession\|addFlash" templates/public/`
Expected: no new matches from this feature (the signup form must have none).

- [ ] **Step 5: Commit**

```bash
git add templates/
git commit -m "77 - wire publish controls and public signup form into templates"
```

---

### Task 10: Docs, env, and full-suite gate

**Files:**
- Modify: `CLAUDE.md` (per-organizer mail paragraph)
- Modify: `.env` (already done in Task 6 — verify documented)
- Modify: deploy runbook if present (`grep -rln "MAILER_DSN" docs/`)

- [ ] **Step 1: Update CLAUDE.md**

In the "Per-organizer mail transport" paragraph, replace the sentence describing the `SodiumException` platform fallback with the strict contract. New wording:

> `OrganizerMailerResolver::forEvent($event)` / `forUser($user)` return the organizer's verified mailer or **throw `OrganizerMailNotConfiguredException`** — there is no platform-mail fallback (changed in #77). A corrupted-ciphertext `\SodiumException` is logged and re-thrown as `OrganizerMailNotConfiguredException` (no silent platform fallback). A verified transport that throws at send time (rebind/`DsnRejected`) is auto-unverified and hard-fails the Messenger message. Platform-level flows (invitations, password reset) autowire `MailerInterface` directly. The visitor "photos are live" notification (#77) uses this resolver exclusively for both the confirmation and announcement emails and is gated on `isCustomActive($owner)`.

- [ ] **Step 2: Note the prod mail prerequisite in the deploy runbook**

If a runbook references `MAILER_DSN`, add a line: organizers who use the #77 notification feature must have a verified custom transport; the platform `MAILER_DSN` is never used for those emails.

- [ ] **Step 3: Run the full quality gate**

Run:
```bash
vendor/bin/phpunit
vendor/bin/grumphp run
```
Expected: all green (phpstan level 10, phpcs PSR-12, phpmnd, phpcpd, rector dry-run, `doctrine:schema:validate`). Fix any magic-number (`phpmnd`) hits by promoting literals to named constants, and any duplication (`phpcpd`) flagged across the new handlers/templates.

- [ ] **Step 4: Final session-audit grep (issue requirement)**

Run: `grep -rn "addFlash\|getSession\|csrf_token" src/Controller/Public/ templates/public/`
Expected: the new notification controller and its templates contribute zero matches.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/
git commit -m "77 - document strict mailer contract and notification prerequisites"
```

---

## Self-Review

**Spec coverage:**
- Strict resolver / no platform fallback → Task 1. ✓
- Entity + state machine, tokens, expiry-via-`confirmation_expires_at` → Tasks 3, 4. ✓
- `Event.publishedAt` one-shot + `notificationsEnabled` → Task 2. ✓
- Unique `(event_id, email)` case-insensitive + `(event_id, status)` index + migration → Tasks 4, 5. ✓
- Repository queries → Task 5. ✓
- Re-subscribe matrix incl. confirmed→no-email → Task 7 (`subscribe`) + test. ✓
- Enumeration-safe identical response → Task 7 (`checkInbox` everywhere). ✓
- Honeypot, 5/hour/IP limiter, 1/10min per-email limiter → Task 7. ✓
- Confirm expiry-as-nonexistent + single-use token → Task 7 (`confirm`) + entity. ✓
- Unsubscribe long-lived token, works post-publish → Task 7. ✓
- Admin publish: EDIT + CSRF, ≥1 Ready, active mail, one-shot, dispatch fan-out → Task 8. ✓
- Enable-notifications toggle gated on active mail → Task 8. ✓
- Subscriber-count-only admin display → Task 9. ✓
- Throttled fan-out with monotonic `DelayStamp`, env rate default 30 → Task 6 + test. ✓
- Per-recipient strict send, hard-fail no fallback, `markNotified` → Task 6. ✓
- Mail templates confirm/live html+txt, no-referrer → Tasks 6, 7. ✓
- Public form / closed notice → Task 9. ✓
- `.env` rate var, no `MAIL_FROM` → Task 6. ✓
- CLAUDE.md doc update → Task 10. ✓
- Session audit grep → Tasks 7, 9, 10. ✓
- Tests (unit/integration/functional incl. monotonic delays, honeypot, both limiters, CSRF, no-ready, expired/reused token, confirmed-no-email) → Tasks 1,2,4,5,6,7,8. ✓

**Open items the implementer must resolve against the live code (flagged inline, not placeholders):**
- Test-env mail capture mechanism: `assertEmailCount` vs the `CapturedMail` helper used by `AccountMailFlowTest` — read that test and match it (Tasks 6, 7).
- `messenger.transport.async` must be `in-memory://` under `when@test` for the fan-out assertions; adjust if it is still Doctrine (Task 6).
- Exact admin event detail route name and error convention (422 vs redirect+flash) — match the existing controller (Task 8).
- `Photo` constructor + `markReady` signature for the Ready-photo test fixture — read `tests/Unit/Entity/PhotoTest.php` (Task 8).
- Whether `PhotoRepository` already has a Ready-count helper before adding one (Task 8).
- The public landing + admin detail base templates and block names — match existing templates (Task 9).

**Type consistency:** route names (`public_event_notify_confirm`, `public_event_notify_unsubscribe`, `public_event_notify_subscribe`, `admin_event_publish`, `admin_event_toggle_notifications`), method names (`markPublished`, `areNotificationsEnabled`, `isConfirmationExpired`, `restartPending`, `markNotified`, `findConfirmedByEvent`, `countByEvent`, `findOneByEventAndEmail`, `isCustomActive`, `forEvent`), and message property names (`eventId`, `subscriptionId`) are used consistently across tasks. ✓
