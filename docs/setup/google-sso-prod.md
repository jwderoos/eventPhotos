# Google SSO — production setup

A step-by-step walkthrough for enabling "Sign in with Google" on the production deployment. Parallel to [`google-sso-dev.md`](google-sso-dev.md), but with the production-specific concerns: separate OAuth client, HTTPS-only redirect URI, secrets in `.env.prod` on the NAS, consent-screen promotion.

Throughout this guide, `photos.example.com` stands in for the real production domain — replace it everywhere with whatever the app is actually reached at.

## 0. Before you start

- Production must already be reachable at `https://photos.example.com` with a valid TLS cert (Let's Encrypt via Nginx Proxy Manager in our setup). Google rejects redirect URIs that aren't HTTPS in production — there is no `localhost`-style exception.
- The app must have at least one `User` row whose email matches a Google address you want to log in with. Google SSO is a login method, not a signup path; new accounts only come from `app:create-user` or invite redemption.

## 1. Create the production Google OAuth client

**Use a separate OAuth client from dev.** It can be in the same Cloud project as the dev client, or — preferred — in a dedicated `eventfotos-prod` project. Never reuse the dev credentials in prod: rotating one shouldn't force rotating the other, and the consent-screen status is per-project.

### 1.1 Pick / create the Cloud project

https://console.cloud.google.com → project picker → **New project** (e.g. `eventfotos-prod`). Free tier; no billing required.

### 1.2 Configure the OAuth consent screen

APIs & Services → **OAuth consent screen**.

- **User type:** *External* — unless every prod user is on the same Google Workspace, in which case *Internal* gives you a smaller blast radius.
- **App information:** real app name (shown on the consent screen), support email, app logo if you want branding, app home page `https://photos.example.com`, privacy policy + terms URLs if you have them.
- **Authorized domains:** add `example.com` (the apex of the production domain). Required once you list any HTTPS link.
- **Scopes:** exactly three, all *basic*:
  - `openid`
  - `.../auth/userinfo.email`
  - `.../auth/userinfo.profile`

  Because these are basic scopes, **no Google verification review is required** even after publishing.

**Publishing status — pick one:**

- *Testing* (default): unlimited duration for basic scopes, but only Google addresses on the **Test users** list can log in. Cap is 100. Suitable if your real user set is small and stable.
- *Production*: anyone with a Google account can attempt to log in. Promotion is self-service for basic scopes — click **Publish app**. (Even when published, the *resolution algorithm* still only lets a Google identity in if a matching `User` row already exists, so this isn't a public-signup risk.)

For a small homelab / private deployment, *Testing* with a curated test-users list is usually the right answer.

### 1.3 Create the OAuth 2.0 Client ID

APIs & Services → **Credentials** → **Create credentials** → **OAuth client ID**.

- **Application type:** *Web application*
- **Name:** `eventfotos prod` (cosmetic)
- **Authorized JavaScript origins:** leave empty.
- **Authorized redirect URIs** — add exactly one:
  ```
  https://photos.example.com/oauth/google/callback
  ```
  HTTPS, exact match, no trailing slash. The app uses a single dispatcher URI per environment and routes internally by purpose (login / link / invite).

Click **Create**. Copy the **Client ID** and **Client Secret** — you'll paste them into `.env.prod` on the NAS in step 2. Store them in your password manager too; the Console will let you retrieve them, but treat them as you would any production secret.

## 2. Configure the app on the NAS

SSH to the NAS, then edit `.env.prod` in the repo checkout (`/mnt/Data/apps/eventfotos/repo` in this deployment):

```bash
ssh nas
cd /mnt/Data/apps/eventfotos/repo
vi .env.prod        # add the two vars below, save
chmod 600 .env.prod # ensure it stays locked down
```

Append:

```bash
###> knpuniversity/oauth2-client-bundle ###
GOOGLE_OAUTH_CLIENT_ID=123...apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=GOCSPX-...
###< knpuniversity/oauth2-client-bundle ###
```

The presence of `GOOGLE_OAUTH_CLIENT_ID` (non-empty) flips `GoogleOAuthFeatureFlag::isEnabled()` to true at runtime, which registers the `/oauth/google/*` routes and renders the Google buttons.

Apply by restarting the PHP service so it re-reads the env:

```bash
./deploy.sh         # safe option: full re-deploy
# or, if you only changed env vars:
docker compose -f compose.prod.yaml restart php worker
docker compose -f compose.prod.yaml exec php bin/console cache:clear --env=prod
```

Worker is restarted alongside `php` because it also reads the env — not strictly required for SSO itself, but cheaper than chasing a stale config later.

## 3. Verify

1. Open `https://photos.example.com/login` in a fresh browser session.
2. The **Sign in with Google** button should be visible alongside the password form. If it's not, the feature flag isn't picking up the env — see Troubleshooting.
3. Click it, complete the Google consent flow, and confirm you land on `/admin`.
4. Visit `/account` — the linked Google identity should appear under linked identities.

For the invite-redemption path: hit `https://photos.example.com/invite/<token>` and check the "Sign up with Google" button is rendered.

## Rotation

To rotate the secret without downtime:

1. In the Console: open the OAuth client → **Add secret** (Google allows two active secrets per client). Don't delete the old one yet.
2. Update `.env.prod` with the new `GOOGLE_OAUTH_CLIENT_SECRET`.
3. Restart `php` + `worker`.
4. Verify a fresh login works.
5. Back in the Console, delete the old secret.

The Client ID itself rotates only if you create a new OAuth client — which also means updating the registered redirect URI elsewhere (you don't, in this case, since the URI is identical).

## Troubleshooting

| What you see | What's wrong | Fix |
|---|---|---|
| `redirect_uri_mismatch` | The redirect URI Google sees doesn't exactly match what's registered | Must be `https://photos.example.com/oauth/google/callback` — HTTPS, no trailing slash, exact host. Check the `DEFAULT_URI` env var and `TRUSTED_PROXIES` so URL generation behind Nginx Proxy Manager produces HTTPS. |
| `access_blocked: <app> has not completed Google verification` | The consent screen is in *Testing* and the Google address isn't on the test-users list | Add the address under OAuth consent screen → Test users, or publish the app. |
| `/oauth/google/login` returns 404 in prod | Feature flag is off — env var not picked up | `docker compose -f compose.prod.yaml exec php bin/console debug:dotenv` should show `GOOGLE_OAUTH_CLIENT_ID` set. If empty, confirm `.env.prod` is in the repo dir and the `php` container was restarted after the edit. |
| Sign-in completes but you're refused with a flash about no matching account | No `User` row exists with that Google email | Create the user (`app:create-user`) or issue an invite. Google never auto-creates accounts. |
| Redirect lands on `http://photos.example.com/...` instead of HTTPS | The app isn't trusting the proxy's `X-Forwarded-Proto` | Confirm `TRUSTED_PROXIES=REMOTE_ADDR` is set in `.env.prod` and Nginx Proxy Manager is forwarding `X-Forwarded-Proto: https`. |
| `invalid_client` after rotation | Old secret still cached or wrong secret pasted | Re-paste from the Console, restart `php`. If you accidentally deleted the active secret, create a new one. |

## Pointers

- Local-dev guide: [`google-sso-dev.md`](google-sso-dev.md)
- Per-environment reference: the *Google sign-in* section in the project [`README.md`](../../README.md)
- Full architectural spec: [`../superpowers/specs/2026-06-12-19-google-sso-design.md`](../superpowers/specs/2026-06-12-19-google-sso-design.md)
- Feature-flag source of truth: `App\Service\Auth\GoogleOAuthFeatureFlag`
- Login-resolution algorithm: `App\Service\Auth\IdentityLinker::resolveLogin()`
