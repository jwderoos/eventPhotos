# Standalone photo management UI — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move photo management out of the event-edit form into a standalone `/admin/events/{id}/photos` page with a paginated data table, a fixed-height upload queue panel, and per-upload feedback (server-rendered `<tr>` appended on each 202 instead of a single batch refresh).

**Architecture:** Add a `manage()` controller action that renders a Twig page hosting the existing turbo-frame photo grid (now table-shaped and paginated). Extract the per-photo row into a partial so the upload endpoint can render the same partial server-side and ship it back in the 202 JSON as a `rowHtml` field. The renamed `photo-uploader` stimulus controller prepends that HTML into the table on each completed upload while the existing `photos-poller` continues to drive `pending → ready` transitions. Remove the photos panel from the event edit form and surface a Photos action on the event list.

**Tech Stack:** PHP 8.5 / Symfony 8 / Doctrine ORM 3 / PostgreSQL 16 / Twig / Stimulus / Turbo / Tailwind (via symfonycasts/tailwind-bundle) / PHPUnit 13 / dama/doctrine-test-bundle.

**Reference spec:** `docs/superpowers/specs/2026-06-11-standalone-photo-management-design.md`. GitHub issue: [#20](https://github.com/jwderoos/eventPhotos/issues/20).

---

## File map

**New**

- `src/Twig/BytesExtension.php` — `format_bytes` Twig filter.
- `templates/admin/event/_photo_row.html.twig` — single `<tr>` partial, used by the table loop and by the upload endpoint's `rowHtml`.
- `templates/admin/event/photos_manage.html.twig` — standalone page extending `admin/_base.html.twig`.
- `assets/controllers/photo_uploader_controller.js` — renamed and extended from `uppy_controller.js`.
- `tests/Functional/Admin/PhotoManagePageTest.php`
- `tests/Functional/Admin/PhotoPaginationTest.php`
- `tests/Integration/Repository/PhotoRepositoryPaginationTest.php`
- `tests/Unit/Twig/BytesExtensionTest.php`

**Modified**

- `src/Controller/Admin/PhotoController.php` — add `manage()`, paginate `gridFrame()`, extend `upload()` JSON, switch retry/delete redirects.
- `src/Repository/PhotoRepository.php` — add `paginateForEvent()`.
- `templates/admin/event/photos_grid.html.twig` — rebuilt as `<table>` using `_photo_row.html.twig`, pagination footer.
- `templates/admin/event/index.html.twig` — add Photos action button per row.
- `templates/admin/event/form.html.twig` — remove the photos panel include.
- `tests/Functional/Admin/PhotoUploadTest.php` — assert `rowHtml` in 202 response.
- `tests/Functional/Admin/PhotoModerationTest.php` — assert retry/delete redirect to the manage page.

**Deleted**

- `templates/admin/event/photos_panel.html.twig`
- `assets/controllers/uppy_controller.js` (replaced by the renamed file)

---

## Task 1: Create the feature branch

**Files:** none (git only)

- [ ] **Step 1: Verify we're on main with a clean tree**

```bash
git status
git branch --show-current
```

Expected: clean tree, branch `main`.

- [ ] **Step 2: Create the feature branch**

```bash
git checkout -b feature/20-standalone-photo-management
```

(The CLAUDE.md / GrumPHP branch-name gate requires `^(feature|hotfix|bugfix|release)/\d+-`.)

- [ ] **Step 3: Confirm tooling works on host**

```bash
php -v
vendor/bin/phpunit --version
```

Expected: PHP 8.5.x and PHPUnit 13.x. No commit.

---

## Task 2: `BytesExtension` Twig filter

The size column needs a human byte formatter; no equivalent exists in the codebase. Smallest unit, so it goes first.

**Files:**
- Create: `src/Twig/BytesExtension.php`
- Test: `tests/Unit/Twig/BytesExtensionTest.php`

- [ ] **Step 1: Write the failing unit test**

`tests/Unit/Twig/BytesExtensionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\BytesExtension;
use PHPUnit\Framework\TestCase;

final class BytesExtensionTest extends TestCase
{
    private BytesExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new BytesExtension();
    }

    public function testZeroBytes(): void
    {
        $this->assertSame('0 B', $this->ext->formatBytes(0));
    }

    public function testUnderOneKilobyteReturnsBytes(): void
    {
        $this->assertSame('512 B', $this->ext->formatBytes(512));
    }

    public function testKilobytes(): void
    {
        $this->assertSame('1.5 KB', $this->ext->formatBytes(1536));
    }

    public function testMegabytes(): void
    {
        $this->assertSame('2.1 MB', $this->ext->formatBytes(2_202_010));
    }

    public function testGigabytes(): void
    {
        $this->assertSame('1.0 GB', $this->ext->formatBytes(1_073_741_824));
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit tests/Unit/Twig/BytesExtensionTest.php
```

Expected: fails with "Class App\Twig\BytesExtension not found".

- [ ] **Step 3: Implement the extension**

`src/Twig/BytesExtension.php`:

```php
<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class BytesExtension extends AbstractExtension
{
    private const int BYTES_PER_KB = 1024;

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_bytes', $this->formatBytes(...)),
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < self::BYTES_PER_KB) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes / self::BYTES_PER_KB;
        $unit  = $units[0];
        foreach ($units as $candidate) {
            $unit = $candidate;
            if ($value < self::BYTES_PER_KB) {
                break;
            }
            $value /= self::BYTES_PER_KB;
        }

        return sprintf('%.1f %s', $value, $unit);
    }
}
```

(Symfony autoconfigures Twig extensions tagged via the `twig.extension` tag automatically when they extend `AbstractExtension` and `App\` is autoconfigured in `config/services.yaml` — no manual service definition needed.)

- [ ] **Step 4: Run the test and watch it pass**

```bash
vendor/bin/phpunit tests/Unit/Twig/BytesExtensionTest.php
```

Expected: 5 passing tests, 0 failures.

- [ ] **Step 5: PHPStan check**

```bash
vendor/bin/phpstan analyse src/Twig tests/Unit/Twig
```

Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Twig/BytesExtension.php tests/Unit/Twig/BytesExtensionTest.php
git commit -m "20 - add format_bytes Twig filter for photo size column"
```

---

## Task 3: `PhotoRepository::paginateForEvent`

A single method that returns the photos for one page plus the total count, used by the (paginated) `gridFrame()` controller.

**Files:**
- Modify: `src/Repository/PhotoRepository.php`
- Test: `tests/Integration/Repository/PhotoRepositoryPaginationTest.php`

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/Repository/PhotoRepositoryPaginationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoRepositoryPaginationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PhotoRepository $repo;
    private Event $event;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var PhotoRepository $repo */
        $repo = self::getContainer()->get(PhotoRepository::class);

        $this->em   = $em;
        $this->repo = $repo;

        $owner = new User('owner@example.test', 'Owner');
        $owner->setPassword('x');
        $this->em->persist($owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $owner);
        $this->event->setTimezone('UTC');
        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testReturnsRequestedPageAndTotal(): void
    {
        for ($i = 0; $i < 150; ++$i) {
            $this->createPending('file-' . $i . '.jpg');
        }
        $this->em->flush();

        $page1 = $this->repo->paginateForEvent($this->event, 1, 100);
        $page2 = $this->repo->paginateForEvent($this->event, 2, 100);
        $page3 = $this->repo->paginateForEvent($this->event, 3, 100);

        $this->assertSame(150, $page1['total']);
        $this->assertCount(100, $page1['photos']);
        $this->assertCount(50, $page2['photos']);
        $this->assertCount(0, $page3['photos']);
    }

    public function testOrdersByCreatedAtDescAcrossPages(): void
    {
        $photos = [];
        for ($i = 0; $i < 5; ++$i) {
            $photos[] = $this->createPending('f-' . $i . '.jpg');
        }
        $this->em->flush();

        $page1 = $this->repo->paginateForEvent($this->event, 1, 3);
        $page2 = $this->repo->paginateForEvent($this->event, 2, 3);

        // Newest first: the last-created photo should be index 0 on page 1.
        $this->assertSame($photos[4]->getId(), $page1['photos'][0]->getId());
        $this->assertSame($photos[2]->getId(), $page1['photos'][2]->getId());
        $this->assertSame($photos[1]->getId(), $page2['photos'][0]->getId());
        $this->assertSame($photos[0]->getId(), $page2['photos'][1]->getId());
    }

    public function testScopesToTheGivenEventOnly(): void
    {
        $other = new Event('other', 'Other', new DateTimeImmutable('2026-06-10'), $this->event->getOwner());
        $other->setTimezone('UTC');
        $this->em->persist($other);

        $this->createPending('mine.jpg');

        $strangerPhoto = new Photo($other, bin2hex(random_bytes(32)), 'stranger.jpg', 100);
        $this->em->persist($strangerPhoto);
        $this->em->flush();

        $result = $this->repo->paginateForEvent($this->event, 1, 100);

        $this->assertSame(1, $result['total']);
        $this->assertSame('mine.jpg', $result['photos'][0]->getOriginalFilename());
    }

    private function createPending(string $filename): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: bin2hex(random_bytes(32)),
            originalFilename: $filename,
            byteSize: 100,
        );
        $this->em->persist($photo);

        return $photo;
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryPaginationTest.php
```

Expected: fatal error "Call to undefined method ... ::paginateForEvent()".

- [ ] **Step 3: Implement `paginateForEvent` on the repository**

Add at the end of `src/Repository/PhotoRepository.php` (inside the class, after `findReadyInWindow`):

```php
    /**
     * @return array{photos: list<Photo>, total: int}
     */
    public function paginateForEvent(Event $event, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        $offset  = ($page - 1) * $perPage;

        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.event = :event')
            ->setParameter('event', $event)
            ->orderBy('p.createdAt', 'DESC');

        /** @var list<Photo> $photos */
        $photos = (clone $qb)
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) (clone $qb)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return ['photos' => $photos, 'total' => $total];
    }
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryPaginationTest.php
```

Expected: 3 passing tests, 0 failures.

- [ ] **Step 5: PHPStan check**

```bash
vendor/bin/phpstan analyse src/Repository tests/Integration/Repository
```

Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositoryPaginationTest.php
git commit -m "20 - add paginateForEvent to PhotoRepository"
```

---

## Task 4: `_photo_row.html.twig` partial + rebuild `photos_grid.html.twig` as a paginated table

This is the biggest single template change. The partial is needed by both the table loop and the upload endpoint's `rowHtml`. The grid template becomes a `<table>` consuming the partial, with a pagination footer. The controller is updated to paginate.

**Files:**
- Create: `templates/admin/event/_photo_row.html.twig`
- Modify: `templates/admin/event/photos_grid.html.twig`
- Modify: `src/Controller/Admin/PhotoController.php` (`gridFrame` only)
- Test: `tests/Functional/Admin/PhotoPaginationTest.php`

- [ ] **Step 1: Write the failing functional test**

`tests/Functional/Admin/PhotoPaginationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoPaginationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Event $event;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c            = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;

        $owner = new User('owner@example.test', 'Owner');
        $owner->setPassword($hasher->hashPassword($owner, 'secret'));
        $owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $owner);
        $this->event->setTimezone('Europe/Amsterdam');
        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($owner);
    }

    public function testFirstPageShowsHundredRows(): void
    {
        $this->seed(150);

        $url = sprintf('/admin/events/%d/photos-grid', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertCount(100, $crawler->filter('table tbody tr'));
    }

    public function testSecondPageShowsRemainingFifty(): void
    {
        $this->seed(150);

        $url = sprintf('/admin/events/%d/photos-grid?page=2', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertCount(50, $crawler->filter('table tbody tr'));
    }

    public function testPastLastPageIsEmpty(): void
    {
        $this->seed(150);

        $url = sprintf('/admin/events/%d/photos-grid?page=3', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('table tbody tr'));
    }

    private function seed(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $photo = new Photo(
                event: $this->event,
                contentHash: bin2hex(random_bytes(32)),
                originalFilename: 'f-' . $i . '.jpg',
                byteSize: 100,
            );
            $this->em->persist($photo);
        }
        $this->em->flush();
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoPaginationTest.php
```

Expected: assertion failure — current `photos_grid.html.twig` renders a `<ul>`, not a `<table>`, so `table tbody tr` counts come back as 0.

- [ ] **Step 3: Create the row partial**

`templates/admin/event/_photo_row.html.twig`:

```twig
<tr data-photo-id="{{ photo.id }}" data-status="{{ photo.status.value }}">
    <td class="w-14">
        {% if photo.status.value == 'ready' %}
            <a href="{{ path('photo_serve_preview', {id: photo.id}) }}"
               target="_blank" rel="noopener">
                <img src="{{ path('photo_serve_thumb', {id: photo.id}) }}"
                     alt="{{ photo.originalFilename }}"
                     loading="lazy"
                     class="h-12 w-12 object-cover rounded">
            </a>
        {% else %}
            <div class="h-12 w-12 bg-base-300 rounded"></div>
        {% endif %}
    </td>
    <td class="font-medium">
        <span class="block max-w-xs truncate" title="{{ photo.originalFilename }}">
            {{ photo.originalFilename }}
        </span>
    </td>
    <td class="whitespace-nowrap text-sm">
        {% if photo.takenAt %}
            {{ photo.takenAt|date('Y-m-d H:i', event.timezone) }}
        {% endif %}
    </td>
    <td class="whitespace-nowrap text-sm">
        {{ photo.createdAt|date('Y-m-d H:i', event.timezone) }}
    </td>
    <td class="whitespace-nowrap text-sm">
        {% if photo.width and photo.height %}
            {{ photo.width }} × {{ photo.height }}
        {% endif %}
    </td>
    <td class="whitespace-nowrap text-sm">
        {{ photo.byteSize|format_bytes }}
    </td>
    <td>
        <span class="badge badge-{{ {
            ready: 'success',
            pending: 'info',
            failed: 'error',
        }[photo.status.value] ?? 'ghost' }}"
              {% if photo.processingError %}title="{{ photo.processingError }}"{% endif %}>
            {{ photo.status.value }}
        </span>
    </td>
    <td class="text-right">
        <div class="inline-flex items-center gap-1">
            {% if photo.status.value == 'ready' %}
                <a href="{{ path('photo_serve_preview', {id: photo.id}) }}"
                   target="_blank" rel="noopener"
                   class="btn btn-ghost btn-xs">View</a>
            {% endif %}
            {% if photo.status.value == 'failed' %}
                <form method="post"
                      action="{{ path('admin_photo_retry', {eventId: event.id, photoId: photo.id}) }}"
                      class="inline">
                    <input type="hidden" name="_token" value="{{ csrf_token('retry_photo_' ~ photo.id) }}">
                    <button class="btn btn-ghost btn-xs text-warning">Retry</button>
                </form>
            {% endif %}
            <form method="post"
                  action="{{ path('admin_photo_delete', {eventId: event.id, photoId: photo.id}) }}"
                  onsubmit="return confirm('Delete this photo?');"
                  class="inline">
                <input type="hidden" name="_token" value="{{ csrf_token('delete_photo_' ~ photo.id) }}">
                <button class="btn btn-ghost btn-xs text-error">Delete</button>
            </form>
        </div>
    </td>
</tr>
```

- [ ] **Step 4: Replace `photos_grid.html.twig` with the paginated table**

Overwrite `templates/admin/event/photos_grid.html.twig` with:

```twig
<turbo-frame id="photos-grid"
             data-controller="photos-poller"
             data-photos-poller-src-value="{{ path('admin_photo_grid', {id: event.id, page: page}) }}">
    {% if hasStalePending %}
        <div class="alert alert-warning my-4">
            Some photos look stuck. Is the worker running?
            <code>bin/console messenger:consume async failed -vv</code>
        </div>
    {% endif %}

    {% if total == 0 %}
        <p class="text-base-content/70">No photos yet. Drop some files above.</p>
    {% elseif photos|length == 0 %}
        <p class="text-base-content/70">No photos on this page.</p>
    {% else %}
        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>Thumb</th>
                        <th>Filename</th>
                        <th>Taken at</th>
                        <th>Uploaded at</th>
                        <th>Dimensions</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {% for photo in photos %}
                        {{ include('admin/event/_photo_row.html.twig', {event: event, photo: photo}) }}
                    {% endfor %}
                </tbody>
            </table>
        </div>
    {% endif %}

    {% set lastPage = (total > 0) ? ((total - 1) // perPage) + 1 : 1 %}
    {% if lastPage > 1 %}
        <nav class="mt-4 flex items-center justify-between text-sm">
            <div class="text-base-content/70">
                {% set from = (page - 1) * perPage + 1 %}
                {% set to   = min(page * perPage, total) %}
                Showing {{ from }}–{{ to }} of {{ total }}
            </div>
            <div class="inline-flex items-center gap-2">
                {% if page > 1 %}
                    <a href="{{ path('admin_photo_grid', {id: event.id, page: page - 1}) }}"
                       class="btn btn-ghost btn-sm">‹ Prev</a>
                {% endif %}
                <span class="px-2">Page {{ page }} of {{ lastPage }}</span>
                {% if page < lastPage %}
                    <a href="{{ path('admin_photo_grid', {id: event.id, page: page + 1}) }}"
                       class="btn btn-ghost btn-sm">Next ›</a>
                {% endif %}
            </div>
        </nav>
    {% endif %}
</turbo-frame>
```

- [ ] **Step 5: Update `gridFrame()` to paginate**

In `src/Controller/Admin/PhotoController.php`, **remove** the `GRID_LIMIT` constant and **replace** the existing `gridFrame()` method with:

```php
    private const int PER_PAGE = 100;

    #[Route(
        '/admin/events/{id}/photos-grid',
        name: 'admin_photo_grid',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function gridFrame(Event $event, Request $request): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $page = max(1, $request->query->getInt('page', 1));

        $result = $this->photos->paginateForEvent($event, $page, self::PER_PAGE);
        /** @var list<Photo> $photos */
        $photos = $result['photos'];
        /** @var int $total */
        $total = $result['total'];

        $hasStalePending = false;
        $cutoff          = new DateTimeImmutable(self::STALE_PENDING_THRESHOLD);
        foreach ($photos as $p) {
            if ($p->getStatus() === PhotoStatus::Pending && $p->getCreatedAt() < $cutoff) {
                $hasStalePending = true;
                break;
            }
        }

        return $this->render('admin/event/photos_grid.html.twig', [
            'event'           => $event,
            'photos'          => $photos,
            'total'           => $total,
            'page'            => $page,
            'perPage'         => self::PER_PAGE,
            'hasStalePending' => $hasStalePending,
        ]);
    }
```

(Leave the existing `GRID_LIMIT` reference if PHPStan complains — but the new code does not use it; remove the constant line entirely.)

- [ ] **Step 6: Run the pagination test and watch it pass**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoPaginationTest.php
```

Expected: 3 passing tests.

- [ ] **Step 7: Run the rest of the suite to confirm nothing else broke**

```bash
vendor/bin/phpunit
```

Expected: green. `PhotoUploadTest` and `PhotoModerationTest` should still pass — they target endpoints that haven't changed yet.

- [ ] **Step 8: PHPStan check**

```bash
vendor/bin/phpstan analyse src tests
```

Expected: `[OK] No errors`.

- [ ] **Step 9: Commit**

```bash
git add src/Controller/Admin/PhotoController.php \
        src/Repository/PhotoRepository.php \
        templates/admin/event/photos_grid.html.twig \
        templates/admin/event/_photo_row.html.twig \
        tests/Functional/Admin/PhotoPaginationTest.php
git commit -m "20 - rebuild photo grid as paginated table with row partial"
```

(The `PhotoRepository.php` was already committed in Task 3; if `git add` is a no-op for it, that's fine.)

---

## Task 5: Manage page route + template + functional test

A new GET route at the same path as the POST upload (Symfony differentiates by method), rendering a Twig page that hosts the uploader and the turbo-frame grid.

**Files:**
- Modify: `src/Controller/Admin/PhotoController.php` (add `manage()`)
- Create: `templates/admin/event/photos_manage.html.twig`
- Test: `tests/Functional/Admin/PhotoManagePageTest.php`

- [ ] **Step 1: Write the failing functional test**

`tests/Functional/Admin/PhotoManagePageTest.php`:

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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoManagePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Event $event;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c            = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;

        $this->owner = new User('owner@example.test', 'Owner');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'secret'));
        $this->owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($this->owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $this->owner);
        $this->event->setTimezone('Europe/Amsterdam');
        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testManagePageRendersForOwner(): void
    {
        $this->client->loginUser($this->owner);

        $url     = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('[data-controller="photo-uploader"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('turbo-frame#photos-grid')->count());
        $this->assertStringContainsString('Photos', (string) $this->client->getResponse()->getContent());
    }

    public function testManagePageRejectsNonOwner(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher   = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = new User('stranger@example.test', 'Stranger');
        $stranger->setPassword($hasher->hashPassword($stranger, 'x'));
        $stranger->addRole('ROLE_ORGANIZER');
        $this->em->persist($stranger);
        $this->em->flush();

        $this->client->loginUser($stranger);

        $url = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagePageRequiresAuthentication(): void
    {
        $url = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseRedirects('/login');
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoManagePageTest.php
```

Expected: `testManagePageRendersForOwner` fails because the GET route doesn't exist yet (404 or "No route found"). The other two may pass or fail depending on routing; what matters is the first failure.

- [ ] **Step 3: Add the `manage()` action**

Add to `src/Controller/Admin/PhotoController.php`, immediately above the existing `upload()` method:

```php
    #[Route(
        '/admin/events/{id}/photos',
        name: 'admin_event_photo_manage',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function manage(Event $event): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        return $this->render('admin/event/photos_manage.html.twig', [
            'event' => $event,
        ]);
    }
```

- [ ] **Step 4: Create the manage page template**

`templates/admin/event/photos_manage.html.twig`:

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — Photos · {{ event.name }}{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li><a href="{{ path('admin_event_index') }}" class="link link-hover">Events</a></li>
            <li>{{ event.name }}</li>
            <li>Photos</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Photos · {{ event.name }}</h1>
        <a href="{{ path('admin_event_edit', {id: event.id}) }}" class="btn btn-ghost btn-sm">← Back to event</a>
    </header>

    <section class="card bg-base-100 shadow-sm mb-6">
        <div class="card-body">
            <div data-controller="photo-uploader"
                 data-photo-uploader-endpoint-value="{{ path('admin_photo_upload', {id: event.id}) }}"
                 data-photo-uploader-grid-frame-value="{{ path('admin_photo_grid', {id: event.id}) }}">
            </div>
        </div>
    </section>

    <section class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <turbo-frame id="photos-grid"
                         src="{{ path('admin_photo_grid', {id: event.id}) }}"
                         loading="lazy">
                <p class="text-base-content/70">Loading photos…</p>
            </turbo-frame>
        </div>
    </section>
{% endblock %}
```

(`data-controller="photo-uploader"` is the renamed-but-not-yet-existing controller. Until Task 7 renames it, this attribute won't bind to anything — that's fine for the functional test, which only checks the DOM markup.)

- [ ] **Step 5: Run the test and watch it pass**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoManagePageTest.php
```

Expected: 3 passing tests.

- [ ] **Step 6: PHPStan check**

```bash
vendor/bin/phpstan analyse src
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/PhotoController.php \
        templates/admin/event/photos_manage.html.twig \
        tests/Functional/Admin/PhotoManagePageTest.php
git commit -m "20 - add standalone photo management page"
```

---

## Task 6: Upload endpoint returns `rowHtml` in 202 response

Extend the existing `upload()` JSON. Update the existing `PhotoUploadTest` to assert the new field. Duplicate responses are unchanged.

**Files:**
- Modify: `src/Controller/Admin/PhotoController.php` (`upload()` only)
- Modify: `tests/Functional/Admin/PhotoUploadTest.php`

- [ ] **Step 1: Add a failing assertion to the existing happy-path test**

In `tests/Functional/Admin/PhotoUploadTest.php`, replace the body of `testHappyPathReturnsPendingAndPersistsRow()` with:

```php
    public function testHappyPathReturnsPendingAndPersistsRow(): void
    {
        $file = $this->fixture('with-datetime-original.jpg');
        $url  = sprintf('/admin/events/%d/photos', (int) $this->event->getId());

        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file]);

        self::assertResponseStatusCodeSame(202);
        $body = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('pending', $body['status'] ?? null);
        $this->assertIsInt($body['photoId'] ?? null);
        $this->assertArrayHasKey('rowHtml', $body);
        $this->assertIsString($body['rowHtml']);
        $this->assertStringContainsString(
            sprintf('data-photo-id="%d"', $body['photoId']),
            $body['rowHtml'],
        );
        $this->assertStringContainsString('data-status="pending"', $body['rowHtml']);

        $photo = $this->em->find(Photo::class, $body['photoId']);
        $this->assertInstanceOf(Photo::class, $photo);
        $this->assertSame(PhotoStatus::Pending, $photo->getStatus());
        $this->assertTrue($this->originals->fileExists(
            sprintf('event-%d/%d.jpg', (int) $this->event->getId(), (int) $photo->getId()),
        ));
    }
```

Add a new test in the same file for the duplicate case (it should NOT contain `rowHtml`):

```php
    public function testDuplicateResponseHasNoRowHtml(): void
    {
        $url = sprintf('/admin/events/%d/photos', (int) $this->event->getId());

        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $this->fixture('with-datetime-original.jpg')]);
        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $this->fixture('with-datetime-original.jpg')]);

        self::assertResponseStatusCodeSame(200);
        $body = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('duplicate', $body['status']);
        $this->assertArrayNotHasKey('rowHtml', $body);
    }
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoUploadTest.php
```

Expected: `testHappyPathReturnsPendingAndPersistsRow` fails on `assertArrayHasKey('rowHtml', $body)`.

- [ ] **Step 3: Extend `upload()` to include `rowHtml`**

In `src/Controller/Admin/PhotoController.php`, replace the final `return new JsonResponse(...)` block at the end of `upload()` with:

```php
        $this->bus->dispatch(new ProcessPhoto((int) $photo->getId()));

        $rowHtml = $this->renderView('admin/event/_photo_row.html.twig', [
            'event' => $event,
            'photo' => $photo,
        ]);

        return new JsonResponse(
            [
                'status'  => 'pending',
                'photoId' => $photo->getId(),
                'rowHtml' => $rowHtml,
            ],
            Response::HTTP_ACCEPTED,
        );
```

(The `duplicate` path is left untouched.)

- [ ] **Step 4: Run the test and watch it pass**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoUploadTest.php
```

Expected: all tests in the file pass.

- [ ] **Step 5: PHPStan check**

```bash
vendor/bin/phpstan analyse src
```

Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Admin/PhotoController.php \
        tests/Functional/Admin/PhotoUploadTest.php
git commit -m "20 - return rendered row html in upload 202 response"
```

---

## Task 7: Rename and extend the stimulus controller

Rename `uppy_controller.js` to `photo_uploader_controller.js` and extend it with explicit queue states (uploading / queued / done), a fixed-height scrollable container, and per-upload prepend of `rowHtml` into the table `<tbody>`.

**Files:**
- Create: `assets/controllers/photo_uploader_controller.js`
- Delete: `assets/controllers/uppy_controller.js`

There are no automated JS tests in this repo. Verification is via the dev stack + browser.

- [ ] **Step 1: Delete the old controller**

```bash
git rm assets/controllers/uppy_controller.js
```

- [ ] **Step 2: Create the new controller**

`assets/controllers/photo_uploader_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

const MAX_BYTES = 25 * 1024 * 1024;
const CONCURRENCY = 3;
const ALLOWED_TYPES = ['image/jpeg', 'image/jpg'];

export default class extends Controller {
    static values = {
        endpoint: String,
        gridFrame: String,
    };

    connect() {
        this.element.innerHTML = `
            <div class="border-2 border-dashed border-base-300 rounded-box p-6 text-center"
                 data-dropzone>
                <p class="text-base-content/70 mb-2">Drag JPEGs here, or</p>
                <label class="btn btn-primary btn-sm">
                    Choose files
                    <input type="file" multiple accept="image/jpeg,.jpg,.jpeg" class="hidden" data-file-input>
                </label>
                <p class="text-xs text-base-content/50 mt-2">JPEG only, up to 25 MB each</p>
            </div>
            <div class="mt-3 max-h-64 overflow-y-auto border border-base-300 rounded-box p-3 hidden"
                 data-queue-panel>
                <section data-section="uploading" class="hidden mb-2">
                    <h3 class="text-xs font-semibold text-base-content/60 mb-1">Uploading · <span data-count>0</span></h3>
                    <ul class="space-y-1 text-sm" data-list></ul>
                </section>
                <section data-section="queued" class="hidden mb-2">
                    <h3 class="text-xs font-semibold text-base-content/60 mb-1">Queued · <span data-count>0</span></h3>
                    <ul class="space-y-1 text-sm" data-list></ul>
                </section>
                <section data-section="done" class="hidden">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-xs font-semibold text-base-content/60">Done · <span data-count>0</span></h3>
                        <button type="button"
                                class="btn btn-ghost btn-xs"
                                data-action="click->photo-uploader#clearDone">Clear done</button>
                    </div>
                    <ul class="space-y-1 text-sm" data-list></ul>
                </section>
            </div>
        `;

        this.dropzone   = this.element.querySelector('[data-dropzone]');
        this.fileInput  = this.element.querySelector('[data-file-input]');
        this.queuePanel = this.element.querySelector('[data-queue-panel]');
        this.sections   = {
            uploading: this.element.querySelector('[data-section="uploading"]'),
            queued:    this.element.querySelector('[data-section="queued"]'),
            done:      this.element.querySelector('[data-section="done"]'),
        };

        this.queue    = [];
        this.inFlight = 0;

        this.fileInput.addEventListener('change', (e) => this.handleFiles(e.target.files));

        ['dragenter', 'dragover'].forEach((evt) =>
            this.dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                this.dropzone.classList.add('border-primary');
            })
        );
        ['dragleave', 'drop'].forEach((evt) =>
            this.dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                this.dropzone.classList.remove('border-primary');
            })
        );
        this.dropzone.addEventListener('drop', (e) => this.handleFiles(e.dataTransfer.files));
    }

    handleFiles(fileList) {
        const files = Array.from(fileList);
        if (!files.length) return;

        this.queuePanel.classList.remove('hidden');

        for (const file of files) {
            const job = { file, row: this.createRow(file), state: null };
            this.moveTo(job, 'queued');
            this.queue.push(job);
        }
        this.drain();
    }

    drain() {
        while (this.inFlight < CONCURRENCY && this.queue.length) {
            const job = this.queue.shift();
            this.inFlight++;
            this.moveTo(job, 'uploading');
            this.upload(job).finally(() => {
                this.inFlight--;
                this.drain();
            });
        }
    }

    async upload(job) {
        const { file, row } = job;

        if (!ALLOWED_TYPES.includes(file.type)) {
            this.fail(job, 'Not a JPEG');
            return;
        }
        if (file.size > MAX_BYTES) {
            this.fail(job, 'Too large (>25 MB)');
            return;
        }

        const form = new FormData();
        form.append('file', file);

        await new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.endpointValue);
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    this.setProgress(row, pct);
                }
            });
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    let body = {};
                    try { body = JSON.parse(xhr.responseText); } catch (_) { /* noop */ }
                    if (body.status === 'duplicate') {
                        this.done(job, 'Already uploaded');
                    } else {
                        this.done(job, 'Uploaded');
                        if (body.rowHtml) {
                            this.prependRow(body.rowHtml);
                        }
                    }
                } else {
                    this.fail(job, `HTTP ${xhr.status}`);
                }
                resolve();
            });
            xhr.addEventListener('error', () => {
                this.fail(job, 'Network error');
                resolve();
            });
            xhr.send(form);
        });
    }

    createRow(file) {
        const li = document.createElement('li');
        li.className = 'flex items-center gap-2';
        li.innerHTML = `
            <span class="truncate flex-1">${file.name}</span>
            <progress class="progress progress-primary w-24" value="0" max="100"></progress>
            <span class="text-xs text-base-content/60 w-24 text-right" data-status></span>
        `;
        return li;
    }

    moveTo(job, state) {
        const target = this.sections[state];
        target.querySelector('[data-list]').appendChild(job.row);
        job.state = state;
        this.refreshSections();
    }

    refreshSections() {
        for (const [name, section] of Object.entries(this.sections)) {
            const list  = section.querySelector('[data-list]');
            const count = section.querySelector('[data-count]');
            count.textContent = String(list.children.length);
            section.classList.toggle('hidden', list.children.length === 0);
        }
    }

    setProgress(row, pct) {
        row.querySelector('progress').value = pct;
    }

    done(job, label) {
        job.row.querySelector('progress').value = 100;
        job.row.querySelector('[data-status]').textContent = label;
        this.moveTo(job, 'done');
    }

    fail(job, reason) {
        job.row.querySelector('progress').classList.add('progress-error');
        job.row.querySelector('[data-status]').textContent = reason;
        this.moveTo(job, 'done');
    }

    clearDone() {
        const list = this.sections.done.querySelector('[data-list]');
        list.innerHTML = '';
        this.refreshSections();
    }

    prependRow(html) {
        const frame = document.getElementById('photos-grid');
        if (!frame) return;
        // Only inject when the frame is showing page 1; on later pages the next
        // poller tick re-renders the page from the server and the placeholder
        // would either belong on page 1 or be a spurious row.
        const src = frame.getAttribute('src') || '';
        const pageMatch = src.match(/[?&]page=(\d+)/);
        if (pageMatch && pageMatch[1] !== '1') return;
        const tbody = frame.querySelector('table tbody');
        if (!tbody) return;
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const tr = wrapper.firstElementChild;
        if (tr) {
            tbody.prepend(tr);
        }
    }
}
```

- [ ] **Step 3: Verify the controller is auto-registered**

`importmap.php` / `config/packages/stimulus.yaml` auto-discover controllers under `assets/controllers/*_controller.js` by filename. No additional registration needed. Confirm by:

```bash
ls assets/controllers/
```

Expected: `photo_uploader_controller.js` present, `uppy_controller.js` absent.

- [ ] **Step 4: Manual UI smoke test**

```bash
docker compose up -d
docker compose logs -f worker  # in another terminal
```

Open `http://localhost:8080/admin/events`, log in as an organizer, click "Photos" on a row (Photos action arrives in Task 8 — for this task, navigate manually to `/admin/events/<id>/photos`), and:

1. Drop 3–5 JPEGs. Confirm each appears in the table immediately (as pending) without waiting for the rest.
2. Confirm queue panel shows Uploading / Done sub-sections in that order.
3. Confirm the poller upgrades pending → ready within ~5 s after the worker finishes.
4. Click "Clear done"; the done list empties but Uploading does not.

If anything is off, fix and re-test before committing.

- [ ] **Step 5: Commit**

```bash
git add assets/controllers/photo_uploader_controller.js
git commit -m "20 - rename uppy_controller to photo_uploader and add staged queue + per-upload row prepend"
```

(`git rm` in Step 1 already staged the deletion.)

---

## Task 8: Add the Photos action to the event list

The event list row needs a Photos button linking to the manage page.

**Files:**
- Modify: `templates/admin/event/index.html.twig`
- Modify: `tests/Functional/Admin/PhotoManagePageTest.php` (extend with a list-page assertion)

- [ ] **Step 1: Add a failing assertion to `PhotoManagePageTest`**

Append this method to `tests/Functional/Admin/PhotoManagePageTest.php`:

```php
    public function testEventListShowsPhotosLinkToManagePage(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events');

        self::assertResponseIsSuccessful();
        $expected = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $this->assertGreaterThan(
            0,
            $crawler->filter(sprintf('a[href="%s"]', $expected))->count(),
            'Event list row should link to the photo manage page.',
        );
    }
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit --filter testEventListShowsPhotosLinkToManagePage
```

Expected: failure — the index template does not yet render a link to `/admin/events/N/photos`.

- [ ] **Step 3: Add the Photos action to the row**

In `templates/admin/event/index.html.twig`, **replace** the existing action block inside the `<td class="text-right">` with:

```twig
<td class="text-right">
    <div class="inline-flex items-center gap-1">
        <a href="{{ path('admin_event_qr', {id: event.id}) }}"
           target="_blank"
           rel="noopener"
           class="btn btn-ghost btn-xs">QR</a>
        <a href="{{ path('admin_event_photo_manage', {id: event.id}) }}"
           class="btn btn-ghost btn-xs">Photos</a>
        <a href="{{ path('admin_event_edit', {id: event.id}) }}"
           class="btn btn-ghost btn-xs">Edit</a>
        <form method="post"
              action="{{ path('admin_event_delete', {id: event.id}) }}"
              onsubmit="return confirm('Delete this event?')"
              class="inline">
            <input type="hidden" name="_token" value="{{ csrf_token('delete_event_' ~ event.id) }}">
            <button type="submit" class="btn btn-ghost btn-xs text-error">Delete</button>
        </form>
    </div>
</td>
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
vendor/bin/phpunit --filter testEventListShowsPhotosLinkToManagePage
```

Expected: passes.

- [ ] **Step 5: Commit**

```bash
git add templates/admin/event/index.html.twig tests/Functional/Admin/PhotoManagePageTest.php
git commit -m "20 - add Photos action to event list"
```

---

## Task 9: Remove the photos panel from the event edit form

The standalone page is now live; the in-form panel becomes dead code.

**Files:**
- Modify: `templates/admin/event/form.html.twig`
- Delete: `templates/admin/event/photos_panel.html.twig`

- [ ] **Step 1: Remove the include from the edit form**

In `templates/admin/event/form.html.twig`, delete this block (lines 36–38 in current state):

```twig
    {% if mode == 'edit' %}
        {{ include('admin/event/photos_panel.html.twig', {event: event}) }}
    {% endif %}
```

- [ ] **Step 2: Delete the orphan template**

```bash
git rm templates/admin/event/photos_panel.html.twig
```

- [ ] **Step 3: Run the suite to confirm nothing referenced the panel**

```bash
vendor/bin/phpunit
```

Expected: green.

- [ ] **Step 4: Commit**

```bash
git add templates/admin/event/form.html.twig
git commit -m "20 - drop photos panel from event edit form"
```

---

## Task 10: Retry / delete redirects target the manage page

Both currently redirect to `admin_event_edit`. Now that the edit form has no photos panel, that is jarring — send the user back to where they took the action.

**Files:**
- Modify: `src/Controller/Admin/PhotoController.php` (`retry()`, `delete()`)
- Modify: `tests/Functional/Admin/PhotoModerationTest.php`

- [ ] **Step 1: Inspect the existing redirect assertion**

```bash
grep -n "assertResponseRedirects\|admin_event_edit" tests/Functional/Admin/PhotoModerationTest.php
```

Note the exact assertion lines; the test expects `/admin/events/{id}/edit`.

- [ ] **Step 2: Update the test to expect the manage page**

In `tests/Functional/Admin/PhotoModerationTest.php`, change each `assertResponseRedirects` call that targets the edit URL to target the manage URL. Example diff:

```diff
- self::assertResponseRedirects(sprintf('/admin/events/%d/edit', $eventId));
+ self::assertResponseRedirects(sprintf('/admin/events/%d/photos', $eventId));
```

Apply to every retry/delete test in the file.

- [ ] **Step 3: Run the test and watch it fail**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoModerationTest.php
```

Expected: failures with "expected redirect to /admin/events/N/photos, got /admin/events/N/edit".

- [ ] **Step 4: Update the controller**

In `src/Controller/Admin/PhotoController.php`, change the two `redirectToRoute('admin_event_edit', ...)` calls — in `retry()` and `delete()` — to:

```php
return $this->redirectToRoute('admin_event_photo_manage', ['id' => $eventId]);
```

- [ ] **Step 5: Run the test and watch it pass**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoModerationTest.php
```

Expected: green.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Admin/PhotoController.php tests/Functional/Admin/PhotoModerationTest.php
git commit -m "20 - redirect retry and delete to photo manage page"
```

---

## Task 11: Final verification

Run the full GrumPHP gate (matches CI), then a manual smoke pass.

**Files:** none

- [ ] **Step 1: Full test suite**

```bash
vendor/bin/phpunit
```

Expected: green, no deprecation/notice/warning failures.

- [ ] **Step 2: GrumPHP — all gates**

```bash
vendor/bin/grumphp run
```

Expected: every task passes (phpstan level 10, phpcs PSR-12, phpmnd, phpcpd, rector, securitychecker_roave, doctrine:schema:validate).

If `phpmnd` flags a magic number you introduced, lift it into a named constant. If `phpcs` flags style, fix style and re-commit.

- [ ] **Step 3: Manual browser smoke pass**

```bash
docker compose up -d
```

In `http://localhost:8080`:

1. Log in as an organizer.
2. From the event list, click "Photos" on an event row — lands on `/admin/events/{id}/photos`.
3. Drop a batch of 10 JPEGs. Confirm:
   - Queue panel shows them moving through Queued → Uploading → Done.
   - The table prepends each row as soon as its upload returns 202, without waiting for the rest.
   - Within ~5 s of the worker finishing, the rows update from `pending` to `ready` with thumbnails.
4. Click pagination "Next" — the turbo-frame swaps in-place; the queue panel stays.
5. Open the event edit page — no photos panel present.
6. Trigger a Retry on a failed photo (manually fail one by mucking with the file if needed) — confirm redirect lands on the manage page.

- [ ] **Step 4: Push the branch (no PR until user requests)**

Per the user's standing instructions, do **not** open a PR or merge. Just push when asked:

```bash
git push -u origin feature/20-standalone-photo-management
```

(The user will create the PR.)

---

## Out of scope

Not in this plan (per spec):

- Column sorting.
- Search / filter.
- Bulk select / bulk delete.
- Inline editing of photo metadata.
- Resumable uploads or migrating to the real Uppy library.
