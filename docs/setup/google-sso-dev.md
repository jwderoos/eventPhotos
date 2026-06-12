# Google SSO — local dev setup

A hands-on walkthrough for getting "Sign in with Google" working against your local `http://localhost:8080` stack. The README has the per-environment reference; this guide is the step-by-step.

Google SSO is **optional**. With `GOOGLE_OAUTH_CLIENT_ID` empty the app runs normally — no Google UI, `/oauth/google/*` returns 404. Skip this whole guide if you don't need it.

## 1. Create the Google OAuth client

### 1.1 Pick a Cloud project

https://console.cloud.google.com → project picker → **New project**. Any name (e.g. `eventfotos-dev`). No billing required.

### 1.2 Configure the OAuth consent screen

APIs & Services → **OAuth consent screen**.

- **User type:** *External* (use *Internal* only if you're on Workspace and want to restrict to your domain).
- **App information:** app name, support email, developer contact — your email is fine for dev.
- **Scopes:** add exactly three, all under "basic scopes":
  - `openid`
  - `.../auth/userinfo.email`
  - `.../auth/userinfo.profile`

  Basic scopes mean **no Google verification review is required**.
- **Test users:** add your own Google address (and any other devs/testers). Cap is 100. The app stays in *Testing* status — that's correct for dev.

### 1.3 Create the OAuth 2.0 Client ID

APIs & Services → **Credentials** → **Create credentials** → **OAuth client ID**.

- **Application type:** *Web application*
- **Name:** `eventfotos dev` (cosmetic)
- **Authorized JavaScript origins:** leave empty — the OAuth dance is fully server-side.
- **Authorized redirect URIs** — add exactly one:
  ```
  http://localhost:8080/oauth/google/callback
  ```
  This is the *only* redirect URI for the dev environment. The app uses one dispatcher URI per environment and routes internally by purpose (login / link / invite).

Click **Create**. Google shows a **Client ID** (looks like `123...apps.googleusercontent.com`) and a **Client Secret**. Copy both — you can retrieve them again later from the Credentials page.

## 2. Configure the app

Add the credentials to `.env.local` (gitignored — never put secrets in `.env`):

```bash
GOOGLE_OAUTH_CLIENT_ID=123...apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=GOCSPX-...
```

Pick up the new env:

```bash
docker compose restart php
bin/console cache:clear
```

The presence of `GOOGLE_OAUTH_CLIENT_ID` flips `GoogleOAuthFeatureFlag::isEnabled()` to true, which:
- renders the Google button on `/login`, `/invite/{token}`, and `/account`
- registers the `/oauth/google/*` routes (otherwise they 404)

## 3. Make a user that matches your Google address

Google SSO is a **login** method, not a signup path. New accounts still come from `app:create-user` or invite redemption. So before you can click "Sign in with Google", a `User` row with `email = <your Google address>` must already exist.

Easiest path for dev:

```bash
bin/console app:create-user you@gmail.com "Your Name" 'somepassword' ROLE_ORGANIZER
```

(Or accept an invite via Google on `/invite/{token}` — that path creates the user inside the redemption transaction.)

## 4. Try it

1. `docker compose up -d` (if not already running)
2. Open http://localhost:8080/login
3. Click **Sign in with Google**
4. Pick the Google account whose email matches the user you just created
5. You should land on `/admin`

Then visit `/account` to see the linked identity and the link/unlink controls.

## Troubleshooting

| What you see | What's wrong | Fix |
|---|---|---|
| `redirect_uri_mismatch` | The redirect URI Google sees doesn't exactly match what's registered | The URI must be `http://localhost:8080/oauth/google/callback` — not `127.0.0.1`, not a trailing slash, not HTTPS. Edit the OAuth client and re-add. |
| `access_blocked: <app> has not completed Google verification` | Your Google account isn't on the test-users list and the consent screen is still in *Testing* | Add the address under OAuth consent screen → Test users. |
| `/oauth/google/login` returns 404 | Feature flag is off — `GOOGLE_OAUTH_CLIENT_ID` not picked up | Check `.env.local` is in the project root, restart `php` service, `bin/console debug:dotenv` to confirm. |
| Login completes but you're refused | Either your Google email isn't `email_verified=true`, or no `User` row exists with that email | Verify the Google account; or create the matching user (step 3). |
| "Sign in with Google" button missing on `/login` | Same as the 404 case above | Same fix. |

## Pointers

- Full architectural spec: [`docs/superpowers/specs/2026-06-12-19-google-sso-design.md`](../superpowers/specs/2026-06-12-19-google-sso-design.md)
- Per-environment reference (staging/prod): the *Google sign-in* section in the project [`README.md`](../../README.md)
- Feature-flag source of truth: `App\Service\Auth\GoogleOAuthFeatureFlag`
- Login-resolution algorithm: `App\Service\Auth\IdentityLinker::resolveLogin()`
