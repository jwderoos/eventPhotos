# Styled Event Emails With Inline Hero — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the two event-context emails (notification confirm + "photos are live") adopt the event's resolved colors and show the event hero (banner) image inline.

**Architecture:** A new `EventStyledEmailFactory` resolves the event's style via the existing `StyleResolver`, injects the `ResolvedStyle` and (when the event has a banner) an inline CID `DataPart` read from `event_banners_storage` into the email context, and returns a ready `TemplatedEmail`. Both send sites delegate to it. A shared email-safe Twig layout (`email/_layout.html.twig`, table + inline styles) renders the colors and the `cid:` hero; the two existing HTML templates extend it. Sends keep flowing through `OrganizerMailerResolver` → `RenderingMailer`, which runs `BodyRenderer::render()` — this both renders the Twig body and materializes the inline image part, so no transport changes are needed.

**Tech Stack:** PHP 8.5, Symfony 8 (Mailer/Mime, Twig bridge `TemplatedEmail`), Doctrine ORM 3, Flysystem, Twig, PHPUnit 13.

## Global Constraints

- **Attributes, never annotations** (mapping, routes, validation, DI).
- **Run PHP/Composer/`bin/console`/`vendor/bin/*` on the host** (PHP 8.5 via Homebrew). Docker only for the runtime stack.
- **GrumPHP gates must stay green:** phpstan level 10 (src, tests, public), phpcs PSR-12, phpmnd (no magic numbers in `src/` — use named constants), phpcpd (50-line / 100-token duplication in `src/`), rector, securitychecker_roave, `doctrine:schema:validate`, full PHPUnit suite.
- **Branch:** work on `feature/119-styled-event-emails` (already created; branch name matches `^(feature|hotfix|bugfix|release)/\d+-`; `main` is blacklisted for direct commits).
- **Commits:** Claude does not run `git commit`. Each "Commit" step means: **stage the listed files and surface the proposed one-line commit message**; the user performs the commit. Every message must contain the issue number `119`.
- **Injecting a specific storage:** six `FilesystemOperator` services exist — always use `#[Autowire(service: 'event_banners_storage')]`, never plain autowiring.
- **Tests fail on any deprecation/notice/warning** (PHPUnit `failOnDeprecation`/`Notice`/`Warning` = true).
- **No new columns, no migration, no new style settings** — reuse `Event::getStyle()`/`StyleResolver` and `Event::getBannerFilename()`.
- **Scope:** only the two `email/event-notification/*` emails. Do NOT touch platform emails (invitations, password reset, `mail-config/verify`).
- **Test fixture:** reuse `tests/fixtures/photos/bigger.jpg` (3000×2000 JPEG) as the banner payload.
- **Test mail seam:** organizer sends are captured by `App\Tests\Mail\CapturedMail`, keyed by the DSN's resolved host. A verified `UserMailConfig` with DSN `smtp://x@smtp.example-organizer.test:25` resolves (via the test `PrebakedDnsResolver`) to host `93.184.216.34` — assert against that host.

---

### Task 1: `EventStyledEmailFactory` service

The one place that turns an event + templates + context into a styled, hero-embedded `TemplatedEmail`.

**Files:**
- Create: `src/Service/Mail/EventStyledEmailFactory.php`
- Create: `tests/Integration/Service/Mail/EventStyledEmailFactoryTest.php`

**Interfaces:**
- Consumes: `App\Service\Style\StyleResolver::resolve(Event): ResolvedStyle`; `event_banners_storage` (`FilesystemOperator`); `Psr\Log\LoggerInterface`.
- Produces:
  - `EventStyledEmailFactory::create(Event $event, string $htmlTemplate, string $textTemplate, array $context): TemplatedEmail`
    — returns a `TemplatedEmail` with `htmlTemplate`/`textTemplate` set and context augmented with `style` (a `ResolvedStyle`) and, when the event has a readable banner, `heroCid` (string) plus an inline image `DataPart`. A missing/unreadable banner is logged and skipped (no `heroCid`, email still returned). The caller adds `->from()`/`->to()`/`->subject()`.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Mail\EventStyledEmailFactory;
use App\Service\Style\ResolvedStyle;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventStyledEmailFactoryTest extends KernelTestCase
{
    private const string HTML = 'email/event-notification/live.html.twig';

    private const string TXT = 'email/event-notification/live.txt.twig';

    private EntityManagerInterface $em;

    private FilesystemOperator $banners;

    private EventStyledEmailFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $banners */
        $banners = $c->get('event_banners_storage');
        /** @var EventStyledEmailFactory $factory */
        $factory = $c->get(EventStyledEmailFactory::class);
        $this->em = $em;
        $this->banners = $banners;
        $this->factory = $factory;
    }

    private function persistEvent(string $slug): Event
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $event = new Event(
            slug: $slug,
            name: 'Styled Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->getStyle()->setBackgroundColor('#123456');
        $event->getStyle()->setButtonColor('#abcdef');

        $this->em->persist($owner);
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testResolvesStyleAndEmbedsHeroWhenBannerPresent(): void
    {
        $event = $this->persistEvent('styled-with-banner');
        $filename = 'event-styled-with-banner.jpg';
        $this->banners->write($filename, (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg'));
        $event->setBannerFilename($filename);
        $this->em->flush();

        $email = $this->factory->create($event, self::HTML, self::TXT, ['eventName' => $event->getName()]);

        self::assertInstanceOf(TemplatedEmail::class, $email);
        $context = $email->getContext();
        self::assertInstanceOf(ResolvedStyle::class, $context['style']);
        self::assertSame('#123456', $context['style']->backgroundColor);
        self::assertArrayHasKey('heroCid', $context);
        self::assertNotSame('', $context['heroCid']);
        self::assertCount(1, $email->getAttachments());
    }

    public function testNoHeroWhenBannerAbsent(): void
    {
        $event = $this->persistEvent('styled-no-banner');

        $email = $this->factory->create($event, self::HTML, self::TXT, ['eventName' => $event->getName()]);

        self::assertArrayNotHasKey('heroCid', $email->getContext());
        self::assertCount(0, $email->getAttachments());
    }

    public function testMissingBannerFileIsSwallowed(): void
    {
        $event = $this->persistEvent('styled-broken-banner');
        $event->setBannerFilename('event-does-not-exist.jpg'); // never written to storage
        $this->em->flush();

        $email = $this->factory->create($event, self::HTML, self::TXT, ['eventName' => $event->getName()]);

        self::assertArrayNotHasKey('heroCid', $email->getContext());
        self::assertCount(0, $email->getAttachments());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Service/Mail/EventStyledEmailFactoryTest.php`
Expected: FAIL — `Class "App\Service\Mail\EventStyledEmailFactory" not found` (or service-not-found from the container).

- [ ] **Step 3: Implement `EventStyledEmailFactory`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Service\Style\StyleResolver;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

final readonly class EventStyledEmailFactory
{
    private const string HERO_NAME = 'event-hero';

    private const string HERO_MIME = 'image/jpeg';

    public function __construct(
        private StyleResolver $styleResolver,
        #[Autowire(service: 'event_banners_storage')]
        private FilesystemOperator $banners,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function create(Event $event, string $htmlTemplate, string $textTemplate, array $context): TemplatedEmail
    {
        $email = new TemplatedEmail()
            ->htmlTemplate($htmlTemplate)
            ->textTemplate($textTemplate);

        $context['style'] = $this->styleResolver->resolve($event);

        $heroCid = $this->attachHero($event, $email);
        if ($heroCid !== null) {
            $context['heroCid'] = $heroCid;
        }

        $email->context($context);

        return $email;
    }

    private function attachHero(Event $event, TemplatedEmail $email): ?string
    {
        $filename = $event->getBannerFilename();
        if ($filename === null) {
            return null;
        }

        try {
            $bytes = $this->banners->read($filename);
        } catch (Throwable $throwable) {
            $this->logger->warning('Could not read event banner for email hero; sending without it.', [
                'event_id' => $event->getId(),
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }

        $hero = new DataPart($bytes, self::HERO_NAME, self::HERO_MIME)->asInline();
        $email->addPart($hero);

        return $hero->getContentId();
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Service/Mail/EventStyledEmailFactoryTest.php`
Expected: PASS (3 tests). (The `live.*.twig` templates still render fine with the extra `style` context key — they ignore it until Task 2.)

- [ ] **Step 5: Commit**

Stage `src/Service/Mail/EventStyledEmailFactory.php`, `tests/Integration/Service/Mail/EventStyledEmailFactoryTest.php`. Proposed message:
`119 - add EventStyledEmailFactory (resolve style + inline CID hero)`

---

### Task 2: Email layout + styled "photos are live" email

Introduces the shared email-safe layout, converts `live.html.twig` to use it, and routes the live-email handler through the factory.

**Files:**
- Create: `templates/email/_layout.html.twig`
- Modify: `templates/email/event-notification/live.html.twig`
- Modify: `src/MessageHandler/SendEventLiveEmailHandler.php`
- Create: `tests/Functional/Notification/StyledLiveEmailTest.php`

**Interfaces:**
- Consumes: `EventStyledEmailFactory::create(...)` (Task 1). Layout reads context keys `style` (`ResolvedStyle`), `heroCid` (optional string), `eventName`, `unsubscribeUrl`, and the child-provided blocks `subject`, `heading`, `content`, `cta_url`, `cta_label`.

- [ ] **Step 1: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendEventLiveEmail;
use App\MessageHandler\SendEventLiveEmailHandler;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

final class StyledLiveEmailTest extends KernelTestCase
{
    /** DSN host smtp.example-organizer.test resolves here via the test DNS stub. */
    private const string ORGANIZER_MAIL_HOST = '93.184.216.34';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
        CapturedMail::reset();
    }

    /** @return array{0: EventNotificationSubscription} */
    private function seed(string $slug, bool $withBanner): array
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            $slug . '-owner@example.com',
            null,
        );
        $config->markVerified();
        $this->em->persist($config);

        $event = new Event(
            slug: $slug,
            name: 'Styled Live',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->getStyle()->setBackgroundColor('#123456');
        $event->getStyle()->setButtonColor('#abcdef');

        if ($withBanner) {
            /** @var FilesystemOperator $banners */
            $banners = self::getContainer()->get('event_banners_storage');
            $filename = 'event-' . $slug . '.jpg';
            $banners->write($filename, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg'));
            $event->setBannerFilename($filename);
        }

        $this->em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'visitor@example.com', $now);
        $sub->confirm($now);
        $this->em->persist($sub);
        $this->em->flush();

        return [$sub];
    }

    private function invoke(EventNotificationSubscription $sub): Email
    {
        /** @var SendEventLiveEmailHandler $handler */
        $handler = self::getContainer()->get(SendEventLiveEmailHandler::class);
        $id = $sub->getId();
        self::assertNotNull($id);
        $handler(new SendEventLiveEmail($id));

        $messages = CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST);
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    public function testLiveEmailIsStyledAndEmbedsHero(): void
    {
        [$sub] = $this->seed('styled-live-hero', true);

        $email = $this->invoke($sub);
        $html = (string) $email->getHtmlBody();

        self::assertStringContainsString('#123456', $html);      // background color applied
        self::assertStringContainsString('#abcdef', $html);      // button color applied
        self::assertStringContainsString('cid:', $html);         // hero referenced by CID
        self::assertCount(1, $email->getAttachments());          // inline hero attached
        self::assertStringContainsString('Unsubscribe', (string) $email->getTextBody()); // txt intact
    }

    public function testLiveEmailWithoutBannerHasNoHero(): void
    {
        [$sub] = $this->seed('styled-live-nohero', false);

        $email = $this->invoke($sub);
        $html = (string) $email->getHtmlBody();

        self::assertStringContainsString('#123456', $html);
        self::assertStringNotContainsString('cid:', $html);
        self::assertCount(0, $email->getAttachments());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Functional/Notification/StyledLiveEmailTest.php`
Expected: FAIL — the current `live.html.twig` contains no `#123456`/`cid:` and the handler attaches no inline part.

- [ ] **Step 3: Create the shared email layout**

Create `templates/email/_layout.html.twig`:

```twig
{#- Email-safe layout: table structure + inline styles only (email clients strip
    <style> and ignore CSS custom properties). `style` is always a ResolvedStyle. -#}
{% set bgColor = style.backgroundColor|default('#f4f4f5') %}
{% set fgColor = style.fontColor|default('#18181b') %}
{% set btnColor = style.buttonColor|default('#2563eb') %}
{% set btnFgColor = style.buttonContentColor|default('#ffffff') %}
{% set ctaUrl %}{% block cta_url %}{% endblock %}{% endset %}
{% set ctaLabel %}{% block cta_label %}{% endblock %}{% endset %}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{% block subject %}{% endblock %}</title>
</head>
<body style="margin:0; padding:0; background-color:{{ bgColor }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{{ bgColor }};">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    {% if heroCid is defined and heroCid %}
                        <tr>
                            <td style="padding:0;">
                                <img src="cid:{{ heroCid }}" alt="{{ eventName }}" width="600" style="display:block; width:100%; max-width:600px; height:auto;">
                            </td>
                        </tr>
                    {% endif %}
                    <tr>
                        <td style="padding:32px 32px 8px 32px; font-family:Arial,Helvetica,sans-serif; color:{{ fgColor }};">
                            <h1 style="margin:0 0 16px 0; font-size:22px; line-height:1.3; color:{{ fgColor }};">{% block heading %}{% endblock %}</h1>
                            {% block content %}{% endblock %}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 24px 32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:6px; background-color:{{ btnColor }};">
                                        <a href="{{ ctaUrl|trim }}" style="display:inline-block; padding:12px 24px; font-family:Arial,Helvetica,sans-serif; font-size:16px; line-height:1; color:{{ btnFgColor }}; text-decoration:none;">{{ ctaLabel|trim }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px 32px; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.5; color:#71717a;">
                            {% block footer %}
                                Don't want these emails? <a href="{{ unsubscribeUrl }}" style="color:#71717a;">Unsubscribe</a>.
                            {% endblock %}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 4: Convert `live.html.twig` to extend the layout**

Replace the entire contents of `templates/email/event-notification/live.html.twig` with:

```twig
{% extends 'email/_layout.html.twig' %}

{% block cta_url %}{{ eventUrl }}{% endblock %}
{% block cta_label %}View the photos{% endblock %}
{% block subject %}Photos from {{ eventName }} are live{% endblock %}
{% block heading %}Photos from {{ eventName }} are live{% endblock %}
{% block content %}
    <p style="margin:0 0 12px 0; font-size:16px; line-height:1.5;">Hi,</p>
    <p style="margin:0; font-size:16px; line-height:1.5;">The photos from <strong>{{ eventName }}</strong> are now available.</p>
{% endblock %}
```

- [ ] **Step 5: Route the handler through the factory**

In `src/MessageHandler/SendEventLiveEmailHandler.php`:

Add the import:
```php
use App\Service\Mail\EventStyledEmailFactory;
```

Add the constructor dependency (append to the promoted-property list):
```php
        private EventStyledEmailFactory $styledEmailFactory,
```

Replace the `$email = new TemplatedEmail() ...` assignment (currently around lines 63-73) with:
```php
        $email = $this->styledEmailFactory->create(
            $event,
            'email/event-notification/live.html.twig',
            'email/event-notification/live.txt.twig',
            [
                'eventName' => $event->getName(),
                'eventUrl' => $eventUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ],
        )
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Photos from %s are live', $event->getName()));
```

(The `use Symfony\Bridge\Twig\Mime\TemplatedEmail;` import is still used as the `create()` return type flows through `->from()` etc.; leave it. If phpstan/phpcs flags it as unused, remove it.)

- [ ] **Step 6: Run the functional test and confirm it passes**

Run: `vendor/bin/phpunit tests/Functional/Notification/StyledLiveEmailTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

Stage `templates/email/_layout.html.twig`, `templates/email/event-notification/live.html.twig`, `src/MessageHandler/SendEventLiveEmailHandler.php`, `tests/Functional/Notification/StyledLiveEmailTest.php`. Proposed message:
`119 - style the 'photos are live' email via shared layout + inline hero`

---

### Task 3: Styled confirmation email

Converts the confirm template to the layout and routes the controller's confirmation send through the factory.

**Files:**
- Modify: `templates/email/event-notification/confirm.html.twig`
- Modify: `src/Controller/Public/EventNotificationController.php`
- Create: `tests/Functional/Public/StyledConfirmEmailTest.php`

**Interfaces:**
- Consumes: `EventStyledEmailFactory::create(...)` (Task 1); the layout blocks (Task 2).

- [ ] **Step 1: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;

final class StyledConfirmEmailTest extends WebTestCase
{
    private const string ORGANIZER_MAIL_HOST = '93.184.216.34';

    protected function setUp(): void
    {
        CapturedMail::reset();
    }

    public function testConfirmationEmailIsStyledAndEmbedsHero(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $slug = 'styled-confirm';
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
            name: 'Styled Confirm',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();
        $event->getStyle()->setBackgroundColor('#123456');
        $event->getStyle()->setButtonColor('#abcdef');

        /** @var FilesystemOperator $banners */
        $banners = self::getContainer()->get('event_banners_storage');
        $filename = 'event-' . $slug . '.jpg';
        $banners->write($filename, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg'));
        $event->setBannerFilename($filename);

        $em->persist($event);
        $em->flush();

        $client->request(
            Request::METHOD_POST,
            '/e/' . $slug . '/notify',
            ['email' => 'visitor@example.com', 'website' => ''],
        );
        self::assertResponseIsSuccessful();

        $messages = CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST);
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('#123456', $html);
        self::assertStringContainsString('#abcdef', $html);
        self::assertStringContainsString('cid:', $html);
        self::assertCount(1, $email->getAttachments());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/StyledConfirmEmailTest.php`
Expected: FAIL — the current `confirm.html.twig` has no `#123456`/`cid:` and the controller attaches no inline part.

- [ ] **Step 3: Convert `confirm.html.twig` to extend the layout**

Replace the entire contents of `templates/email/event-notification/confirm.html.twig` with:

```twig
{% extends 'email/_layout.html.twig' %}

{% block cta_url %}{{ confirmUrl }}{% endblock %}
{% block cta_label %}Confirm my email{% endblock %}
{% block subject %}Confirm notifications for {{ eventName }}{% endblock %}
{% block heading %}Confirm notifications for {{ eventName }}{% endblock %}
{% block content %}
    <p style="margin:0 0 12px 0; font-size:16px; line-height:1.5;">Hi,</p>
    <p style="margin:0; font-size:16px; line-height:1.5;">You asked to be notified when photos from <strong>{{ eventName }}</strong> are live. Confirm within 7 days:</p>
{% endblock %}
{% block footer %}
    Didn't request this? Ignore this email, or <a href="{{ unsubscribeUrl }}" style="color:#71717a;">unsubscribe</a>.
{% endblock %}
```

- [ ] **Step 4: Route the confirmation send through the factory**

In `src/Controller/Public/EventNotificationController.php`:

Add the import:
```php
use App\Service\Mail\EventStyledEmailFactory;
```

Add the constructor dependency (append to the promoted-property list, keeping `readonly`):
```php
        private readonly EventStyledEmailFactory $styledEmailFactory,
```

In `sendConfirmation()`, replace the `$email = new TemplatedEmail() ...` assignment (currently around lines 183-193) with:
```php
        $email = $this->styledEmailFactory->create(
            $event,
            'email/event-notification/confirm.html.twig',
            'email/event-notification/confirm.txt.twig',
            [
                'eventName' => $event->getName(),
                'confirmUrl' => $confirmUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ],
        )
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Confirm notifications for %s', $event->getName()));
```

(Leave the `TemplatedEmail` import; remove only if the quality gate flags it unused.)

- [ ] **Step 5: Run the functional test and confirm it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/StyledConfirmEmailTest.php`
Expected: PASS.

- [ ] **Step 6: Run the existing notification suite (regression)**

Run: `vendor/bin/phpunit tests/Functional/Public/EventNotificationControllerTest.php tests/Functional/Notification`
Expected: PASS — existing confirm/fan-out behavior unchanged (still one message per confirmed subscriber, tokens/unsubscribe intact).

- [ ] **Step 7: Commit**

Stage `templates/email/event-notification/confirm.html.twig`, `src/Controller/Public/EventNotificationController.php`, `tests/Functional/Public/StyledConfirmEmailTest.php`. Proposed message:
`119 - style the notification confirmation email via shared layout + inline hero`

---

### Task 4: Full-suite green + docs

**Files:**
- Modify: `CLAUDE.md` (one line in the per-organizer mail section)

- [ ] **Step 1: Run the full quality gate**

Run: `vendor/bin/grumphp run`
Expected: all tasks green — phpstan L10, phpcs (PSR-12), phpmnd, phpcpd, rector, securitychecker_roave, `doctrine:schema:validate`, and the full PHPUnit suite. Fix any finding at its source (no suppressions). Common nits to expect: an unused `TemplatedEmail` import in the two send sites (remove if flagged), and phpstan wanting the `create()` `@param array<string, mixed>` docblock (already present).

- [ ] **Step 2: Note the styled-email behavior in `CLAUDE.md`**

In `CLAUDE.md`, at the end of the "Per-organizer mail transport" paragraph (the sentence describing the #77 visitor notification), append:
```
The two event-context emails (confirmation + "photos are live") are rendered through `App\Service\Mail\EventStyledEmailFactory`, which applies the event's `StyleResolver` colors as inline styles via the shared `templates/email/_layout.html.twig` and embeds the event banner as an inline CID image when set (a missing banner is logged and skipped). Platform emails (invitations, password reset, mail-config verify) are intentionally left unstyled.
```

- [ ] **Step 3: Commit**

Stage `CLAUDE.md`. Proposed message:
`119 - document styled event emails (EventStyledEmailFactory + email layout)`

---

## Notes for the implementer

- **Why `extends`, not inline HTML:** the two child templates share one `_layout.html.twig`. Colors, hero, container, and footer live once in the layout; children only supply copy + CTA target/label via blocks (`cta_url`/`cta_label` are captured into variables with `{% set %}{% block %}{% endset %}`, which is Twig-safe regardless of set-visibility quirks). phpcpd does not scan Twig, so template similarity is not a gate concern — but centralizing keeps the two emails consistent.
- **Inline hero mechanics:** the factory adds a `DataPart($bytes, 'event-hero', 'image/jpeg')->asInline()` and passes its generated `getContentId()` into the context as `heroCid`; the layout renders `<img src="cid:{{ heroCid }}">`. `RenderingMailer` runs `BodyRenderer::render()` before transport send, which both renders the body and materializes the inline part — verified against the existing send path, no transport change.
- **Style comes from `StyleResolver::resolve($event)`** — the event → collection → organizer-profile cascade. `glowEnabled`/`backgroundCss()` (radial-gradient) is intentionally NOT used in email; the flat `backgroundColor` is applied instead, since gradients don't render reliably across email clients.
- **Fallback colors** are Twig `|default(...)` literals in the layout (`#f4f4f5` bg, `#18181b` text, `#2563eb` button, `#ffffff` button text) so an event with no custom style still renders a clean, intentional email.
- **Test mail capture:** organizer sends land in `CapturedMail`, keyed by the DSN's *resolved* host. With DSN `smtp.example-organizer.test`, that host is `93.184.216.34` (test `PrebakedDnsResolver`). The captured `getOriginalMessage()` is the already-rendered `TemplatedEmail`, so `getHtmlBody()`/`getTextBody()`/`getAttachments()` are populated.
- **Keep the issue in sync:** after merge, add a short comment on #119 linking this plan and the spec.
```
