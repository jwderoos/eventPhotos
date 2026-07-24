# #106 — Cart payment model: merchant-of-record / payout routing

**Status:** Design approved 2026-07-24
**Issue:** [#106](https://github.com/jwderoos/eventPhotos/issues/106)
**Parent:** [#103](https://github.com/jwderoos/eventPhotos/issues/103) (shopping-cart epic)
**Unblocks:** [#107](https://github.com/jwderoos/eventPhotos/issues/107) (payment integration)

## Goal

Decide who is merchant-of-record, who carries VAT/tax liability, and how the platform earns revenue when visitors buy watermark-free originals. This decision drives the entire payment integration (#107) — provider choice, onboarding plumbing, refund flow, and tax exposure.

## Decision

**Provider: Mollie. Model: Mollie Connect, connected accounts. Revenue: a flat per-transaction `applicationFee` (decision "B1").**

The organizer is merchant-of-record on their own connected Mollie account; Mollie performs their KYC; the platform is **never a payment facilitator**. Funds settle directly to the organizer. The platform retains a fixed fee per transaction, auto-routed at capture.

## Rationale

### Why Mollie over Stripe

- **Market is NL/Benelux only.** Mollie is iDEAL-native with flat per-transaction pricing and no monthly fee. Because organizers use their *own* Mollie accounts, that per-transaction fee is the *organizer's* cost, not the platform's — so the axis that matters is organizer onboarding friction, where "Connect with Mollie" OAuth and Dutch-language onboarding win for low-volume organizers.
- **Wero drops out of the decision.** iDEAL is folding into Wero (EPI), but Wero is an account-to-account rail carried by both Mollie and Stripe as the transition happens — provider-neutral, so it does not tilt the choice. (Note: Wero's A2A nature keeps dispute exposure lower than cards, but EPI is adding merchant buyer-protection features, so do not model iDEAL as permanently chargeback-free.)
- **Stripe's edge (DX, global reach, subscription tooling) is not decisive** for a Benelux-only, one-off-purchase marketplace.

### Why connected accounts (was "Model B")

The regulatory win — not becoming a licensed payment facilitator, no KYC/AML burden on the platform — comes purely from **connected accounts where the provider KYCs the organizer and the organizer is merchant-of-record**. This holds whether or not the platform takes a cut.

### Why a flat application fee (B1), not separate invoicing (B2)

An earlier idea was "the platform never touches the money and invoices organizers for usage separately." Rejected: it buys **no** extra regulatory safety over connected-accounts-with-a-fee (both are PayFac-free), while adding a dunning subsystem and bad-debt risk (service delivered, money already with the organizer, platform chasing an invoice).

Since the usage metric is **transaction count**, a flat `applicationFee` amount per transaction (independent of sale value) *is* that usage-based charge — collected automatically, auto-reconciled, zero collections risk. Reserve separate invoicing only for a future charge that is not a payment event (e.g. storage, per-event, seats).

## Locked decisions

| | |
|---|---|
| Provider | Mollie (Mollie Connect / "Mollie for Platforms"). |
| Merchant of record | The organizer, on their own connected Mollie account. Platform is not a PayFac. |
| Onboarding | Mollie Connect OAuth ("Connect with Mollie"), mirroring the Google SSO OAuth wiring. |
| Platform revenue | Flat `applicationFee` amount per payment (not a % of GMV), independent of sale value. |
| VAT/tax | Organizer owes VAT on the sale; platform owes VAT only on its own application fee. |
| Refunds | Organizer-initiated (they are MoR). Application-fee reversal behaviour on refund to be pinned during the validation spike. |
| Payout/reconciliation | Funds settle to the organizer's Mollie account on Mollie's normal schedule; platform reconciles application-fee payouts to its own account. |
| Credential storage | Per-organizer Mollie Connect tokens encrypted at rest via the #77 `DsnVault` (libsodium `crypto_secretbox`) pattern; new env key `MOLLIE_CONFIG_ENCRYPTION_KEY`. |
| Amount format | Mollie decimal-string amounts (`{"value":"10.00","currency":"EUR"}`) — NOT minor-unit integers. |

## Validation gate (before #107 implementation)

A €1 sandbox proof, gating the whole model:

1. OAuth-connect a test organizer Mollie account to the platform.
2. Create an **iDEAL** payment on that connected account carrying a flat `applicationFee`.
3. Confirm the payment settles to the connected account **and** the fee routes to the platform account.
4. Confirm Mollie **partner / commission-model prerequisites** — partner approval status and the minimum `applicationFee` Mollie enforces.
5. Confirm refund behaviour: whether the `applicationFee` is reversed proportionally on a refund.

If any step fails (esp. application fees not permitted on iDEAL, or partner approval unavailable), revisit B1 vs a percentage split or a fallback provider before writing #107 code.

## Reuse anchors (existing patterns #107 builds on)

- **Encrypted per-organizer secrets (#77):** `src/Service/Mail/DsnVault.php`, `src/Service/Mail/EncryptedDsn.php`, `src/Entity/UserMailConfig.php` (+ `UserMailConfigAudit`). Connected-Mollie credentials likely hang off `src/Entity/OrganizerProfile.php`, mirroring `UserMailConfig` → `User`.
- **OAuth onboarding (#19 Google SSO):** `config/packages/knpu_oauth2_client.yaml`, `src/Service/Auth/GoogleOAuthFeatureFlag.php` (route-condition gate), `src/Controller/OAuth/OAuthDispatcherController.php` + purpose controllers, `src/Service/Auth/IdentityLinker.php`.
- **Test fake swap:** `config/services.yaml` `when@test:` block — `FakeGoogleOAuthClient` and `InMemoryTransportFactory`. A `MollieClient` interface + `FakeMollieClient` mirror this (no live network).
- **State-machine discipline:** `src/Entity/Photo.php` + `src/Entity/PhotoStatus.php`. The Order/payment state machine mirrors `markReady`/`markFailed`/`resetForRetry` with `DomainException` on illegal moves.
- **Async confirmation:** `src/Message/ProcessPhoto.php`, `src/MessageHandler/ProcessPhotoHandler.php`, `config/packages/messenger.yaml` (`async` Doctrine transport, 3× retry, `failed` queue). The Mollie webhook re-fetches payment status from Mollie (never trusts the payload) and dispatches onto this bus. The inbound-webhook controller is net-new — closest precedent is `src/Controller/Public/*`.

## Non-goals

- Percentage-based commission — flat per-transaction fee only for v1.
- Multi-provider support / Stripe fallback — Mollie only unless the validation gate forces a rethink.
- Platform-collects (old "Model A") — rejected for the tax-liability reasons above.
- Separate usage invoicing (B2) — rejected; revisit only for non-transaction charge metrics.
