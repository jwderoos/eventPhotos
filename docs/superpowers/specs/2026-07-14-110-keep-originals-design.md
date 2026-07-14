# #110 — Event setting: keep originals + reduce photo upload cap to 10 MB

**Status:** Design approved (brainstorming) — pending spec review
**Date:** 2026-07-14
**Issue:** [#110](https://github.com/jwderoos/eventPhotos/issues/110)
**Related:** #112 (re-ingest), #103 (paid originals / cart), #101 (event export/import)

## Summary

Add a per-event **"Keep originals"** toggle (`Event.retainOriginals`, default off). Today `ProcessPhotoHandler::deleteOriginalQuietly()` runs after both the success (`markReady`) and domain-rejection (`markFailed`) paths, so `photo_originals_storage` holds nothing once the worker has run. With retain **on**, the handler skips that delete and the original survives at `event-<id>/<photoId>.jpg`, unlocking re-ingest (#112) and future paid-original flows (#103).

The toggle is editable **only while the event has 0 photos** — once any `Photo` exists (any status) it locks, because the setting must be consistent for every original in the event and already-deleted originals can't be recovered retroactively.

This ticket also:
- Cleans up **all** derivative/original storage on event delete (fixes a latent thumb/preview orphan bug — see §5).
- Extends the #101 export/import archive to **carry originals when retain is on** (see §7).
- **Reduces the per-photo upload cap from 25 MB to 10 MB** (see §8) — retained originals are the full JPEGs, so keeping them bounded matters.

## Scope decisions (from brainstorming)

- **Event-delete cleanup:** clean all three prefixes (originals + thumbs + previews), not just originals. Fixes the pre-existing thumb/preview orphan gap while we're wiring cleanup in.
- **Export/import:** include originals in *this* ticket when retain is on.
- **Upload cap:** lower to 10 MB (user request during design).

## 1. Entity — `Event.retainOriginals`

Add to `src/Entity/Event.php`:

```php
#[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
private bool $retainOriginals = false;

public function isRetainOriginals(): bool { return $this->retainOriginals; }
public function setRetainOriginals(bool $retainOriginals): void { $this->retainOriginals = $retainOriginals; }
```

Migration generated via `bin/console doctrine:migrations:diff` (never hand-written per CLAUDE.md). Edit only `getDescription()` if needed.

## 2. Photo count helper — `PhotoRepository::countForEvent(Event): int`

No existing helper counts photos of *any* status (`countReady` is Ready-only). Add:

```php
public function countForEvent(Event $event): int
{
    return (int) $this->createQueryBuilder('p')
        ->select('COUNT(p.id)')
        ->andWhere('p.event = :event')
        ->setParameter('event', $event)
        ->getQuery()
        ->getSingleScalarResult();
}
```

Used for the form lock decision.

## 3. Form — `EventType`

Add a **mapped** `retainOriginals` checkbox:

```php
$builder->add('retainOriginals', CheckboxType::class, [
    'required' => false,
    'disabled' => $options['lock_retain_originals'],
    'label'    => 'Keep original photos',
    'help'     => 'Retains the full-resolution originals (up to 10 MB each) — uses '
        . 'significantly more storage than the web derivatives. Can only be changed '
        . 'while the event has no photos.',
]);
```

New option:

```php
$resolver->setDefaults([..., 'lock_retain_originals' => true]); // safe default: locked
$resolver->setAllowedTypes('lock_retain_originals', 'bool');
```

**Why `disabled` and not an unmapped-listener guard:** Symfony's `disabled` option preserves the model's current value and *ignores* any submitted data for that field. That gives us tamper-proof server-side enforcement of the 0-photos rule for free — a crafted POST cannot flip the flag when the field is disabled. (Contrast `notificationsEnabled`, which uses the unmapped-listener pattern because its enablement depends on mail state, not a lock.)

`EventController` create + edit actions pass:

```php
'lock_retain_originals' => $this->photos->countForEvent($event) > 0,
```

- **New event** (create form): the transient `Event` has no photos → `countForEvent` returns 0 → unlocked.
- **Edit** with any photos → locked.

Render note: a `disabled` checkbox needs no template change beyond adding `{{ form_row(form.retainOriginals) }}` to `templates/admin/event/` edit/new forms; Symfony renders the `disabled` attribute automatically. Confirm the field appears in whichever template renders `EventType` (check both create and edit templates).

## 4. Ingest — `ProcessPhotoHandler`

Gate both delete calls behind the event flag. Replace the two direct `deleteOriginalQuietly()` calls (success + failure paths) with a guarded helper:

```php
private function maybeDeleteOriginal(Event $event, string $path, int $photoId): void
{
    if ($event->isRetainOriginals()) {
        return; // retain: original stays at event-<id>/<photoId>.jpg
    }
    $this->deleteOriginalQuietly($path, $photoId);
}
```

Call `$this->maybeDeleteOriginal($event, $path, (int) $photo->getId())` in both the `markReady` and `markFailed` branches. `$event` is already in scope (`$photo->getEvent()`).

## 5. Event delete — `EventController::delete`

Today `delete()` only does `em->remove($event)` + cascade — **no storage cleanup**, so retained originals (and, already today, thumbs/previews) would orphan.

Inject the three storages (mirroring `PhotoController`):

```php
#[Autowire(service: 'photo_originals_storage')] private readonly FilesystemOperator $originals,
#[Autowire(service: 'photo_thumbs_storage')]    private readonly FilesystemOperator $thumbs,
#[Autowire(service: 'photo_previews_storage')]  private readonly FilesystemOperator $previews,
```

In `delete()`, snapshot the id **before** removal, then best-effort `deleteDirectory`:

```php
$eventId = (int) $event->getId();
// ... existing audit snapshot ...
$this->em->remove($event);
$this->em->flush();

$dir = sprintf('event-%d', $eventId);
foreach ([$this->originals, $this->thumbs, $this->previews] as $fs) {
    try {
        $fs->deleteDirectory($dir);
    } catch (FilesystemException) {
        // Best-effort — event may have had no photos / no derivatives.
    }
}
```

Mirrors `PhotoController::deleteAll` exactly. Removes retained originals **and** fixes the latent thumb/preview orphan.

## 6. Single-photo delete — `PhotoController::delete`

Already deletes `event-<id>/<photoId>.jpg` on all three storages best-effort (`:238–245`). **No code change** — acceptance criterion already met. Add a regression test asserting the original is removed.

## 7. Export/import — carry originals when retained (#101)

### Manifest (`EventArchiveManifest` + `ManifestEvent`)

Add `retainOriginals` (bool) to the event section.
- `ManifestEvent`: add `public bool $retainOriginals` constructor param (append at end).
- `toArray()`: add `'retainOriginals' => $this->event->retainOriginals` under `event`.
- `fromJson()`: read `(bool) ($event['retainOriginals'] ?? false)` — **defaults false when absent**, so pre-#110 archives import unchanged.
- `VERSION` stays `1` (purely additive, forward/backward compatible).

### Exporter (`EventArchiveExporter`)

- Inject `#[Autowire(service: 'photo_originals_storage')] FilesystemOperator $originals`.
- Set the manifest flag from `$event->isRetainOriginals()`.
- When retain is on, for each **Ready** photo also add the original:
  ```php
  if ($event->isRetainOriginals()) {
      $zip->addFromString('photos/' . $hash . '.original.jpg', $this->originals->read($path));
  }
  ```
  (Inside the existing `foreach ($ready as $photo)` loop, alongside the thumb/preview writes.)

Only **Ready** photos are exported (unchanged behavior). Failed-photo originals are not carried — consistent with import reconstituting only Ready photos.

### Importer (`EventArchiveImporter`)

- Inject `#[Autowire(service: 'photo_originals_storage')] FilesystemOperator $originals`.
- In `reconstitute()`, after setting other event fields: `$event->setRetainOriginals($manifest->event->retainOriginals);`
- In `reconstitutePhoto()`, when `$event->isRetainOriginals()`:
  ```php
  $originalBytes = $this->readJpeg($zip, 'photos/' . $mp->contentHash . '.original.jpg');
  // ... after $path is computed ...
  $this->originals->write($path, $originalBytes);
  $written[] = [$this->originals, $path];
  ```
  `readJpeg` already throws `InvalidArchiveException` on a missing/non-JPEG entry — so a **retain-on archive with a missing original hard-fails the import**, keeping the "consistent for every original" invariant intact. Pass `$event` into `reconstitutePhoto` (add param) so it can read the flag; `$event` is already available at the call site.
- Rollback: originals are tracked in `$written`, so the existing catch-block cleanup deletes them on failure.

## 8. Reduce per-photo upload cap: 25 MB → 10 MB

Touch points (all confirmed via grep):

| File | Change |
|---|---|
| `src/Controller/Admin/PhotoController.php:35` | `MAX_BYTES = 10 * 1024 * 1024` |
| `assets/controllers/photo_uploader_controller.js:3` | `MAX_BYTES = 10 * 1024 * 1024` |
| `assets/controllers/photo_uploader_controller.js:22` | hint text "up to **10** MB each" |
| `assets/controllers/photo_uploader_controller.js:109` | error "Too large (>**10** MB)" |
| `CLAUDE.md` (ingest pipeline §) | "JPEG only, ≤**10** MB" |

`10 * 1024 * 1024` stays a named `const`, so `phpmnd` is satisfied. Server-side is authoritative (returns `413`); the JS cap is a UX pre-check. No migration or data change — existing photos are unaffected (the cap only gates new uploads).

## 9. Tests

**Unit — `Event`:** `retainOriginals` defaults false; getter/setter round-trip.

**Unit/Integration — `ProcessPhotoHandler`:**
- retain **on** → original survives after success (Ready) and after domain rejection (Failed).
- retain **off** → original deleted after both paths (existing behavior preserved).

**Functional — `EventType` / `EventController`:**
- Edit form with ≥1 photo → `retainOriginals` field rendered `disabled`.
- New event / event with 0 photos → field editable; submitting `on` persists `true`.
- Tampered POST setting `retainOriginals=1` while locked (photos exist) → flag unchanged (disabled-field enforcement).

**Functional/Integration — event delete:** deleting an event removes `event-<id>/` on originals, thumbs, and previews (assert all three gone).

**Regression — single-photo delete:** deleting one photo removes its original from `photo_originals_storage`.

**Integration — export/import:**
- Export of a retain-on event writes `photos/<hash>.original.jpg` entries + `retainOriginals: true` in the manifest.
- Import of that archive restores originals to `photo_originals_storage` and sets `retainOriginals` true on the new event.
- Full round-trip (export → import) preserves the flag and originals.
- Pre-#110 archive (no `retainOriginals` key) imports with the flag `false` and no original writes.
- Retain-on archive with a missing `.original.jpg` entry → `InvalidArchiveException`, transaction rolled back, no partial writes.

**Upload cap:** a >10 MB (e.g. 11 MB) JPEG upload returns `413`; a ≤10 MB upload proceeds. (Extend the existing photo-upload functional test if present.)

## Acceptance criteria (from #110, + additions)

- [ ] `Event` has a `retainOriginals` toggle, default off.
- [ ] Toggle editable only when the event has 0 photos; disabled/locked otherwise (tamper-proof server-side).
- [ ] Retain on → `ProcessPhotoHandler` keeps the original after ingest (success and failure paths).
- [ ] Retain off → behaviour unchanged (original deleted post-ingest).
- [ ] Deleting an event removes all retained originals (and thumbs/previews) for that event — no orphaned `event-<id>/` files.
- [ ] Deleting a single photo removes its retained original.
- [ ] Export carries originals when retain is on; import restores them and the flag; retain-on archive missing an original fails cleanly.
- [ ] Per-photo upload cap is 10 MB (server + client + UI hint + docs).

## Out of scope

- Re-ingest of retained originals (#112).
- Paid-original / cart flows (#103).
- Retroactive recovery of originals for events that already ingested with retain off.
