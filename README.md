## Photo ingest

Photos uploaded through the admin are processed asynchronously by a Symfony Messenger worker. To process the queue in local dev, run:

```bash
php bin/console messenger:consume async failed -vv
```

(Add to your Procfile/supervisor/systemd of choice for non-dev environments — out of scope for this app.)

### Inspecting failed messages

```bash
php bin/console messenger:failed:show
php bin/console messenger:failed:retry <id>
```

### Storage layout

- Originals: `var/uploads/photos/originals/event-<id>/<photoId>.jpg` — private, never web-served
- Thumbnails: `var/uploads/photos/thumbs/event-<id>/<photoId>.jpg` — served via `/p/<id>/thumb.jpg`
- Previews: `var/uploads/photos/previews/event-<id>/<photoId>.jpg` — served via `/p/<id>/preview.jpg`
