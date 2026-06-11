# Auto-Generate Event Slug — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-generate `Event.slug` server-side on create in the shape `<slugified-name>-<6-char-token>`, remove the slug input from the admin form, and keep slugs immutable after creation.

**Architecture:** A pure `EventSlugGenerator` service produces the slug. A Doctrine entity listener registered via `#[AsEntityListener]` calls the generator on `prePersist` if and only if the entity's slug is empty — this preserves all existing test fixtures that pass explicit slugs and keeps slug-policy logic out of the entity.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, `symfony/string` (AsciiSlugger, already a transitive dependency), PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-06-11-event-slug-autogenerate-design.md`

**Branch:** `feature/35-auto-generate-event-slug` (already created)

**GrumPHP gates to satisfy:**
- Branch name regex `^(feature|hotfix|bugfix|release)/\d+-` — satisfied.
- Every commit message contains `#35` or starts with `35 -`.
- `phpstan` level 10, `phpcs` PSR-12, `phpmnd` (no magic numbers in `src/`), `phpcpd`, `rector`, `securitychecker_roave`, `doctrine:schema:validate`.

---

## Task 1: `EventSlugGenerator` service

**Files:**
- Create: `src/Service/EventSlugGenerator.php`
- Create: `tests/Unit/Service/EventSlugGeneratorTest.php`

- [ ] **Step 1.1: Write the failing unit test**

Create `tests/Unit/Service/EventSlugGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EventSlugGenerator;
use PHPUnit\Framework\TestCase;

final class EventSlugGeneratorTest extends TestCase
{
    private const string SHAPE_REGEX = '/^[a-z0-9]+(-[a-z0-9]+)*-[a-z0-9]{6}$/';

    public function testShapeIsSlugifiedNameDashSixCharToken(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('Summer Fest 2026');

        self::assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
        self::assertStringStartsWith('summer-fest-2026-', $slug);
    }

    public function testAsciiFoldsDiacritics(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('Café Olé');

        self::assertStringStartsWith('cafe-ole-', $slug);
        self::assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
    }

    public function testStripsPunctuationAndCollapsesSeparators(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('Summer  Fest!!!  ---  2026');

        self::assertStringStartsWith('summer-fest-2026-', $slug);
    }

    public function testFallsBackToLiteralEventWhenNameHasNoAlphanumerics(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('!!!---???');

        self::assertStringStartsWith('event-', $slug);
        self::assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
    }

    public function testFallsBackToLiteralEventWhenNameIsEmpty(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('');

        self::assertStringStartsWith('event-', $slug);
        self::assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
    }

    public function testBaseIsCappedAt60CharsForMultiWordName(): void
    {
        $generator = new EventSlugGenerator();
        $name = str_repeat('alpha beta ', 30); // 330 chars, many separators

        $slug = $generator->generate($name);

        $base = substr($slug, 0, strrpos($slug, '-'));
        self::assertLessThanOrEqual(60, strlen($base), 'base must be ≤ 60 chars');
        self::assertStringEndsNotWith('-', $base, 'base must not end with separator');
    }

    public function testBaseIsHardTruncatedFor200CharSingleWord(): void
    {
        $generator = new EventSlugGenerator();
        $name = str_repeat('a', 200);

        $slug = $generator->generate($name);

        $base = substr($slug, 0, strrpos($slug, '-'));
        self::assertSame(60, strlen($base));
        self::assertSame(str_repeat('a', 60), $base);
    }

    public function testTokenCharsetIsLowercaseAlphanumeric(): void
    {
        $generator = new EventSlugGenerator();

        for ($i = 0; $i < 100; $i++) {
            $slug = $generator->generate('Test');
            $token = substr($slug, -6);
            self::assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $token);
        }
    }

    public function testGeneratesDistinctSlugsForSameNameUnder1000Draws(): void
    {
        $generator = new EventSlugGenerator();
        $slugs = [];

        for ($i = 0; $i < 1000; $i++) {
            $slugs[] = $generator->generate('Summer Fest');
        }

        self::assertCount(1000, array_unique($slugs));
    }
}
```

- [ ] **Step 1.2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/EventSlugGeneratorTest.php -v`
Expected: FAIL — `App\Service\EventSlugGenerator` class does not exist.

- [ ] **Step 1.3: Implement the generator**

Create `src/Service/EventSlugGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;

final class EventSlugGenerator
{
    private const int BASE_MAX_LENGTH = 60;
    private const int TOKEN_LENGTH = 6;
    private const string TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const int TOKEN_ALPHABET_LAST_INDEX = 35;
    private const string EMPTY_BASE_FALLBACK = 'event';

    public function generate(string $name): string
    {
        $base = $this->slugifyBase($name);

        return $base . '-' . $this->randomToken();
    }

    private function slugifyBase(string $name): string
    {
        $slugger = new AsciiSlugger();
        $slugged = strtolower((string) $slugger->slug($name, '-'));

        $sanitized = (string) preg_replace('/[^a-z0-9-]+/', '-', $slugged);
        $collapsed = (string) preg_replace('/-+/', '-', $sanitized);
        $trimmed = trim($collapsed, '-');

        if ($trimmed === '') {
            return self::EMPTY_BASE_FALLBACK;
        }

        if (strlen($trimmed) > self::BASE_MAX_LENGTH) {
            $trimmed = rtrim(substr($trimmed, 0, self::BASE_MAX_LENGTH), '-');
        }

        return $trimmed === '' ? self::EMPTY_BASE_FALLBACK : $trimmed;
    }

    private function randomToken(): string
    {
        $token = '';
        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= self::TOKEN_ALPHABET[random_int(0, self::TOKEN_ALPHABET_LAST_INDEX)];
        }

        return $token;
    }
}
```

- [ ] **Step 1.4: Run the unit test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/EventSlugGeneratorTest.php -v`
Expected: all tests PASS (9 tests).

- [ ] **Step 1.5: Run phpstan and phpcs on the new files**

Run: `vendor/bin/phpstan analyse src/Service/EventSlugGenerator.php tests/Unit/Service/EventSlugGeneratorTest.php`
Expected: no errors.

Run: `vendor/bin/phpcs src/Service/EventSlugGenerator.php tests/Unit/Service/EventSlugGeneratorTest.php`
Expected: no errors.

- [ ] **Step 1.6: Commit**

```bash
git add src/Service/EventSlugGenerator.php tests/Unit/Service/EventSlugGeneratorTest.php
git commit -m "35 - add EventSlugGenerator service"
```

---

## Task 2: `EventSlugListener` Doctrine entity listener

**Files:**
- Create: `src/EventListener/EventSlugListener.php`
- Create: `tests/Unit/EventListener/EventSlugListenerTest.php`

- [ ] **Step 2.1: Write the failing unit test**

Create `tests/Unit/EventListener/EventSlugListenerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\Event;
use App\Entity\User;
use App\EventListener\EventSlugListener;
use App\Service\EventSlugGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\TestCase;

final class EventSlugListenerTest extends TestCase
{
    public function testGeneratesSlugWhenEntitySlugIsEmpty(): void
    {
        $generator = $this->createMock(EventSlugGenerator::class);
        $generator->expects(self::once())
            ->method('generate')
            ->with('Summer Fest')
            ->willReturn('summer-fest-abc123');

        $listener = new EventSlugListener($generator);
        $event = new Event('', 'Summer Fest', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $args = new PrePersistEventArgs($event, $this->createStub(EntityManagerInterface::class));

        $listener->prePersist($event, $args);

        self::assertSame('summer-fest-abc123', $event->getSlug());
    }

    public function testDoesNotTouchSlugWhenAlreadySet(): void
    {
        $generator = $this->createMock(EventSlugGenerator::class);
        $generator->expects(self::never())->method('generate');

        $listener = new EventSlugListener($generator);
        $event = new Event('existing-slug', 'Summer Fest', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $args = new PrePersistEventArgs($event, $this->createStub(EntityManagerInterface::class));

        $listener->prePersist($event, $args);

        self::assertSame('existing-slug', $event->getSlug());
    }
}
```

- [ ] **Step 2.2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/EventListener/EventSlugListenerTest.php -v`
Expected: FAIL — `App\EventListener\EventSlugListener` does not exist.

- [ ] **Step 2.3: Implement the listener**

Create `src/EventListener/EventSlugListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Event;
use App\Service\EventSlugGenerator;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;

#[AsEntityListener(event: Events::prePersist, entity: Event::class)]
final class EventSlugListener
{
    public function __construct(private readonly EventSlugGenerator $generator)
    {
    }

    public function prePersist(Event $event, PrePersistEventArgs $args): void
    {
        if ($event->getSlug() === '') {
            $event->setSlug($this->generator->generate($event->getName()));
        }
    }
}
```

- [ ] **Step 2.4: Run the unit test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/EventListener/EventSlugListenerTest.php -v`
Expected: 2 PASS.

- [ ] **Step 2.5: Run phpstan and phpcs on the new files**

Run: `vendor/bin/phpstan analyse src/EventListener/EventSlugListener.php tests/Unit/EventListener/EventSlugListenerTest.php`
Expected: no errors.

Run: `vendor/bin/phpcs src/EventListener/EventSlugListener.php tests/Unit/EventListener/EventSlugListenerTest.php`
Expected: no errors.

- [ ] **Step 2.6: Commit**

```bash
git add src/EventListener/EventSlugListener.php tests/Unit/EventListener/EventSlugListenerTest.php
git commit -m "35 - add EventSlugListener to auto-fill empty slugs on persist"
```

---

## Task 3: Add the immutability test for `Event::setName`

**Files:**
- Modify: `tests/Unit/Entity/EventTest.php`

This is a documentation-style test that locks in the invariant: changing `name` does not touch `slug`. The listener doesn't fire on `setName` directly, but the test documents intent and guards against a future bad change (e.g., somebody adding a `preUpdate` hook).

- [ ] **Step 3.1: Add the failing test**

Append to `tests/Unit/Entity/EventTest.php` (before the closing brace):

```php
    public function testSetNameDoesNotChangeSlug(): void
    {
        $event = new Event('summer-fest-abc123', 'Summer Fest', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));

        $event->setName('Winter Fest');

        self::assertSame('summer-fest-abc123', $event->getSlug());
    }
```

- [ ] **Step 3.2: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventTest.php -v`
Expected: PASS — this is documenting existing behavior, no production change needed.

- [ ] **Step 3.3: Commit**

```bash
git add tests/Unit/Entity/EventTest.php
git commit -m "35 - assert slug is immutable on Event::setName"
```

---

## Task 4: Remove `slug` input from `EventType`

**Files:**
- Modify: `src/Form/EventType.php`

Template `templates/admin/event/form.html.twig` uses `{{ form_widget(form) }}` which renders all bound fields generically — no template change is needed once the field is dropped from the form class.

- [ ] **Step 4.1: Remove the slug field**

In `src/Form/EventType.php`, find this block:

```php
        $builder
            ->add('slug', TextType::class, [
                'help' => 'Used in the public QR URL: /e/{slug}',
            ])
            ->add('name', TextType::class)
```

Replace with:

```php
        $builder
            ->add('name', TextType::class)
```

- [ ] **Step 4.2: Remove the now-unused `TextType` import if no other field uses it**

Check `src/Form/EventType.php` after Step 4.1. If `TextType::class` no longer appears in the file, also remove this line from the imports:

```php
use Symfony\Component\Form\Extension\Core\Type\TextType;
```

If `TextType` is still referenced (e.g., by `name`), leave the import alone. (It is — `name` uses `TextType`. So the import stays.)

- [ ] **Step 4.3: Verify existing unit tests still pass**

Run: `vendor/bin/phpunit tests/Unit -v`
Expected: all PASS.

- [ ] **Step 4.4: Commit**

```bash
git add src/Form/EventType.php
git commit -m "35 - remove slug field from EventType form"
```

---

## Task 5: Functional test — create form has no slug input, auto-generates slug, edit preserves slug

**Files:**
- Create: `tests/Functional/Admin/EventSlugTest.php`

- [ ] **Step 5.1: Write the functional test**

Create `tests/Functional/Admin/EventSlugTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventSlugTest extends WebTestCase
{
    public function testNewFormHasNoSlugInput(): void
    {
        $client = self::createClient();
        $alice = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="event[slug]"]'));
        self::assertCount(0, $crawler->filter('textarea[name="event[slug]"]'));
    }

    public function testCreatePopulatesSlugAutomatically(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $alice = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');
        $form = $crawler->selectButton('Create')->form([
            'event[name]' => 'My Brand New Event',
            'event[date]' => '2026-08-01',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events = $container->get(EventRepository::class);
        $created = $events->findOneBy(['name' => 'My Brand New Event']);
        self::assertNotNull($created);
        self::assertMatchesRegularExpression(
            '/^my-brand-new-event-[a-z0-9]{6}$/',
            $created->getSlug(),
        );
    }

    public function testEditFormHasNoSlugInputAndEditDoesNotChangeSlug(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $alice = $this->seedOrganizer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $event = new Event('legacy-slug-xyz999', 'Original Name', new DateTimeImmutable('2026-07-15'), $alice);
        $em->persist($event);
        $em->flush();
        $eventId = (int) $event->getId();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="event[slug]"]'));

        $form = $crawler->selectButton('Save')->form([
            'event[name]' => 'Renamed Event',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        $em->clear();
        /** @var EventRepository $events */
        $events = $container->get(EventRepository::class);
        $reloaded = $events->find($eventId);
        self::assertNotNull($reloaded);
        self::assertSame('legacy-slug-xyz999', $reloaded->getSlug(), 'slug must not change on edit');
        self::assertSame('Renamed Event', $reloaded->getName());
    }

    private function seedOrganizer(): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $em->persist($alice);
        $em->flush();

        return $alice;
    }
}
```

- [ ] **Step 5.2: Prepare the test database (host)**

Run: `bin/console doctrine:database:create --env=test --if-not-exists`
Run: `bin/console doctrine:migrations:migrate --env=test --no-interaction`
Expected: schema present in `eventfotos_test`.

- [ ] **Step 5.3: Run the functional test**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventSlugTest.php -v`
Expected: 3 PASS. If any test fails on the asserted button label, check `templates/admin/event/form.html.twig` for the button text (`Create` for new, `Save` for edit — verified at plan-write time).

- [ ] **Step 5.4: Run phpstan and phpcs on the new file**

Run: `vendor/bin/phpstan analyse tests/Functional/Admin/EventSlugTest.php`
Run: `vendor/bin/phpcs tests/Functional/Admin/EventSlugTest.php`
Expected: no errors.

- [ ] **Step 5.5: Commit**

```bash
git add tests/Functional/Admin/EventSlugTest.php
git commit -m "35 - functional test for slug auto-generation and edit immutability"
```

---

## Task 6: Full quality gate run + push

- [ ] **Step 6.1: Run the full GrumPHP suite**

Run: `vendor/bin/grumphp run`
Expected: PASS — all of phpstan, phpcs, phpmnd, phpcpd, rector, securitychecker_roave, doctrine:schema:validate.

If `phpmnd` flags numeric literals in `EventSlugGenerator.php`: all numeric constants (`60`, `6`, `35`) are already extracted as `private const int`. If `phpmnd` still complains about something, extract that literal as a `private const` and re-commit.

If `phpcpd` flags duplication: this should not happen — the test methods reuse the `seedOrganizer()` helper. If it does, factor any duplicated logic into a private helper.

- [ ] **Step 6.2: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: all PASS, no deprecations/notices/warnings (PHPUnit is configured with `failOn*` for all three).

- [ ] **Step 6.3: Push the branch**

```bash
git push -u origin feature/35-auto-generate-event-slug
```

- [ ] **Step 6.4: Open the PR**

```bash
gh pr create --title "35 - auto-generate event slug on create" --body "$(cat <<'EOF'
## Summary

- Adds `EventSlugGenerator` that produces `<slugified-name>-<6-char-token>` from an event name.
- Adds `EventSlugListener` (Doctrine `prePersist` entity listener) that fills `Event.slug` if and only if the entity's slug is empty — existing test fixtures with explicit slugs pass through untouched.
- Removes the `slug` input from `EventType` (both create and edit).
- Locks slug immutability with a unit test on `Event::setName` and a functional test on the edit form.

Closes #35.

## Test plan

- [ ] `vendor/bin/grumphp run` passes locally
- [ ] `vendor/bin/phpunit` passes locally
- [ ] Manually create an event in the admin form and confirm `/e/<generated-slug>` resolves
- [ ] Rename an event and confirm the public URL still resolves

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Spec-coverage self-check

| Acceptance criterion (spec / issue) | Task |
|---|---|
| Admin create form no longer shows slug input | Task 4 + Task 5.1 (`testNewFormHasNoSlugInput`) |
| On create, slug is auto-populated in shape `<base>-<6-char>` | Task 1 + Task 2 + Task 5.1 (`testCreatePopulatesSlugAutomatically`) |
| Edit form has no slug input | Task 4 + Task 5.1 (`testEditFormHasNoSlugInputAndEditDoesNotChangeSlug`) |
| Renaming does not alter slug | Task 3 (unit) + Task 5.1 (functional) |
| Existing events keep their slugs | Task 2 (`testDoesNotTouchSlugWhenAlreadySet`) + Task 5.1 (fixture with `'legacy-slug-xyz999'`) |
| `events.slug` unique constraint unchanged | No-op; verified by `grumphp run` → `doctrine:schema:validate` |
| Unit test covers shape, idempotence, no-rename-side-effect | Task 1 + Task 2 + Task 3 |
| Functional test covers admin create+edit round-trip with no slug field | Task 5 |
