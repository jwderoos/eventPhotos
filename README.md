## Photo ingest

Photos uploaded through the admin are processed asynchronously by a Symfony Messenger worker. The `worker` service in `compose.yaml` runs `bin/console messenger:consume async failed` and auto-restarts (`restart: unless-stopped`); it self-recycles every hour or at 128 MB to release any leaked GD memory.

```bash
docker compose up -d worker            # start (runs automatically on `docker compose up`)
docker compose logs -f worker          # tail
docker compose restart worker          # restart after code changes
```

### Inspecting failed messages

```bash
docker compose exec php php bin/console messenger:failed:show
docker compose exec php php bin/console messenger:failed:retry <id>
```

### Storage layout

- Originals: `var/uploads/photos/originals/event-<id>/<photoId>.jpg` — private, never web-served
- Thumbnails: `var/uploads/photos/thumbs/event-<id>/<photoId>.jpg` — served via `/p/<id>/thumb.jpg`
- Previews: `var/uploads/photos/previews/event-<id>/<photoId>.jpg` — served via `/p/<id>/preview.jpg`
