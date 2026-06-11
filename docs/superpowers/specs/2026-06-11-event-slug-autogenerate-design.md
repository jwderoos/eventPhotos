# Auto-Generate Event Slug — Design

**Issue:** [#35](https://github.com/jwderoos/eventPhotos/issues/35)
**Date:** 2026-06-11

## Goal

`Event.slug` is currently a free-text input on the admin event form. Organizers have no reason to pick it — it's only a URL token (`/e/{slug}`). Move slug generation server-side, hide it from the form, and never recompute it after creation so existing public URLs and QR codes keep working.

## Background

- `Event` (`src/Entity/Event.php`) has `slug` as a constructor-promoted, length-120, unique-indexed string column.
- `EventType` (`src/Form/EventType.php:40`) exposes slug as a `TextType` field with help text. Reused for create and edit.
- Only one create path exists today: `Admin\EventController::new()` (`src/Controller/Admin/EventController.php:53`). It instantiates `new Event('', '', today, $user)` and binds the form.
- Tests (~25 sites in `tests/`) all instantiate `Event` with explicit slugs like `new Event('summer-fest', ...)` — they own the slug for URL assertions.
- `EventCollection` also has a `slug` field. **Out of scope** — this design only changes `Event`.

## Scope

### In scope

- Auto-generate `Event.slug` on create in shape `<slugified-name>-<6-char-token>`.
- Remove the slug input from `EventType` (affects both create and edit).
- Keep slug immutable after creation — renaming `Event` does not regenerate.
- New unit tests for the generator and the listener; new functional test covering create + edit form round-trip and slug shape in DB.

### Out of scope

- Backfilling existing rows. They keep their current slugs verbatim.
- Surfacing the generated slug in admin UI (read-only display, copy/share button, regenerate button). Possible follow-up.
- Changing public URL shape (`/e/{slug}` stays).
- `EventCollection.slug` (still free-text).
- Removing `Event::setSlug()` from the entity. The listener calls it; keeping it public avoids reflection. The form no longer reaches it, which satisfies the acceptance criterion in spirit.

## Architecture

Three units; one new service, one new listener, one minor entity-adjacent change.

### `App\Service\EventSlugGenerator` (new)

Pure service. No dependencies on Doctrine, no DB lookups.

```php
public function generate(string $name): string
```

Algorithm:

1. **Slugify base** via `Symfony\Component\String\Slugger\AsciiSlugger` (separator `-`, locale-neutral). Lowercase the result. ASCII-fold diacritics.
2. **Sanitize**: replace any character outside `[a-z0-9-]` with `-`; collapse runs of `-`; trim leading/trailing `-`.
3. **Cap base** at `BASE_MAX_LENGTH = 60` chars via hard truncation, then trim any trailing `-` left by the cut (so the base never ends with the separator). If the result is empty (e.g., name was all non-alphanumeric), fall back to the literal `event`.
4. **Token**: 6 chars from alphabet `abcdefghijklmnopqrstuvwxyz0123456789` (`TOKEN_LENGTH = 6`, `TOKEN_ALPHABET_SIZE = 36`), each char drawn via `random_int(0, 35)`. ~31 bits of entropy.
5. **Concatenate**: `"{$base}-{$token}"`.

All numeric constants are `private const int` on the class to satisfy `phpmnd`.

No collision retry — issue specifies "always append the suffix" and the DB unique index is the safety net.

### `App\EventListener\EventSlugListener` (new)

Doctrine entity listener wired via attribute:

```php
#[AsEntityListener(event: Events::prePersist, entity: Event::class)]
final class EventSlugListener
{
    public function __construct(private readonly EventSlugGenerator $generator) {}

    public function prePersist(Event $event, PrePersistEventArgs $args): void
    {
        if ($event->getSlug() === '') {
            $event->setSlug($this->generator->generate($event->getName()));
        }
    }
}
```

Key invariant: **empty-slug guard.** Existing test fixtures that pass `new Event('summer-fest', ...)` are non-empty and bypass the generator — zero churn to the test suite.

Listener only registers for `prePersist`. There is no `preUpdate` hook, so `setName()` after creation has no slug effect.

### `App\Entity\Event` (minimal change)

- Constructor signature **unchanged**: `__construct(string $slug, string $name, DateTimeImmutable $date, User $owner)`. Avoids editing ~25 test sites.
- `setSlug()` **stays public**. Only the listener calls it on create; the form can no longer reach it. Removing it would require either an internal `assignSlugIfMissing()` or reflection in the listener — worse trade.
- No `#[ORM\HasLifecycleCallbacks]` — slugify/RNG stays out of the entity for testability.

### `App\Form\EventType`

Remove this block from `buildForm()`:

```php
->add('slug', TextType::class, [
    'help' => 'Used in the public QR URL: /e/{slug}',
])
```

That's the only form change. Reused for both create and edit; no field-disambiguation needed.

### `App\Controller\Admin\EventController::new()`

No change. `new Event('', '', ...)` stays. The empty string is the signal to the listener.

### Twig template

`templates/admin/event/form.html.twig` — if it explicitly renders `{{ form_row(form.slug) }}`, that line is removed. If it uses `form_widget(form)` or `form_rest`, no change. Verified during implementation.

## Data Flow

**Admin create (happy path):**

1. Controller constructs `Event('', '', today, $user)`.
2. Form binds name, date, etc. — no `slug` field, slug stays `''`.
3. `em->persist($event)` → Doctrine fires `prePersist` → listener sees empty slug → calls `EventSlugGenerator::generate($name)` → calls `$event->setSlug(...)`.
4. `em->flush()` → INSERT with populated slug. Returns `302 /admin/events`.

**Admin edit:**

- Form has no slug field; user can change `name`. On submit → `em->flush()` → Doctrine fires `preUpdate` (not subscribed) → slug untouched.

**Test fixtures:**

- `persist(new Event('summer-fest', ...))` → `prePersist` → listener sees non-empty slug → no-op. Slug preserved.

## Edge Cases

| Case | Behavior |
|---|---|
| `name = "Summer Fest 2026!"` | `summer-fest-2026-<token>` |
| `name = "Café Olé"` | `cafe-ole-<token>` |
| `name` of 200 chars | Base capped at ≤ 60 chars (trim-to-boundary), full slug ≤ 67 chars |
| `name = "!!!"` or all non-alphanumeric | Falls back to `event-<token>` |
| Unique-constraint collision (1-in-billions) | `UniqueConstraintViolationException` surfaces as 500; admin retries. Per issue: acceptable. |
| Existing event with hand-set legacy slug | Listener no-ops on edit (preUpdate not subscribed) and on persist (slug already non-empty). Public URL keeps resolving. |
| Programmatic creator passes empty name AND empty slug | Listener generates `event-<token>`. Defensive — form validation would reject empty `name` before this is reachable from HTTP. |

## Testing

### Unit — `tests/Unit/Service/EventSlugGeneratorTest.php` (new)

- **Shape**: `assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*-[a-z0-9]{6}$/', $slug)`.
- **Slugification**: `"Summer Fest 2026!"` → base `summer-fest-2026`. `"Café Olé"` → `cafe-ole`.
- **Length cap, multi-word**: 200-char multi-word name → base ≤ 60 chars; final char of base is not `-`; full slug ≤ 67 chars.
- **Length cap, single long word**: 200-char single token → base is hard-truncated to 60 chars (no fallback to `event`).
- **Empty/garbage fallback**: `"!!!"` → starts with `event-`.
- **Token charset**: 100 generations → every suffix char in `[a-z0-9]`.
- **Uniqueness smoke**: 1000 generations for the same name produce 1000 distinct slugs.

### Unit — `tests/Unit/EventListener/EventSlugListenerTest.php` (new)

- Empty-slug `Event` → listener calls generator (stub returning a fixed string) and sets slug.
- Non-empty-slug `Event` → listener is a no-op; slug untouched.
- `PrePersistEventArgs` constructed with a real `EntityManagerInterface` mock; only the entity reference is used by the listener.

### Unit — `tests/Unit/Entity/EventTest.php` (existing, extended)

- Add a test documenting the invariant: `setName('New Name')` does not touch `slug`. (Trivial — listener doesn't run on `setName` — but documents intent.)

### Functional — `tests/Functional/Admin/EventSlugTest.php` (new)

- `GET /admin/events/new` (as ROLE_ORGANIZER) → response HTML contains no `input[name="event[slug]"]`.
- `POST /admin/events/new` with valid `name`/`date` → 302 to `/admin/events`. Reload entity from DB; assert slug matches `/^my-event-name-[a-z0-9]{6}$/`.
- `GET /admin/events/{id}/edit` for existing event → no `event[slug]` input.
- `POST /admin/events/{id}/edit` with changed `name` → reload entity; assert slug **unchanged** vs. pre-edit value.
- Legacy URL sanity: persist fixture with `slug = 'legacy-slug'`, `GET /e/legacy-slug` (or `/e/legacy-slug/photos`) → 200.

### Integration

None. `dama/doctrine-test-bundle` rolls back transactions; the functional create test exercises listener wiring end-to-end against a real EM.

## Implementation order

1. `EventSlugGenerator` + unit tests.
2. `EventSlugListener` + unit tests.
3. Remove slug field from `EventType`; remove `form.slug` from template if present.
4. Functional tests.
5. Run full GrumPHP locally (`vendor/bin/grumphp run`) and fix any phpstan/phpcs/phpmnd findings.

## CI / quality gates

- Branch: `feature/35-auto-generate-event-slug` (matches the GrumPHP regex `^(feature|hotfix|bugfix|release)/\d+-`).
- Commit messages reference `#35`.
- `phpstan` level 10: listener and service are `final`, properties typed.
- `phpmnd`: extract `60`, `6`, `36` as `private const int` on the generator.
- `phpcpd`: no copy-paste expected.
- `doctrine:schema:validate`: passes — no schema change.
- No migration is needed; the unique index already exists.

## Risks

- **Listener not autoregistering**: `#[AsEntityListener]` requires the listener to be a service. With Symfony's default autoconfiguration and `App\EventListener\` already in `services.yaml`'s default scan, it should register automatically. The functional create test catches a misregistration immediately (slug would remain `''` → INSERT violates `length: 120` constraint? Actually no — empty string is valid for that column. But the assertion `slug matches /^...-[a-z0-9]{6}$/` would fail). Risk: low, well-covered.
- **`AsciiSlugger` availability**: `symfony/string` is already a transitive dep via Symfony 8; no composer change expected. If not present, add `symfony/string` to `composer.json`.
- **`random_int` exceptions**: throws on entropy-source failure. Not catching — surfaces as 500. Acceptable.
