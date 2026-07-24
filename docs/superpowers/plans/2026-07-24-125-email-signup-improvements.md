# #125 Email Signup Improvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface confirmed-vs-total signup counts, move the double-opt-in confirmation email off the request path onto Messenger, and give organizers a dashboard button to re-send confirmations to unverified (`Pending`) subscribers.

**Architecture:** Extract a shared `EventStyledEmailFactory` that builds both the confirmation and live-announcement `TemplatedEmail`s. Introduce one async message (`SendSubscriptionConfirmationEmail`) + handler as the single send path used by the public signup, the dashboard re-send, and (later, #123) a console command. A `PendingConfirmationResender` service restarts each `Pending` subscription and fans the message out with `DelayStamp` spacing.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, Symfony Messenger (Doctrine transport; `in-memory://` in test), PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-07-24-125-email-signup-improvements-design.md`

## Global Constraints

- Branch: `feature/125-email-signup-improvements` (already created off `origin/main`).
- **Claude does not `git commit`** (project rule). Each task's final step runs the touched-file quality gate and leaves changes in the working tree; a single commit message is proposed at the very end.
- No schema change → **no migration** in this work.
- PHPStan level 10, PHPCS PSR-12, phpmnd (no magic numbers in `src/` — extract `const` like the existing `MS_PER_MINUTE = 60_000`), phpcpd, rector, phpunit all gate. Run `vendor/bin/grumphp run` before proposing the commit message.
- New classes are `final readonly` where they hold only injected deps, mirroring `SendEventLiveEmailHandler`.
- Injected storages/services use explicit `#[Autowire]` where ambiguous; rate value is `#[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')] int`.
- Time is constructed inline as `new DateTimeImmutable('now', new DateTimeZone('UTC'))`, matching existing handlers.
- Tests: async messages are asserted via the `messenger.transport.async` `InMemoryTransport` (`getSent()`), NOT via `CapturedMail`, because the test transport does not auto-consume. `CapturedMail::messagesForHost('93.184.216.34')` is only used when a handler is invoked directly.

---

### Task 1: Repository query methods (COUNT-by-status + find Pending)

**Files:**
- Modify: `src/Repository/EventNotificationSubscriptionRepository.php`
- Test: `tests/Integration/Repository/EventNotificationSubscriptionRepositoryTest.php`

**Interfaces:**
- Produces:
  - `countConfirmedByEvent(Event $event): int`
  - `countPendingByEvent(Event $event): int`
  - `findPendingByEvent(Event $event): array<int, EventNotificationSubscription>`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Integration/Repository/EventNotificationSubscriptionRepositoryTest.php` (mirror the existing fixtures in that file — reuse its event/owner setup helper if present; otherwise build inline as below).

```php
public function testCountsAndFindByStatus(): void
{
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $owner = new User('countstatus-owner@example.com', 'Owner');
    $em->persist($owner);
    $event = new Event(
        slug: 'count-status-event',
        name: 'Counts',
        startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
        endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
        owner: $owner,
    );
    $em->persist($event);

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $confirmed = new EventNotificationSubscription($event, 'confirmed@example.com', $now);
    $confirmed->confirm($now);
    $em->persist($confirmed);

    $pendingA = new EventNotificationSubscription($event, 'pending-a@example.com', $now);
    $pendingB = new EventNotificationSubscription($event, 'pending-b@example.com', $now);
    $em->persist($pendingA);
    $em->persist($pendingB);

    $unsub = new EventNotificationSubscription($event, 'unsub@example.com', $now);
    $unsub->unsubscribe($now);
    $em->persist($unsub);

    $em->flush();

    /** @var EventNotificationSubscriptionRepository $repo */
    $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);

    self::assertSame(1, $repo->countConfirmedByEvent($event));
    self::assertSame(2, $repo->countPendingByEvent($event));
    self::assertSame(4, $repo->countByEvent($event));

    $pending = $repo->findPendingByEvent($event);
    self::assertCount(2, $pending);
    foreach ($pending as $sub) {
        self::assertSame(EventNotificationStatus::Pending, $sub->getStatus());
    }
}
```

Ensure the test file's `use` block includes `App\Entity\Event`, `App\Entity\User`, `App\Entity\EventNotificationStatus`, `App\Entity\EventNotificationSubscription`, `DateTimeImmutable`, `DateTimeZone`, `Doctrine\ORM\EntityManagerInterface`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testCountsAndFindByStatus`
Expected: FAIL — `Call to undefined method ...::countConfirmedByEvent()`

- [ ] **Step 3: Implement the three methods**

Add to `src/Repository/EventNotificationSubscriptionRepository.php` (after `countByEvent`):

```php
public function countConfirmedByEvent(Event $event): int
{
    return (int) $this->createQueryBuilder('s')
        ->select('COUNT(s.id)')
        ->andWhere('s.event = :event')
        ->andWhere('s.status = :status')
        ->setParameter('event', $event)
        ->setParameter('status', EventNotificationStatus::Confirmed)
        ->getQuery()
        ->getSingleScalarResult();
}

public function countPendingByEvent(Event $event): int
{
    return (int) $this->createQueryBuilder('s')
        ->select('COUNT(s.id)')
        ->andWhere('s.event = :event')
        ->andWhere('s.status = :status')
        ->setParameter('event', $event)
        ->setParameter('status', EventNotificationStatus::Pending)
        ->getQuery()
        ->getSingleScalarResult();
}

/**
 * @return array<int, EventNotificationSubscription>
 */
public function findPendingByEvent(Event $event): array
{
    /** @var array<int, EventNotificationSubscription> $result */
    $result = $this->createQueryBuilder('s')
        ->andWhere('s.event = :event')
        ->andWhere('s.status = :status')
        ->setParameter('event', $event)
        ->setParameter('status', EventNotificationStatus::Pending)
        ->orderBy('s.id', 'ASC')
        ->getQuery()
        ->getResult();

    return $result;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testCountsAndFindByStatus`
Expected: PASS

- [ ] **Step 5: Gate the touched files**

Run: `vendor/bin/phpstan analyse src/Repository/EventNotificationSubscriptionRepository.php && vendor/bin/phpcs src/Repository/EventNotificationSubscriptionRepository.php`
Expected: no errors. Leave changes staged.

---

### Task 2: `EventStyledEmailFactory` + refactor live-announcement handler onto it

**Files:**
- Create: `src/Service/Mail/EventStyledEmailFactory.php`
- Modify: `src/MessageHandler/SendEventLiveEmailHandler.php`
- Test: `tests/Unit/Service/Mail/EventStyledEmailFactoryTest.php`

**Interfaces:**
- Produces:
  - `EventStyledEmailFactory::confirmation(Event $event, EventNotificationSubscription $subscription, UserMailConfig $config): TemplatedEmail`
  - `EventStyledEmailFactory::liveAnnouncement(Event $event, EventNotificationSubscription $subscription, UserMailConfig $config): TemplatedEmail`
- Consumes: `UrlGeneratorInterface`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Mail/EventStyledEmailFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\EventStyledEmailFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EventStyledEmailFactoryTest extends TestCase
{
    public function testConfirmationEmailShape(): void
    {
        $factory = new EventStyledEmailFactory($this->urlGenerator());
        [$event, $sub, $config] = $this->fixtures();

        $email = $factory->confirmation($event, $sub, $config);

        self::assertSame('Confirm notifications for Sample Event', $email->getSubject());
        self::assertSame('email/event-notification/confirm.html.twig', $email->getHtmlTemplate());
        self::assertSame('email/event-notification/confirm.txt.twig', $email->getTextTemplate());
        self::assertSame('press@example.test', $email->getFrom()[0]->getAddress());
        self::assertSame('visitor@example.com', $email->getTo()[0]->getAddress());
        $context = $email->getContext();
        self::assertSame('Sample Event', $context['eventName']);
        self::assertArrayHasKey('confirmUrl', $context);
        self::assertArrayHasKey('unsubscribeUrl', $context);
    }

    public function testLiveAnnouncementEmailShape(): void
    {
        $factory = new EventStyledEmailFactory($this->urlGenerator());
        [$event, $sub, $config] = $this->fixtures();

        $email = $factory->liveAnnouncement($event, $sub, $config);

        self::assertSame('Photos from Sample Event are live', $email->getSubject());
        self::assertSame('email/event-notification/live.html.twig', $email->getHtmlTemplate());
        self::assertSame('email/event-notification/live.txt.twig', $email->getTextTemplate());
        $context = $email->getContext();
        self::assertArrayHasKey('eventUrl', $context);
        self::assertArrayHasKey('unsubscribeUrl', $context);
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $gen = $this->createStub(UrlGeneratorInterface::class);
        $gen->method('generate')->willReturn('https://example.test/url');

        return $gen;
    }

    /** @return array{Event, EventNotificationSubscription, UserMailConfig} */
    private function fixtures(): array
    {
        $owner = new User('owner@example.test', 'Owner');
        $event = new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $sub = new EventNotificationSubscription(
            $event,
            'visitor@example.com',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $config = new UserMailConfig($owner, 'ignored-ciphertext', 'press@example.test', null);

        return [$event, $sub, $config];
    }
}
```

Note: confirm the `UserMailConfig` constructor signature matches `($owner, $encryptedDsn, $senderAddress, $senderName)` as used in the existing functional tests; adjust the fixture call if the real signature differs. `Address` import may be unused — drop it if so.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/EventStyledEmailFactoryTest.php`
Expected: FAIL — class `EventStyledEmailFactory` not found.

- [ ] **Step 3: Create the factory**

Create `src/Service/Mail/EventStyledEmailFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\UserMailConfig;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class EventStyledEmailFactory
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function confirmation(
        Event $event,
        EventNotificationSubscription $subscription,
        UserMailConfig $config,
    ): TemplatedEmail {
        $confirmUrl = $this->urlGenerator->generate('public_event_notify_confirm', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getConfirmationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return new TemplatedEmail()
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Confirm notifications for %s', $event->getName()))
            ->htmlTemplate('email/event-notification/confirm.html.twig')
            ->textTemplate('email/event-notification/confirm.txt.twig')
            ->context([
                'eventName' => $event->getName(),
                'confirmUrl' => $confirmUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl($event, $subscription),
            ]);
    }

    public function liveAnnouncement(
        Event $event,
        EventNotificationSubscription $subscription,
        UserMailConfig $config,
    ): TemplatedEmail {
        $eventUrl = $this->urlGenerator->generate('public_event_landing', [
            'slug' => $event->getSlug(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return new TemplatedEmail()
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Photos from %s are live', $event->getName()))
            ->htmlTemplate('email/event-notification/live.html.twig')
            ->textTemplate('email/event-notification/live.txt.twig')
            ->context([
                'eventName' => $event->getName(),
                'eventUrl' => $eventUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl($event, $subscription),
            ]);
    }

    private function unsubscribeUrl(Event $event, EventNotificationSubscription $subscription): string
    {
        return $this->urlGenerator->generate('public_event_notify_unsubscribe', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getUnsubscribeToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/EventStyledEmailFactoryTest.php`
Expected: PASS

- [ ] **Step 5: Refactor `SendEventLiveEmailHandler` onto the factory**

In `src/MessageHandler/SendEventLiveEmailHandler.php`:
- Add constructor dependency `private EventStyledEmailFactory $emailFactory`.
- Remove the `UrlGeneratorInterface` constructor dependency and its import (now unused).
- Replace the URL building + inline `TemplatedEmail` construction (the `$eventUrl`/`$unsubscribeUrl`/`$email = new TemplatedEmail()...` block) with:

```php
        $email = $this->emailFactory->liveAnnouncement($event, $subscription, $config);

        $mailer->send($email);
```

- Add `use App\Service\Mail\EventStyledEmailFactory;`. Keep `EntityManagerInterface` (used by `markNotified` flush), `OrganizerMailerResolver`, `EventNotificationSubscriptionRepository`.

- [ ] **Step 6: Run the live-announcement tests to verify no regression**

Run: `vendor/bin/phpunit tests/Functional/Notification/EventLiveFanOutTest.php tests/Unit/Service/Mail/EventStyledEmailFactoryTest.php`
Expected: PASS

- [ ] **Step 7: Gate the touched files**

Run: `vendor/bin/phpstan analyse src/Service/Mail/EventStyledEmailFactory.php src/MessageHandler/SendEventLiveEmailHandler.php && vendor/bin/phpcs src/Service/Mail/EventStyledEmailFactory.php src/MessageHandler/SendEventLiveEmailHandler.php`
Expected: no errors.

---

### Task 3: Async confirmation message + handler + routing

**Files:**
- Create: `src/Message/SendSubscriptionConfirmationEmail.php`
- Create: `src/MessageHandler/SendSubscriptionConfirmationEmailHandler.php`
- Modify: `config/packages/messenger.yaml`
- Test: `tests/Functional/Notification/SendSubscriptionConfirmationEmailHandlerTest.php`

**Interfaces:**
- Produces:
  - `SendSubscriptionConfirmationEmail` with public `int $subscriptionId`.
  - `SendSubscriptionConfirmationEmailHandler` (`__invoke(SendSubscriptionConfirmationEmail): void`).
- Consumes: `EventStyledEmailFactory` (Task 2), `EventNotificationSubscriptionRepository`, `OrganizerMailerResolver`.

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Notification/SendSubscriptionConfirmationEmailHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendSubscriptionConfirmationEmail;
use App\MessageHandler\SendSubscriptionConfirmationEmailHandler;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SendSubscriptionConfirmationEmailHandlerTest extends KernelTestCase
{
    private const string ORGANIZER_MAIL_HOST = '93.184.216.34';

    protected function setUp(): void
    {
        CapturedMail::reset();
    }

    public function testPendingSubscriptionGetsOneEmail(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$event, $sub] = $this->makePending($em, 'confirm-async-a');

        $this->handler()(new SendSubscriptionConfirmationEmail((int) $sub->getId()));

        self::assertCount(1, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));
    }

    public function testConfirmedSubscriptionIsSkipped(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$event, $sub] = $this->makePending($em, 'confirm-async-b');
        $sub->confirm(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $em->flush();

        $this->handler()(new SendSubscriptionConfirmationEmail((int) $sub->getId()));

        self::assertCount(0, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));
    }

    public function testMissingSubscriptionIsNoOp(): void
    {
        self::bootKernel();

        $this->handler()(new SendSubscriptionConfirmationEmail(999999));

        self::assertCount(0, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));
    }

    private function handler(): SendSubscriptionConfirmationEmailHandler
    {
        /** @var SendSubscriptionConfirmationEmailHandler $handler */
        $handler = self::getContainer()->get(SendSubscriptionConfirmationEmailHandler::class);

        return $handler;
    }

    /** @return array{Event, EventNotificationSubscription} */
    private function makePending(EntityManagerInterface $em, string $slug): array
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

        $sub = new EventNotificationSubscription(
            $event,
            'visitor@example.com',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $em->persist($sub);
        $em->flush();

        return [$event, $sub];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Notification/SendSubscriptionConfirmationEmailHandlerTest.php`
Expected: FAIL — message/handler classes not found.

- [ ] **Step 3: Create the message**

Create `src/Message/SendSubscriptionConfirmationEmail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendSubscriptionConfirmationEmail
{
    public function __construct(public int $subscriptionId)
    {
    }
}
```

- [ ] **Step 4: Create the handler**

Create `src/MessageHandler/SendSubscriptionConfirmationEmailHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\EventNotificationStatus;
use App\Entity\UserMailConfig;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Service\Mail\EventStyledEmailFactory;
use App\Service\Mail\OrganizerMailerResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendSubscriptionConfirmationEmailHandler
{
    public function __construct(
        private EventNotificationSubscriptionRepository $subscriptions,
        private OrganizerMailerResolver $mailerResolver,
        private EventStyledEmailFactory $emailFactory,
    ) {
    }

    public function __invoke(SendSubscriptionConfirmationEmail $message): void
    {
        $subscription = $this->subscriptions->find($message->subscriptionId);
        if ($subscription === null || $subscription->getStatus() !== EventNotificationStatus::Pending) {
            return;
        }

        $event = $subscription->getEvent();
        $config = $event->getOwner()->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            return;
        }

        // Strict resolver: a throw hard-fails into Messenger retry/DLQ — never a
        // platform-mail fallback.
        $mailer = $this->mailerResolver->forEvent($event);
        $mailer->send($this->emailFactory->confirmation($event, $subscription, $config));
    }
}
```

- [ ] **Step 5: Route the message to `async`**

In `config/packages/messenger.yaml`, under `framework.messenger.routing`, add the line after the existing `SendEventLiveEmail` entry:

```yaml
            'App\Message\SendSubscriptionConfirmationEmail': async
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Notification/SendSubscriptionConfirmationEmailHandlerTest.php`
Expected: PASS (3 tests)

- [ ] **Step 7: Gate the touched files**

Run: `vendor/bin/phpstan analyse src/Message/SendSubscriptionConfirmationEmail.php src/MessageHandler/SendSubscriptionConfirmationEmailHandler.php && vendor/bin/phpcs src/Message/SendSubscriptionConfirmationEmail.php src/MessageHandler/SendSubscriptionConfirmationEmailHandler.php`
Expected: no errors.

---

### Task 4: Dispatch confirmation asynchronously from the public signup

**Files:**
- Modify: `src/Controller/Public/EventNotificationController.php`
- Test: `tests/Functional/Public/EventNotificationControllerTest.php` (update 3 existing tests)

**Interfaces:**
- Consumes: `SendSubscriptionConfirmationEmail` (Task 3), `MessageBusInterface`.

- [ ] **Step 1: Update the existing tests to assert dispatch (they now fail)**

In `tests/Functional/Public/EventNotificationControllerTest.php`:

Add imports:
```php
use App\Message\SendSubscriptionConfirmationEmail;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
```

Add a helper method:
```php
private function asyncTransport(): InMemoryTransport
{
    /** @var InMemoryTransport $transport */
    $transport = self::getContainer()->get('messenger.transport.async');

    return $transport;
}
```

Rewrite `testSignupSendsConfirmationEmail` — replace the `CapturedMail` assertion with:
```php
        self::assertResponseIsSuccessful();

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(SendSubscriptionConfirmationEmail::class, $sent[0]->getMessage());

        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        $sub = $repo->findOneByEventAndEmail($event, 'visitor@example.com');
        $this->assertInstanceOf(EventNotificationSubscription::class, $sub);
        $this->assertSame(EventNotificationStatus::Pending, $sub->getStatus());
```

In `testHoneypotDropsSilently`, replace
`$this->assertCount(0, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));`
with
`$this->assertCount(0, $this->asyncTransport()->getSent());`

In `testConfirmedResubmitSendsNoEmail`, replace the same `CapturedMail` assertion with
`$this->assertCount(0, $this->asyncTransport()->getSent());`

Run: `vendor/bin/phpunit tests/Functional/Public/EventNotificationControllerTest.php`
Expected: FAIL — signup still sends inline (`getSent()` is empty; `CapturedMail` still has the message from the old code path).

- [ ] **Step 2: Swap inline send for async dispatch in the controller**

In `src/Controller/Public/EventNotificationController.php`:

Constructor — add `private readonly MessageBusInterface $bus,` and remove `private readonly LoggerInterface $logger,` (only `sendConfirmation()` used it).

Replace the send block (currently):
```php
        if ($shouldSend && $this->confirmResendLimiter->create($email)->consume()->isAccepted()) {
            $this->sendConfirmation($event, $subscription);
        }
```
with:
```php
        if ($shouldSend && $this->confirmResendLimiter->create($email)->consume()->isAccepted()) {
            $this->bus->dispatch(new SendSubscriptionConfirmationEmail((int) $subscription->getId()));
        }
```

Delete the entire `sendConfirmation()` private method.

Update imports: add
```php
use App\Message\SendSubscriptionConfirmationEmail;
use Symfony\Component\Messenger\MessageBusInterface;
```
Remove now-unused imports: `Throwable`, `App\Entity\UserMailConfig`, `Psr\Log\LoggerInterface`, `Symfony\Bridge\Twig\Mime\TemplatedEmail`, `Symfony\Component\Routing\Generator\UrlGeneratorInterface`. Keep `OrganizerMailerResolver` (still used for `isCustomActive`), `DateTimeImmutable`, `DateTimeZone`, `EntityManagerInterface`, `ValidatorInterface`, the two rate-limiter factories.

- [ ] **Step 3: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/EventNotificationControllerTest.php`
Expected: PASS (all tests in the file)

- [ ] **Step 4: Gate the touched file**

Run: `vendor/bin/phpstan analyse src/Controller/Public/EventNotificationController.php && vendor/bin/phpcs src/Controller/Public/EventNotificationController.php`
Expected: no errors (no unused private properties/imports).

---

### Task 5: Show confirmed-of-total counts in the dashboard

**Files:**
- Modify: `src/Controller/Admin/EventController.php:234-247` (the `edit()` render tail)
- Modify: `templates/admin/event/form.html.twig:79`
- Test: `tests/Functional/Public/EventNotificationControllerTest.php` is unrelated; add a functional assertion in a new/existing admin edit test — create `tests/Functional/Admin/EventNotificationCountsTest.php`

**Interfaces:**
- Consumes: `countConfirmedByEvent`, `countByEvent` (Task 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Admin/EventNotificationCountsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\DsnVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventNotificationCountsTest extends WebTestCase
{
    public function testEditPageShowsConfirmedOfTotal(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('counts-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');
        $em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            'counts-owner@example.com',
            null,
        );
        $config->markVerified();
        $em->persist($config);

        $event = new Event(
            slug: 'counts-dash-event',
            name: 'Counts Dash',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();
        $em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $confirmed = new EventNotificationSubscription($event, 'c@example.com', $now);
        $confirmed->confirm($now);
        $em->persist($confirmed);
        $em->persist(new EventNotificationSubscription($event, 'p@example.com', $now));
        $em->flush();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1 confirmed of 2 total');
    }
}
```

Run: `vendor/bin/phpunit tests/Functional/Admin/EventNotificationCountsTest.php`
Expected: FAIL — page still renders "2 subscriber(s) so far."

- [ ] **Step 2: Use the count query in `edit()` and pass `confirmedCount`**

In `src/Controller/Admin/EventController.php`, replace:
```php
        $rate           = max(1, $this->notificationRatePerMinute);
        $confirmedCount = count($this->subscriptions->findConfirmedByEvent($event));
```
with:
```php
        $rate           = max(1, $this->notificationRatePerMinute);
        $confirmedCount = $this->subscriptions->countConfirmedByEvent($event);
```

Add `'confirmedCount' => $confirmedCount,` to the `render('admin/event/form.html.twig', [...])` context array.

- [ ] **Step 3: Update the template copy**

In `templates/admin/event/form.html.twig`, replace line 79:
```twig
                    <p class="text-sm text-base-content/70">{{ subscriberCount }} subscriber(s) so far.</p>
```
with:
```twig
                    <p class="text-sm text-base-content/70">{{ confirmedCount }} confirmed of {{ subscriberCount }} total signup(s).</p>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventNotificationCountsTest.php`
Expected: PASS

- [ ] **Step 5: Gate the touched files**

Run: `vendor/bin/phpstan analyse src/Controller/Admin/EventController.php && vendor/bin/phpcs src/Controller/Admin/EventController.php`
Expected: no errors.

---

### Task 6: `PendingConfirmationResender` service

**Files:**
- Create: `src/Service/Notification/PendingConfirmationResender.php`
- Test: `tests/Functional/Notification/PendingConfirmationResenderTest.php`

**Interfaces:**
- Produces: `PendingConfirmationResender::resendAll(Event $event): int`.
- Consumes: `findPendingByEvent` (Task 1), `SendSubscriptionConfirmationEmail` (Task 3).

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Notification/PendingConfirmationResenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Service\Notification\PendingConfirmationResender;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PendingConfirmationResenderTest extends KernelTestCase
{
    public function testResendsOnlyPendingWithFreshTokensAndSpacedDispatch(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('resend-owner@example.com', 'Owner');
        $em->persist($owner);
        $event = new Event(
            slug: 'resend-event',
            name: 'Resend',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $pendingA = new EventNotificationSubscription($event, 'pa@example.com', $now);
        $pendingB = new EventNotificationSubscription($event, 'pb@example.com', $now);
        $tokenBefore = $pendingA->getConfirmationToken();
        $em->persist($pendingA);
        $em->persist($pendingB);

        $confirmed = new EventNotificationSubscription($event, 'conf@example.com', $now);
        $confirmed->confirm($now);
        $em->persist($confirmed);

        $unsub = new EventNotificationSubscription($event, 'unsub@example.com', $now);
        $unsub->unsubscribe($now);
        $em->persist($unsub);
        $em->flush();

        /** @var PendingConfirmationResender $resender */
        $resender = $container->get(PendingConfirmationResender::class);
        $count = $resender->resendAll($event);

        self::assertSame(2, $count);

        // Fresh token minted for pending rows.
        $em->refresh($pendingA);
        self::assertNotSame($tokenBefore, $pendingA->getConfirmationToken());
        self::assertSame(EventNotificationStatus::Pending, $pendingA->getStatus());

        // Confirmed / unsubscribed untouched.
        $em->refresh($confirmed);
        self::assertSame(EventNotificationStatus::Confirmed, $confirmed->getStatus());
        $em->refresh($unsub);
        self::assertSame(EventNotificationStatus::Unsubscribed, $unsub->getStatus());

        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(2, $sent);
        $previousDelay = -1;
        foreach ($sent as $envelope) {
            self::assertInstanceOf(SendSubscriptionConfirmationEmail::class, $envelope->getMessage());
            /** @var DelayStamp|null $stamp */
            $stamp = $envelope->last(DelayStamp::class);
            self::assertInstanceOf(DelayStamp::class, $stamp);
            self::assertGreaterThan($previousDelay, $stamp->getDelay());
            $previousDelay = $stamp->getDelay();
        }
    }
}
```

Run: `vendor/bin/phpunit tests/Functional/Notification/PendingConfirmationResenderTest.php`
Expected: FAIL — service class not found.

- [ ] **Step 2: Create the service**

Create `src/Service/Notification/PendingConfirmationResender.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Event;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Repository\EventNotificationSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class PendingConfirmationResender
{
    private const int MS_PER_MINUTE = 60_000;

    public function __construct(
        private EventNotificationSubscriptionRepository $subscriptions,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        #[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')]
        private int $ratePerMinute,
    ) {
    }

    /**
     * Re-send the double-opt-in confirmation to every Pending subscriber of the
     * event: fresh token/expiry (so stale links don't hit the timed-out page),
     * then the async confirmation message, spaced to respect SMTP caps. Confirmed
     * and Unsubscribed rows are never touched.
     */
    public function resendAll(Event $event): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pending = $this->subscriptions->findPendingByEvent($event);

        foreach ($pending as $subscription) {
            $subscription->restartPending($now);
        }
        $this->em->flush();

        $rate = max(1, $this->ratePerMinute);
        $intervalMs = intdiv(self::MS_PER_MINUTE, $rate);

        $index = 0;
        foreach ($pending as $subscription) {
            $this->bus->dispatch(
                new SendSubscriptionConfirmationEmail((int) $subscription->getId()),
                [new DelayStamp($index * $intervalMs)],
            );
            $this->logger->info('Re-queued confirmation for pending subscriber.', [
                'event_id' => $event->getId(),
                'subscription_id' => $subscription->getId(),
            ]);
            ++$index;
        }

        return count($pending);
    }
}
```

- [ ] **Step 3: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Notification/PendingConfirmationResenderTest.php`
Expected: PASS

- [ ] **Step 4: Gate the touched file**

Run: `vendor/bin/phpstan analyse src/Service/Notification/PendingConfirmationResender.php && vendor/bin/phpcs src/Service/Notification/PendingConfirmationResender.php`
Expected: no errors (`MS_PER_MINUTE` const keeps phpmnd green).

---

### Task 7: Dashboard re-send route + button

**Files:**
- Modify: `src/Controller/Admin/EventController.php` (constructor dep, `edit()` context, new route)
- Modify: `templates/admin/event/form.html.twig` (Visitor notifications section)
- Test: `tests/Functional/Admin/EventNotificationResendTest.php`

**Interfaces:**
- Consumes: `PendingConfirmationResender::resendAll` (Task 6), `countPendingByEvent` (Task 1), `EventVoter::EDIT`.

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Admin/EventNotificationResendTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Service\Mail\DsnVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EventNotificationResendTest extends WebTestCase
{
    public function testResendButtonNudgesOnlyPending(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        [$owner, $event] = $this->makeEvent($em, 'resend-dash-event');

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $em->persist(new EventNotificationSubscription($event, 'p1@example.com', $now));
        $em->persist(new EventNotificationSubscription($event, 'p2@example.com', $now));
        $confirmed = new EventNotificationSubscription($event, 'c@example.com', $now);
        $confirmed->confirm($now);
        $em->persist($confirmed);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $client->submitForm('Re-send confirmation to 2 unverified subscriber(s)');
        self::assertResponseRedirects('/admin/events/' . $event->getId() . '/edit');

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(2, $sent);
        self::assertInstanceOf(SendSubscriptionConfirmationEmail::class, $sent[0]->getMessage());
    }

    public function testResendBlockedAfterPublish(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        [$owner, $event] = $this->makeEvent($em, 'resend-published-event');
        $event->markPublished(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $em->persist(new EventNotificationSubscription(
            $event,
            'p@example.com',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ));
        $em->flush();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/notify/resend-pending',
            ['_token' => (string) self::getContainer()->get('security.csrf.token_manager')
                ->getToken('resend_pending_' . $event->getId())],
        );

        self::assertResponseRedirects('/admin/events/' . $event->getId() . '/edit');

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent());
    }

    public function testResendRejectsBadCsrf(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        [$owner, $event] = $this->makeEvent($em, 'resend-csrf-event');
        $em->flush();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/notify/resend-pending',
            ['_token' => 'wrong'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{User, Event} */
    private function makeEvent(EntityManagerInterface $em, string $slug): array
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

        return [$owner, $event];
    }
}
```

Run: `vendor/bin/phpunit tests/Functional/Admin/EventNotificationResendTest.php`
Expected: FAIL — route `admin_event_notify_resend_pending` does not exist / button not found.

- [ ] **Step 2: Add the resender dependency + `pendingCount` to `edit()`**

In `src/Controller/Admin/EventController.php`:

Add to the constructor:
```php
        private readonly PendingConfirmationResender $pendingResender,
```
Add import:
```php
use App\Service\Notification\PendingConfirmationResender;
```

In `edit()`'s render context array, add:
```php
            'pendingCount'     => $this->subscriptions->countPendingByEvent($event),
```

- [ ] **Step 3: Add the re-send route**

Add this method to `src/Controller/Admin/EventController.php` (e.g. after `publish()`):

```php
    #[Route(
        '/admin/events/{id}/notify/resend-pending',
        name: 'admin_event_notify_resend_pending',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function resendPendingConfirmations(Event $event, Request $request): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('resend_pending_' . $event->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($event->isPublished() || !$this->mailerResolver->isCustomActive($event->getOwner())) {
            $this->addFlash('error', 'Cannot re-send confirmations for this event.');

            return $this->redirectToRoute('admin_event_edit', ['id' => $event->getId()]);
        }

        $count = $this->pendingResender->resendAll($event);

        $this->addFlash('success', sprintf('Re-sent confirmation to %d unverified subscriber(s).', $count));

        return $this->redirectToRoute('admin_event_edit', ['id' => $event->getId()]);
    }
```

- [ ] **Step 4: Add the button to the template**

In `templates/admin/event/form.html.twig`, inside the `{% else %}` branch of `{% if not mailActive %}` (the Visitor notifications section), and inside the `{% if event.isPublished %}...{% else %}...{% endif %}` — add within the `{% else %}` (not published) block, after the publish `<form>`:

```twig
                        {% if pendingCount > 0 %}
                            <form method="post" action="{{ path('admin_event_notify_resend_pending', {id: event.id}) }}" class="mt-2">
                                <input type="hidden" name="_token" value="{{ csrf_token('resend_pending_' ~ event.id) }}">
                                <button type="submit" class="btn btn-outline btn-sm w-fit">
                                    Re-send confirmation to {{ pendingCount }} unverified subscriber(s)
                                </button>
                            </form>
                        {% endif %}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventNotificationResendTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Gate the touched files**

Run: `vendor/bin/phpstan analyse src/Controller/Admin/EventController.php && vendor/bin/phpcs src/Controller/Admin/EventController.php`
Expected: no errors.

---

### Task 8: Full-suite verification

- [ ] **Step 1: Run the complete quality gate**

Run: `vendor/bin/grumphp run`
Expected: all tasks green (phpstan L10, phpcs, phpmnd, phpcpd, rector, securitychecker, phpunit full suite, doctrine:schema:validate).

- [ ] **Step 2: Manual smoke (optional but recommended)**

Bring up the stack (`docker compose up -d`), sign up on a test event's `/e/<slug>/notify`, tail `docker compose logs -f worker` to confirm the async confirmation is consumed and the confirm link works; on the admin edit page confirm the "N confirmed of M total" copy and the "Re-send confirmation to N unverified subscriber(s)" button dispatches.

- [ ] **Step 3: Propose the commit message**

Per project rule, do not commit. Propose a single-line message referencing #125.

---

## Self-Review

**Spec coverage:**
- Item 1 (confirmed vs total count + `countConfirmedByEvent`): Task 1 (query) + Task 5 (display + use in `edit()`). ✔
- Item 2 (async confirmation, `final readonly` message/handler, idempotent guard, strict resolver, controller still renders `check_inbox`, limiter gates dispatch): Task 3 (message/handler/routing) + Task 4 (controller). Factory: Task 2. ✔
- Item 3 (dashboard re-send to Pending only, `restartPending`, spaced async dispatch, CSRF + `EventVoter::EDIT`, shared service for #123, log each outcome): Task 6 (service) + Task 7 (route/template). ✔
- Single send path across signup + dashboard (+ #123 console): all converge on `SendSubscriptionConfirmationEmail`/handler. ✔
- Out-of-scope respected: no subscriber list table; no `send_attempted_at`/observability (that's #123). ✔

**Placeholder scan:** none — every code/test step carries full source.

**Type consistency:** `countConfirmedByEvent`/`countPendingByEvent`/`findPendingByEvent` (Task 1) are used verbatim in Tasks 5–7; `SendSubscriptionConfirmationEmail(int $subscriptionId)` (Task 3) is dispatched identically in Tasks 4 and 6; `EventStyledEmailFactory::confirmation(Event, EventNotificationSubscription, UserMailConfig)` (Task 2) matches the handler call in Task 3; `PendingConfirmationResender::resendAll(Event): int` (Task 6) matches the controller call in Task 7. ✔

**Note for implementer:** verify the real `UserMailConfig` constructor signature before running the Task 2 unit test — the fixtures assume `(User $owner, string $encryptedDsn, string $senderAddress, ?string $senderName)` as used in existing functional tests. Adjust the fixture call (not the factory) if it differs.
```
