# Bib Governance + Suppress-List Implementation Plan (#109 — Plan A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-event bib-indexing governance — an off-by-default, organizer-attested toggle plus a per-event bib suppress-list and an organizer de-index action — so the #109 bib-search launch gate can be satisfied before any recognition ships.

**Architecture:** A boolean flag on `Event` with a domain enable/disable API (mirrors `notificationsEnabled`), gated in `EventType` by an unmapped attestation checkbox enforced in a `FormEvents::SUBMIT` listener. A new `BibSuppression` entity (ManyToOne `Event`, `onDelete: CASCADE`, unique `(event_id, bib_number)`) records objected-to bibs independently of photos so it survives re-ingest. A CSRF-protected, voter-gated admin action inserts suppressions and is audited. No ML/inference dependency in this plan.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 / DBAL 4, PostgreSQL 16, Twig, PHPUnit 13 (+ `dama/doctrine-test-bundle`).

## Global Constraints

- PHP 8.5 / Symfony 8 / Doctrine ORM 3 — `declare(strict_types=1)` in every file; PHP attributes only (no annotations).
- Quality gates (GrumPHP, local + CI): **phpstan level 10**, **phpcs PSR-12**, **phpmnd** (no magic numbers in `src/` — use class constants), **phpcpd**, **rector**, **securitychecker**, and **`doctrine:schema:validate`** must all pass.
- **Migrations only via `bin/console doctrine:migrations:diff`** — never hand-write DDL. Edit only `getDescription()` afterward.
- Branch name must match `^(feature|hotfix|bugfix|release)/\d+-` (e.g. `feature/109-bib-governance`). `main`/`develop`/`master` are blacklisted for direct commits. Commit messages must contain the issue number.
- **Claude will not run git commits** (project rule). The `Commit` step in each task is the command for the human to run; the implementing agent stops before committing and hands the message to the user.
- Authorization via voters using constants (`EventVoter::EDIT`), never raw strings. State-changing POSTs must validate CSRF via `$this->isCsrfTokenValid(...)`.
- Governed by `docs/superpowers/specs/2026-07-15-109-attribute-tagging-build-design.md` and the boundary spec `docs/superpowers/specs/2026-07-15-109-indexable-attribute-boundary-design.md`.

**Dependency note on `PhotoAttribute`:** the boundary requires de-index to also *delete existing bib tags*. `PhotoAttribute` is introduced in **Plan C**; in Plan A there are no tags yet (extraction does not exist), so de-index inserts the suppression only. Plan C extends this same action to also delete matching `PhotoAttribute` rows. The launch gate stays satisfied because search (Plan D) ships after Plan C.

---

### Task 1: `Event.bibIndexingEnabled` domain flag

**Files:**
- Modify: `src/Entity/Event.php` (add column near line 72; add domain methods near line 267)
- Test: `tests/Unit/Entity/EventBibIndexingTest.php`

**Interfaces:**
- Produces: `Event::enableBibIndexing(): void`, `Event::disableBibIndexing(): void`, `Event::isBibIndexingEnabled(): bool` (default `false`).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Entity/EventBibIndexingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventBibIndexingTest extends TestCase
{
    private function makeEvent(): Event
    {
        return new Event(
            'run-2026',
            'City Run 2026',
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $this->createMock(User::class),
        );
    }

    public function testBibIndexingIsDisabledByDefault(): void
    {
        self::assertFalse($this->makeEvent()->isBibIndexingEnabled());
    }

    public function testEnableAndDisableBibIndexing(): void
    {
        $event = $this->makeEvent();

        $event->enableBibIndexing();
        self::assertTrue($event->isBibIndexingEnabled());

        $event->disableBibIndexing();
        self::assertFalse($event->isBibIndexingEnabled());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventBibIndexingTest.php`
Expected: FAIL — `Call to undefined method App\Entity\Event::isBibIndexingEnabled()`.

- [ ] **Step 3: Add the column**

In `src/Entity/Event.php`, after the `retainOriginals` property (line 72-73), add:

```php
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $bibIndexingEnabled = false;
```

- [ ] **Step 4: Add the domain methods**

In `src/Entity/Event.php`, after `areNotificationsEnabled()` (line 267), add:

```php
    public function enableBibIndexing(): void
    {
        $this->bibIndexingEnabled = true;
    }

    public function disableBibIndexing(): void
    {
        $this->bibIndexingEnabled = false;
    }

    public function isBibIndexingEnabled(): bool
    {
        return $this->bibIndexingEnabled;
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventBibIndexingTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Entity/Event.php tests/Unit/Entity/EventBibIndexingTest.php
git commit -m "109 - add off-by-default bibIndexingEnabled flag to Event"
```

---

### Task 2: `BibSuppression` entity + repository + migration

**Files:**
- Create: `src/Entity/BibSuppression.php`
- Create: `src/Repository/BibSuppressionRepository.php`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Test: `tests/Integration/Repository/BibSuppressionRepositoryTest.php`

**Interfaces:**
- Produces:
  - `new BibSuppression(Event $event, string $bibNumber)`; `getId(): ?int`, `getEvent(): Event`, `getBibNumber(): string`, `getCreatedAt(): DateTimeImmutable`.
  - `BibSuppressionRepository::isSuppressed(Event $event, string $bibNumber): bool`
  - `BibSuppressionRepository::suppressedBibNumbers(Event $event): list<string>` (consumed by Plan C's bib gate)

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Repository/BibSuppressionRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\BibSuppression;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\BibSuppressionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BibSuppressionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private BibSuppressionRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container  = self::getContainer();
        $this->em   = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(BibSuppressionRepository::class);
    }

    private function persistEvent(string $slug): Event
    {
        $user = new User('org-' . $slug . '@example.test', 'Org');
        $this->em->persist($user);

        $event = new Event(
            $slug,
            'Event ' . $slug,
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $user,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testIsSuppressedAndListing(): void
    {
        $event = $this->persistEvent('run-a');

        self::assertFalse($this->repo->isSuppressed($event, '1423'));

        $this->em->persist(new BibSuppression($event, '1423'));
        $this->em->flush();

        self::assertTrue($this->repo->isSuppressed($event, '1423'));
        self::assertSame(['1423'], $this->repo->suppressedBibNumbers($event));
    }

    public function testUniqueConstraintPerEventAndBib(): void
    {
        $event = $this->persistEvent('run-b');
        $this->em->persist(new BibSuppression($event, '77'));
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist(new BibSuppression($event, '77'));
        $this->em->flush();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Repository/BibSuppressionRepositoryTest.php`
Expected: FAIL — class `App\Entity\BibSuppression` not found.

- [ ] **Step 3: Create the entity**

Create `src/Entity/BibSuppression.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BibSuppressionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BibSuppressionRepository::class)]
#[ORM\Table(name: 'bib_suppressions')]
#[ORM\UniqueConstraint(name: 'uniq_bib_suppression_event_bib', columns: ['event_id', 'bib_number'])]
class BibSuppression
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Event $event,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $bibNumber,
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getBibNumber(): string
    {
        return $this->bibNumber;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 4: Create the repository**

Create `src/Repository/BibSuppressionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BibSuppression;
use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BibSuppression>
 */
final class BibSuppressionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BibSuppression::class);
    }

    public function isSuppressed(Event $event, string $bibNumber): bool
    {
        return $this->count(['event' => $event, 'bibNumber' => $bibNumber]) > 0;
    }

    /**
     * @return list<string>
     */
    public function suppressedBibNumbers(Event $event): array
    {
        /** @var list<array{bibNumber: string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('b.bibNumber')
            ->where('b.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): string => $r['bibNumber'], $rows);
    }
}
```

- [ ] **Step 5: Generate the migration**

Run:
```bash
bin/console doctrine:migrations:diff
```
Expected: a new `migrations/VersionYYYYMMDDHHMMSS.php` creating `bib_suppressions` (id, event_id FK `ON DELETE CASCADE`, bib_number, created_at) plus the unique index. Do **not** hand-edit the DDL; optionally refine `getDescription()` to `"#109 bib suppress-list table"`.

- [ ] **Step 6: Migrate the test database and run the test**

Run:
```bash
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test --no-interaction
vendor/bin/phpunit tests/Integration/Repository/BibSuppressionRepositoryTest.php
```
Expected: PASS (2 tests). Then confirm mapping is in sync: `bin/console doctrine:schema:validate` → "database schema is in sync with the mapping files".

- [ ] **Step 7: Commit**

```bash
git add src/Entity/BibSuppression.php src/Repository/BibSuppressionRepository.php migrations/ tests/Integration/Repository/BibSuppressionRepositoryTest.php
git commit -m "109 - add per-event BibSuppression entity + repository (survives re-ingest)"
```

---

### Task 3: Attested bib-indexing toggle in the event form

**Files:**
- Modify: `src/Form/EventType.php` (add fields ~line 150; prefill ~line 206; new SUBMIT listener registered ~line 174)
- Modify: `templates/admin/event/form.html.twig` (render after line 45)
- Modify: `src/Controller/Admin/EventController.php` (record change/attestation in `edit()`, ~line 199-223)
- Test: `tests/Functional/Admin/BibIndexingToggleTest.php`

**Interfaces:**
- Consumes: `Event::enableBibIndexing()/disableBibIndexing()/isBibIndexingEnabled()` (Task 1).
- Produces: form fields `bibIndexingEnabled` (unmapped checkbox) and `bibIndexingAttestation` (unmapped checkbox); enabling requires attestation on the enabling transition.

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Admin/BibIndexingToggleTest.php`. Adapt the login helper / fixture creation to the project's existing functional-test conventions (look at a sibling test in `tests/Functional/Admin/` for how a `ROLE_ORGANIZER` user and an owned `Event` are created and how `loginUser()` is used):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BibIndexingToggleTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeOrganizerWithEvent(): array
    {
        $user = new User('owner@example.test', 'Owner');
        $user->setRoles(['ROLE_ORGANIZER']);
        // Use the project's password hasher helper if the User requires a password; see sibling tests.
        $this->em->persist($user);

        $event = new Event(
            'bib-run',
            'Bib Run',
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $user,
        );
        $this->em->persist($event);
        $this->em->flush();

        return [$user, $event];
    }

    private function submitEdit(Event $event, bool $enable, bool $attest): void
    {
        $crawler = $this->client->request('GET', '/admin/events/' . $event->getId() . '/edit');
        $form    = $crawler->selectButton('Save')->form();

        $form['event[bibIndexingEnabled]']     = $enable;
        $form['event[bibIndexingAttestation]'] = $attest;

        $this->client->submit($form);
    }

    public function testEnablingWithoutAttestationIsRejected(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->submitEdit($event, enable: true, attest: false);

        // Form redisplays (422/200 with error), flag stays off.
        $this->em->clear();
        $reloaded = $this->em->getRepository(Event::class)->find($event->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isBibIndexingEnabled());
    }

    public function testEnablingWithAttestationPersists(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->submitEdit($event, enable: true, attest: true);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Event::class)->find($event->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isBibIndexingEnabled());
    }
}
```

> Note: the exact button label (`Save`) and field name prefix (`event[...]`) must match the real template/form name — verify against the rendered edit page before finalizing.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/BibIndexingToggleTest.php`
Expected: FAIL — the `bibIndexingEnabled` / `bibIndexingAttestation` fields do not exist yet.

- [ ] **Step 3: Add the form fields**

In `src/Form/EventType.php`, after the `retainOriginals` field block (line 159), add:

```php
        $builder->add('bibIndexingEnabled', CheckboxType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => 'Enable bib-number search (races only)',
            'help'     => 'Lets visitors find photos by bib number. Bib numbers are personal '
                . 'data — only enable this if your event terms/registration cover it.',
        ]);

        $builder->add('bibIndexingAttestation', CheckboxType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => 'I confirm my event terms / registration basis cover photo bib-search.',
        ]);
```

- [ ] **Step 4: Register + implement the SUBMIT listener**

In `buildForm()`, alongside the other listeners (after line 174), add:

```php
        $builder->addEventListener(FormEvents::SUBMIT, $this->applyBibIndexingPreference(...));
```

Prefill the toggle from the entity — in `prefillUnmappedFields()` after the notifications block (line 208), add:

```php
        if ($form->has('bibIndexingEnabled')) {
            $form->get('bibIndexingEnabled')->setData($event->isBibIndexingEnabled());
        }
```

Add the new listener method (near `applyNotificationsPreference`, after line 224). It enforces attestation on the enabling transition and otherwise applies the domain change:

```php
    private function applyBibIndexingPreference(FormEvent $formEvent): void
    {
        $event = $formEvent->getData();
        $form  = $formEvent->getForm();
        if (!$event instanceof Event || !$form->has('bibIndexingEnabled')) {
            return;
        }

        $wantEnabled = $form->get('bibIndexingEnabled')->getData() === true;
        $wasEnabled  = $event->isBibIndexingEnabled();

        // Attestation is required to cross from disabled → enabled. Staying enabled or
        // disabling never requires it.
        if ($wantEnabled && !$wasEnabled && $form->get('bibIndexingAttestation')->getData() !== true) {
            $form->get('bibIndexingAttestation')->addError(
                new FormError('Confirm your lawful basis to enable bib-number search.'),
            );

            return;
        }

        if ($wantEnabled) {
            $event->enableBibIndexing();
        } else {
            $event->disableBibIndexing();
        }
    }
```

Add the import at the top of the file with the other `Symfony\Component\Form` uses:

```php
use Symfony\Component\Form\FormError;
```

- [ ] **Step 5: Render the fields in the template**

In `templates/admin/event/form.html.twig`, after line 45 (`{{ form_row(form.retainOriginals) }}`), add:

```twig
                {{ form_row(form.bibIndexingEnabled) }}
                {{ form_row(form.bibIndexingAttestation) }}
```

- [ ] **Step 6: Record the change + attestation in the audit trail**

In `src/Controller/Admin/EventController.php` `edit()` (line 199), capture the flag before `handleRequest`, and record the change after a valid submit. Replace the body around lines 214-220 so it reads:

```php
        $bibBefore = $event->isBibIndexingEnabled();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyBanner($form, $event);
            $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

            if ($event->isBibIndexingEnabled() !== $bibBefore) {
                $this->audit->changed('bib_indexing', $bibBefore, $event->isBibIndexingEnabled());
                if ($event->isBibIndexingEnabled()) {
                    $this->audit->set('bib_indexing_attested', true);
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Event updated.');

            return $this->redirectToRoute('admin_event_index');
        }
```

(The edit route is already `#[Audited(AuditAction::EventEdit, ...)]`; the `changed()`/`set()` context is attached to that entry, giving the attestation trail.)

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/BibIndexingToggleTest.php`
Expected: PASS (2 tests). If the field-name/button-label assumptions were wrong, fix the test selectors (not the feature) and re-run.

- [ ] **Step 8: Commit**

```bash
git add src/Form/EventType.php templates/admin/event/form.html.twig src/Controller/Admin/EventController.php tests/Functional/Admin/BibIndexingToggleTest.php
git commit -m "109 - attested off-by-default bib-indexing toggle on event form (audited)"
```

---

### Task 4: Organizer de-index (bib suppression) admin action

**Files:**
- Modify: `src/Audit/AuditAction.php` (add case ~line 15)
- Modify: `src/Controller/Admin/PhotoController.php` (add action + constructor dep)
- Modify: `templates/admin/event/photos_grid.html.twig` (add a minimal suppress control)
- Test: `tests/Functional/Admin/BibSuppressionActionTest.php`

**Interfaces:**
- Consumes: `BibSuppressionRepository::isSuppressed()` (Task 2), `EventVoter::EDIT`.
- Produces: route `admin_bib_suppress` (`POST /admin/events/{id}/bib-suppressions`); `AuditAction::EventBibSuppress`.

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Admin/BibSuppressionActionTest.php` (reuse the login/fixture conventions from Task 3's sibling tests):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\BibSuppressionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BibSuppressionActionTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeOrganizerWithEvent(): array
    {
        $user = new User('owner2@example.test', 'Owner');
        $user->setRoles(['ROLE_ORGANIZER']);
        $this->em->persist($user);

        $event = new Event(
            'bib-run-2',
            'Bib Run 2',
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $user,
        );
        $this->em->persist($event);
        $this->em->flush();

        return [$user, $event];
    }

    public function testSuppressBibRequiresCsrf(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->client->request('POST', '/admin/events/' . $event->getId() . '/bib-suppressions', [
            'bibNumber' => '1423',
            '_token'    => 'wrong',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuppressBibInsertsRow(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $token = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('suppress_bib_' . $event->getId())->getValue();

        $this->client->request('POST', '/admin/events/' . $event->getId() . '/bib-suppressions', [
            'bibNumber' => '1423',
            '_token'    => $token,
        ]);

        self::assertResponseRedirects();

        $repo = self::getContainer()->get(BibSuppressionRepository::class);
        self::assertTrue($repo->isSuppressed($event, '1423'));
    }

    public function testSuppressingSameBibTwiceIsIdempotent(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $tokenManager = self::getContainer()->get('security.csrf.token_manager');

        foreach (['1423', '1423'] as $bib) {
            $token = $tokenManager->getToken('suppress_bib_' . $event->getId())->getValue();
            $this->client->request('POST', '/admin/events/' . $event->getId() . '/bib-suppressions', [
                'bibNumber' => $bib,
                '_token'    => $token,
            ]);
        }

        $repo = self::getContainer()->get(BibSuppressionRepository::class);
        self::assertTrue($repo->isSuppressed($event, '1423'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/BibSuppressionActionTest.php`
Expected: FAIL — route `admin_bib_suppress` (404) / `AuditAction::EventBibSuppress` undefined.

- [ ] **Step 3: Add the audit action case**

In `src/Audit/AuditAction.php`, after `EventNotificationsToggle` (line 13), add:

```php
    case EventBibSuppress = 'event.bib_suppress';
```

- [ ] **Step 4: Add the repository dependency to the controller**

In `src/Controller/Admin/PhotoController.php` constructor (line 43-55), add after `PhotoRepository $photos`:

```php
        private readonly BibSuppressionRepository $bibSuppressions,
```

and add the import with the others:

```php
use App\Repository\BibSuppressionRepository;
```

- [ ] **Step 5: Add the de-index action**

In `src/Controller/Admin/PhotoController.php`, add before `loadOrThrow()` (line 327):

```php
    #[Route(
        '/admin/events/{id}/bib-suppressions',
        name: 'admin_bib_suppress',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::EventBibSuppress, targetParam: 'id', targetType: 'Event')]
    public function suppressBib(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'suppress_bib_' . $event->getId());

        $bibNumber = trim((string) $request->request->get('bibNumber'));
        if ($bibNumber === '') {
            $this->addFlash('error', 'Enter a bib number to suppress.');

            return $this->redirectToRoute('admin_photo_grid', ['id' => $event->getId()]);
        }

        // Plan C extends this action to also delete matching bib PhotoAttribute rows.
        if (!$this->bibSuppressions->isSuppressed($event, $bibNumber)) {
            $this->em->persist(new BibSuppression($event, $bibNumber));
            $this->em->flush();
        }

        $this->audit->set('suppressed_bib', $bibNumber);
        $this->addFlash('success', sprintf('Bib %s will not be indexed.', $bibNumber));

        return $this->redirectToRoute('admin_photo_grid', ['id' => $event->getId()]);
    }
```

Add the entity import with the others:

```php
use App\Entity\BibSuppression;
```

- [ ] **Step 6: Add a minimal admin control**

In `templates/admin/event/photos_grid.html.twig`, guarded by the toggle, add a small form (place near the existing re-ingest control, ~line 61):

```twig
                {% if event.bibIndexingEnabled %}
                    <form method="post"
                          action="{{ path('admin_bib_suppress', {id: event.id}) }}"
                          class="flex items-end gap-2">
                        <label class="form-control">
                            <span class="label-text">De-index a bib number</span>
                            <input type="text" name="bibNumber" class="input input-bordered input-sm" required>
                        </label>
                        <input type="hidden" name="_token"
                               value="{{ csrf_token('suppress_bib_' ~ event.id) }}">
                        <button type="submit" class="btn btn-sm">Suppress</button>
                    </form>
                {% endif %}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/BibSuppressionActionTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add src/Audit/AuditAction.php src/Controller/Admin/PhotoController.php templates/admin/event/photos_grid.html.twig tests/Functional/Admin/BibSuppressionActionTest.php
git commit -m "109 - organizer bib de-index action + suppress-list UI (CSRF, voter, audited)"
```

---

### Task 5: Full-suite + gate verification

**Files:** none (verification only).

- [ ] **Step 1: Run the whole test suite**

Run: `vendor/bin/phpunit`
Expected: all green (PHPUnit is configured `failOnDeprecation/Notice/Warning` — a single deprecation fails the run).

- [ ] **Step 2: Run the quality gates**

Run: `vendor/bin/grumphp run`
Expected: phpstan L10, phpcs, phpmnd, phpcpd, rector, securitychecker, and `doctrine:schema:validate` all pass. Fix anything flagged (e.g. move any stray literal into a class constant for phpmnd) and re-run.

- [ ] **Step 3: Hand the branch to the user**

The plan is code-complete for Plan A. Report the suggested squash/commit summary to the user (do not run git yourself):

```
109 - bib governance: attested off-by-default toggle + per-event suppress-list + de-index action
```

---

## Self-Review

- **Spec coverage:** `Event.bibIndexingEnabled` (Task 1) ✓; attested toggle OFF-by-default (Task 3) ✓; audit trail of enable + attestation (Task 3 Step 6) ✓; `BibSuppression` unique per event, survives re-ingest because it is photo-independent (Task 2) ✓; organizer de-index action, CSRF + voter + audited (Task 4) ✓; launch-gate removal path exists (Task 4) ✓. Deleting existing bib *tags* on de-index is explicitly deferred to Plan C (no tags exist until then) — noted in the dependency callout.
- **Placeholder scan:** none — every code step carries complete code; test selectors that depend on real template labels are flagged for verification, not left blank.
- **Type consistency:** `isBibIndexingEnabled()/enableBibIndexing()/disableBibIndexing()` used identically in Task 1, Task 3, and the template; `BibSuppression(Event, string)` and `isSuppressed(Event, string)`/`suppressedBibNumbers(Event)` used identically in Tasks 2 and 4.
