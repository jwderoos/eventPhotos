# Photo fixtures

Generated once by `bin/make-photo-fixtures.php` (deleted after generation; recreate from git history if you need to regenerate).

- `with-datetime-original.jpg` — JPEG with EXIF `DateTimeOriginal=2026:06:10 12:34:56`, no offset.
- `with-offset-time.jpg`       — same, plus `OffsetTimeOriginal=+02:00`.
- `no-exif.jpg`                — plain JPEG, no EXIF metadata.
- `bigger.jpg`                 — ~1.8 MB JPEG for size/streaming tests (kept under the grumphp 2M file_size gate).
