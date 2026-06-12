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

## Google sign-in (optional)

Google SSO is a login method, not a signup path — new accounts still come from `app:create-user` or invite redemption. The feature is fully optional: with `GOOGLE_OAUTH_CLIENT_ID` empty, the Google buttons disappear and `/oauth/google/*` returns 404.

> Setting it up? Step-by-step guides: [`docs/setup/google-sso-dev.md`](docs/setup/google-sso-dev.md) (local dev) and [`docs/setup/google-sso-prod.md`](docs/setup/google-sso-prod.md) (production).

### Per-environment Google Cloud Console setup

For each environment (dev, staging, prod) you need one **OAuth 2.0 Web Client** in a Google Cloud project:

1. **Google Cloud project** — free tier is enough; no billing required.
2. **OAuth consent screen** — User type *External* (or *Internal* if Workspace-only). Scopes are just `openid`, `email`, `profile` (basic scopes — no Google verification review needed). While the screen is in *Testing*, each tester's Google address must be on the test-users list. Promote to *Production* once you're done testing (self-service for basic-scope apps).
3. **OAuth 2.0 Client ID** (type *Web application*):
   - **Authorized redirect URI** (exact match, per environment):
     - `http://localhost:8080/oauth/google/callback` (local dev)
     - `https://<staging-host>/oauth/google/callback`
     - `https://<prod-host>/oauth/google/callback`
   - No JavaScript origins needed — the dance is fully server-side.

Google issues a **Client ID** and **Client Secret**.

### Configuring the app

```bash
# Local dev: put real values in .env.local (gitignored)
GOOGLE_OAUTH_CLIENT_ID=<your-client-id>.apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=<your-client-secret>

# Then:
bin/console cache:clear
```

Prod uses the same env-var names, sourced from your secret store.

`localhost:8080` is one of the few `http://` redirect URIs Google accepts; staging/prod redirect URIs must be HTTPS.

### What ships out of the box

- `/login` shows a *Sign in with Google* button alongside the password form (gated by the feature flag).
- `/invite/{token}` shows a *Sign up with Google* button on the invite-redeem page.
- `/account` lets users link/unlink Google, change password, change display name.
- All `/oauth/google/*` routes use a single redirect URI (`/oauth/google/callback`) and dispatch internally by session-stashed purpose.

Refer to `docs/superpowers/specs/2026-06-12-19-google-sso-design.md` for the full architectural spec.
