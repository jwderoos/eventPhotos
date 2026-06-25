# Audit trail for admin actions — #75

**Status:** Design approved — ready for implementation plan.
**Date:** 2026-06-25
**Branch:** `feature/75-audit-trail`
**Author:** Claude (design), commissioned by @jwderoos.

---

## 0. Goal

A durable, append-only record answering *"who changed/deleted X, and when?"* across the
`/admin/**` surface, plus cross-cutting auth events (login success/failure, logout, OAuth
link, invite redemption). Serves debugging and trust (multi-organizer events, shared
collections).

Read-only. No mutation, no edit, no soft-delete of audit rows once written.

---

## 1. Decisions locked during brainstorming

| Decision | Choice | Why |
|---|---|---|
| **Trigger paradigm** | **Action-driven**, not entity-change-driven | Goal is "who did what" + auth events. A Doctrine flush-listener can't see login failures (no entity change), floods the log with async-worker writes (~8k `Photo: Pending→Ready` per event, no logged-in user), and records data deltas instead of intent. Mirrors the existing `UserMailConfigAudit` precedent. |
| **Record detail** | **Fixed columns + flexible JSON `context`** | One schema fits create/edit/delete/auth; per-action detail (edit diffs, deleted-entity snapshot, role change) scales into `context`. |
| **Wiring** | **`#[Audited]` attribute + kernel listeners** (Approach A′) | Declarative intent co-located with the action; centralized writing (no `AuditLogger` injected into 10 controllers); idiomatic Symfony (same shape as `#[IsGranted]`/`#[Cache]`); fits the codebase's "PHP attributes everywhere" grain; makes coverage testable. |
| **UI** | **Read-only viewer in v1** | `GET /admin/audit`, filterable, paginated. The durable log has value the moment it's written; the viewer makes it usable without DB access. |
| **Visibility** | **`ROLE_ADMIN` only, global** | Keeps the admin surface neutral, no per-entity ownership filtering. Organizer-scoped views are a deferred follow-up. |
| **Retention** | **Indefinite for v1** | Admin actions are low-volume (hundreds–low-thousands of rows; individual photo uploads excluded). Durability is the point. A purge command is a deferred follow-up. |

---

## 2. Why action-driven over a bundle (the framing correction)

Issue #75's table compares a custom table vs. `damienharper/auditor-bundle` vs.
`simplethings/entity-audit-bundle`. That is a *tooling* comparison that hides the real
fork: **what triggers a record.** All three of those options are entity-change-driven
(fire on Doctrine flush). Three structural mismatches with the stated goal:

1. **Can't see auth events.** Login/logout/login-failure flush no entity. Login *failure*
   especially. A second mechanism is needed for those regardless.
2. **Captures the worker, not just admins.** Every async `Photo` status transition is an
   entity change → thousands of system rows per event, all with a null actor.
3. **Records data deltas, not intent.** "column `role` changed" ≠ "admin used the
   promote-to-organizer action."

Action-driven inverts all three. Cost: we touch the action sites instead of one listener,
and field-level diffs aren't automatic — we compute them where we log (only where they
matter). The `#[Audited]` attribute keeps the per-site cost to one annotation.

---

## 3. Schema — `audit_log`

Append-only, write-once. **Generated via `bin/console doctrine:migrations:diff`** — never
hand-written (CLAUDE.md § Migrations: hand-written index names drift from Doctrine's hash
algorithm).

```
audit_log
─────────────────────────────────────────────────────────────
id            BIGINT      identity PK         append-only; bigint headroom
action        VARCHAR     NOT NULL            'event.delete', 'auth.login_failure', …
actor_id      INT         NULL                soft ref to users.id — NOT a FK
actor_label   VARCHAR     NULL                email snapshot at action time
target_type   VARCHAR     NULL                short class name: 'Event','User','Invitation'
target_id     INT         NULL                target's id — NOT a FK
target_label  VARCHAR     NULL                readable snapshot: event name / slug
context       JSON        NULL                diffs / deleted-entity snapshot / extra (jsonb on PG)
ip_address    VARCHAR(45) NULL                string, not PG inet (portable → MySQL #73/#74)
created_at    DATETIMETZ_IMMUTABLE NOT NULL   matches Invitation convention
─────────────────────────────────────────────────────────────
indexes (Doctrine-named, via diff): (created_at), (target_type, target_id), (actor_id), (action)
```

### Deliberate denormalizations — both for audit integrity

- **`actor_id` / `target_id` are plain `int` columns, not Doctrine associations / FKs.**
  A delete-audit's whole purpose is that the target row is *gone*; a FK would either block
  the delete or cascade-erase the audit row. Same for actors — deleting a user must not
  erase their trail. We keep the id for correlation **plus** a `*_label` snapshot so the
  viewer stays readable after the referent is deleted.
- **`ip_address` is `VARCHAR(45)`, not PostgreSQL `inet`.** A MySQL/MariaDB second
  deployment is on the roadmap (#73/#74); `inet` is PG-only. A string is portable and
  sufficient for display (45 chars covers IPv4-mapped IPv6).

### Entity

`App\Entity\AuditLogEntry` — constructor-only. No setters, no `updatedAt`, no `#[ORM\PreUpdate]`.
Once written it never changes. `App\Repository\AuditLogEntryRepository` holds one paginated,
filtered query method (§7).

### `schema_filter` note

`config/packages/doctrine.yaml` carries `schema_filter: ~^(?!sessions$).*~` to exclude the
non-entity `sessions` table. `audit_log` **is** a Doctrine entity, so it is *not* excluded —
no change to the filter needed. (Flagged because anyone touching that regex must not
accidentally exclude `audit_log`.)

---

## 4. `AuditAction` enum

`App\Audit\AuditAction` — backed `string` enum. The fixed vocabulary: type-safety at call
sites, drives the viewer's filter dropdown, and exposes `label(): string` (human-readable)
and `category(): string` (grouping) for the UI.

```
Event:       event.create · event.edit · event.delete · event.publish · event.notifications_toggle
Collection:  collection.create · collection.edit · collection.delete
Photo:       photo.delete · photo.delete_all · photo.retry          ← NOT photo.upload
User:        user.create · user.edit · user.role_change · user.delete · user.send_reset
Invitation:  invite.create · invite.revoke
Mail config: mailconfig.update · mailconfig.verify · mailconfig.resend · mailconfig.clear
Session:     session.revoke
Auth:        auth.login_success · auth.login_failure · auth.logout
Identity:    oauth.link · invite.redeem
```

`photo.upload` is **deliberately absent**: at the ~8k-photo hiking-event scale (#79/#80)
blanket upload logging would dump ~8k noise rows into the trail. Per-action attribute
control lets us simply not annotate `upload` — a blanket entity-listener could not make this
distinction cleanly. `delete` / `delete_all` / `retry` *are* audited.

---

## 5. Components

### Attributes — `App\Audit\Attribute\`

- `#[Audited(AuditAction $action, ?string $targetParam = null, ?string $targetType = null)]`
  — `Attribute::TARGET_METHOD`. `targetParam` names the route param carrying the target id
  (`'id'`, `'eventId'`, `'photoId'`); `targetType` is the short class name for `target_type`.
- `#[AuditIgnore]` — explicitly marks a mutating route as intentionally un-audited (e.g.
  `photo.upload`) so the §8 coverage test stays green. Forces a conscious decision on every
  new mutating route.

### Writer & enrichment — `App\Audit\`

- **`AuditLogger`** — the single low-level writer. Builds an `AuditLogEntry`, persists +
  flushes it in its **own unit of work**, and **never throws into the caller** (logs failure
  via the injected logger). Used by both the terminate listener and the auth listener.
  Signature roughly:
  `log(AuditAction $action, ?int $actorId, ?string $actorLabel, ?string $targetType, ?int $targetId, ?string $targetLabel, array $context, ?string $ip): void`.
- **`AuditContext`** — request-scoped enrichment, backed by the **request attribute bag**
  (via `RequestStack`) so there is no shared-state leakage between requests (matters under
  any process reuse). Optional API for actions wanting rich detail:
  - `snapshot(object $entity): void` — capture key fields **before** a delete (the row will
    be gone by terminate).
  - `changed(string $field, mixed $old, mixed $new): void` — record an edit diff.
  - `set(string $key, mixed $value): void` — arbitrary context.
  - `suppress(): void` — escape hatch to cancel logging on a guard-failure-then-redirect
    path (see §6 rough edge).

### Listeners — `App\EventListener\Audit\`

- **`AuditedControllerListener`** (`kernel.controller`) — reflects `#[Audited]` off the
  resolved controller callable, stashes the descriptor into the request attributes.
  Idiomatic Symfony (mirrors how `#[IsGranted]` is resolved).
- **`AuditTerminateListener`** (`kernel.terminate`) — when a descriptor is present **and**
  the response succeeded (`Response::isRedirection()`, matching the post/redirect/get
  convention) **and** `AuditContext` was not `suppress()`-ed: resolves the actor from
  `TokenStorage`, extracts `target_id` from the named route param, merges `AuditContext`,
  reads client IP, and calls `AuditLogger`. Runs after the response is flushed to the client,
  so a logging failure cannot break the user's request and adds no latency.
- **`AuthEventListener`** — listens to `LoginSuccessEvent` → `auth.login_success`,
  `LoginFailureEvent` → `auth.login_failure` (actor null; attempted username + reason into
  `context`), `LogoutEvent` → `auth.logout`. Firewall events, not controller actions, so it
  calls `AuditLogger` directly. Kept separate from the existing `UserSessionLoginListener`
  (single responsibility).

### Identity flows

`oauth.link` (`GoogleLinkController` / `IdentityLinker`) and `invite.redeem`
(`GoogleInviteController` / `IdentityCreator`) go through controllers, so they carry
`#[Audited]`. Rich detail (which user linked, which invite consumed) is enriched from the
service via `AuditContext` (services inject `AuditContext`, which wraps `RequestStack`).

### Viewer — `App\Controller\Admin\`

- **`AuditLogController`** — `GET /admin/audit`, `#[IsGranted('ROLE_ADMIN')]`. Filters:
  actor, action (enum dropdown), target type + id, date range. Offset pagination.
- **`templates/admin/audit/index.html.twig`** — reuses the existing `table table-zebra`
  pattern (cf. `templates/admin/user/index.html.twig`). Columns: time, actor, action
  (badge), target, context summary, IP. Nav link added to `templates/admin/_base.html.twig`,
  gated on `ROLE_ADMIN`.

---

## 6. Known rough edge — success inferred from response status

Success is inferred from the response being a `3xx` redirect. Correct for:
- the success path (post/redirect/get → 302), and
- hard failures (`denyAccessUnlessGranted` throws 403; exceptions → 500) → not `3xx` → not
  logged.

**The one leak:** an action that fails a guard but still redirects with an error flash
(e.g. invalid CSRF → `addFlash('error', …)` + redirect) would be recorded as success.

**Mitigation:** those paths call `$audit->suppress()`. During implementation, audit which
admin controllers flash-and-redirect on a failure branch and apply `suppress()` there. This
is the deliberate price of inferring intent from the response rather than threading an
explicit per-call success signal — accepted because it keeps the common path
injection-free, and the escape hatch is one line where needed.

---

## 7. Query path (viewer)

`AuditLogEntryRepository::findFiltered(filters, page, perPage): array` — builds a QueryBuilder
applying any subset of {actor_id, action, target_type, target_id, created_at from/to},
orders by `created_at DESC, id DESC`, applies `LIMIT/OFFSET`. Returns rows + total count for
pagination. No N+1 (all display data is denormalized onto the row — that's what the `*_label`
snapshots are for).

---

## 8. Testing

- **Unit:** `AuditAction::label()`/`category()` exhaustiveness; `AuditContext` enrichment +
  `suppress()`; `AuditLogger` writes the expected row and swallows write failures.
- **Functional:**
  - Representative mutating routes each produce the expected row (action, actor_id/label,
    target_type/id/label, context, ip). At minimum: `event.delete` (+ snapshot),
    `user.role_change` (+ diff), `invite.revoke`, `auth.login_success`, `auth.login_failure`.
  - **Coverage test:** every mutating route under `/admin` (POST, or non-GET) carries either
    `#[Audited]` or `#[AuditIgnore]`. This is the guarantee that pure explicit-call logging
    could never give. Implemented by reflecting over `App\Controller\Admin\*` actions and
    inspecting route + attribute metadata.
  - Viewer: `ROLE_ORGANIZER` → 403; `ROLE_ADMIN` → 200; filters narrow results correctly.

PHPUnit 13 here fails on any deprecation/notice/warning in the code path — the audit write
path must be clean (no deprecations from `RequestStack`/`TokenStorage` access).

---

## 9. Out of scope (v1) — deferred follow-ups

- Organizer-scoped viewing (organizers seeing audit entries for their own events) — needs
  ownership filtering + a voter + a rule for non-entity events. File if demand appears.
- Retention / purge command. Indefinite retention for now.
- CSV/JSON export.
- Auditing CLI mutations (`app:create-user`) — no HTTP request / actor context; out of the
  `/admin` HTTP surface this issue targets.
- Folding the existing `UserMailConfigAudit` into the unified log. The mail-config actions
  are covered by new `mailconfig.*` audit rows; the richer domain-specific
  `UserMailConfigAudit` record stays as-is. Unifying is a separate cleanup.
- Individual `photo.upload` events (volume — see §4).

---

## 10. Files (anticipated)

```
src/Entity/AuditLogEntry.php                              new
src/Repository/AuditLogEntryRepository.php                new
src/Audit/AuditAction.php                                 new (enum)
src/Audit/AuditLogger.php                                 new
src/Audit/AuditContext.php                                new
src/Audit/Attribute/Audited.php                           new
src/Audit/Attribute/AuditIgnore.php                       new
src/EventListener/Audit/AuditedControllerListener.php     new
src/EventListener/Audit/AuditTerminateListener.php        new
src/EventListener/Audit/AuthEventListener.php             new
src/Controller/Admin/AuditLogController.php               new
templates/admin/audit/index.html.twig                    new
templates/admin/_base.html.twig                           edit (ROLE_ADMIN nav link)
src/Controller/Admin/*Controller.php                      edit (#[Audited] / #[AuditIgnore] + enrichment)
src/Service/Auth/IdentityLinker.php                       edit (enrichment)
src/Service/Auth/IdentityCreator.php                      edit (enrichment)
migrations/VersionYYYYMMDDHHMMSS.php                      generated via doctrine:migrations:diff
tests/Unit/Audit/*                                        new
tests/Functional/Audit/*                                  new
```
