# Stripe payment provider, webhook and front from network

Opened: 2026-09-24
Updated: 2026-09-24
Author: agent:archeology

## Read this first — status of this todo

> **This is a proposal for discussion, not an order to code.** It was written by the 2026-09 network archaeology pass. Read it, then discuss it with the owner: every design choice and recommendation below is to be challenged and validated **before** any code is written. Do not start implementing on your own.
>
> - Context: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/index.md.j2` (entry point, order between packages), then `sources.md.j2` (where the legacy code lives: archive repo, branch checkouts, GitLab issues) and the domain page linked below.
> - Pending owner decisions affecting this work are listed in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/recap.md.j2`, section "Décisions qui t'attendent". Where this todo assumes an answer, treat it as an open question.
> - Safety: `NETWORK/local/network` runs on **production data** (real bookkeeping, real invoices in `var/`, a prod dump in `.wex/mysql/dumps/`) — read its code only, never run anything against it. Anonymize any fixture taken from network (bank exports, FEC, mails contain real names/accounts). Never copy secrets found in its history (Stripe keys, tokens, passwords, private keys).

## Goal

Turn `symfony-stripe`, which today is a single `StripeHelper`, into the Stripe provider of the payment stack. It should contain:
- PaymentIntent creation;
- a secured, idempotent webhook endpoint;
- a Stripe client factory, with the API version pinned in config;
- a front module that uses the Stripe **Payment Element**;
- test helpers that sign fake webhooks properly.

The owner's words: "I will quickly need a payment management package, using Stripe as usual."

Knowledge page: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/cart-payment.md.j2`. Read the sections "Payment flow", "Front-end parts", "Pitfalls / bugs" and "Recommended target design" §3.

Issues, in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/`:
- `039.md`: webhooks and security.
- `156.md`: Stripe `amount >= 1` crash.
- `059.md`: log Stripe errors.
- `122.md` and `141.md`: ledger side, **not here**.

Local test procedure: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/introduction.md`, section "Test Stripe API". It uses `stripe listen --forward-to …` and `stripe trigger payment_intent.succeeded`.

## Prerequisites

- `symfony-payment` must exist first. It is proposed at `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/proposed-packages/symfony-payment/todo/extract-from-network.md`, and provides `PaymentProviderInterface`, `PaymentService` and the events.
- If the owner decides to keep everything in one package, merge that todo into this one.
- Add `stripe/stripe-php` (latest major) to `require`.

## Decisions already implied

- The webhook signature is **always** verified.
  - network forged the signature from the body in dev/local/test (`AbstractStripeController::buildStripeEvent` + `EnvironmentHelper::LIST_NO_WEBHOOK_SECURITY`). That means a reachable dev server accepted any payload.
  - Tests must sign payloads with the configured secret using `StripeHelper::buildFakeSignature()`, which is already in this package.
- `isStripeTestEnvironment()` may stay, but only to choose test keys and show debug tools, never to skip verification.
- Keys come from env/secrets only. network committed live keys in `config/services_prod.yaml` and `.env.local`; never copy them.
- One webhook route handles several event types, not one route per event.

## Steps

1. Read the network sources. Paths are relative to `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/src/`:
   - `Wex/BaseBundle/Service/Payment/PaymentStripeService.php`: `initStripe`, `payNowStripePaymentInit`, `buildJsData`. The balance-transaction methods are ledger work: skip them, but see step 8.
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Wex/BaseBundle/Service/Payment/PaymentStripeService.php` (prod): `payNowComplete`, `getCharge`, `getBalanceTransaction`, `payNowStripePaymentComplete`. These store the charge id; fos lost them.
   - `Api/Controller/System/AbstractStripeController.php` and `StripeController.php` (status guard and idempotency).
   - `Wex/BaseBundle/Helper/StripeHelper.php` (`buildFakeWebhookPayload`).
   - `Wex/BaseBundle/Resources/js/services/StripeService.ts`, `js/forms/stripe/Payment.ts`, `js/forms/stripe/partials/*.ts`, `js/components/payment-stripe.ts`.
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/front/tunnels/payment/pay.html.twig`: debug buttons and card/cash/transfer pads.
2. Bundle scaffolding: `AbstractBundle`, config tree `wexample_stripe: { secret_key, public_key, webhook_secret, api_version }` bound to env vars, and `Service/StripeClientFactory` returning `StripeClient` with the pinned API version. network pinned `2020-03-02`; use the current one.
3. `Provider/StripePaymentProvider implements PaymentProviderInterface`:
   - `init()` calls `paymentIntents->create` with:
     - `amount`, `currency` lowercased, `automatic_payment_methods[enabled]=true`;
     - `metadata[payment_id]`;
     - an idempotency key derived from payment id + amount.
   - Store `providerReference` = intent id, and return the `client_secret`.
   - If the payment already has an intent and the amount changed, update the intent instead of creating a new one.
   - Refuse amount ≤ 0.
   - On `ApiErrorException`, log and mark the payment failed.
   - `fetchStatus()` retrieves the intent, maps the Stripe status to the payment status, and stores `latest_charge` as `providerChargeId`.
   - `refund()` calls `refunds->create`.
4. `Controller/StripeWebhookController`, route `POST /api/stripe/webhook`, name `stripe_webhook`.
   - Call `Webhook::constructEvent` with the secret. An invalid signature returns 400 and is logged.
   - Map these events to `PaymentService` transitions: `payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `payment_intent.canceled`, `charge.refunded`.
   - Unknown intent → 200 with an ignored body, so Stripe stops retrying foreign events.
   - Illegal transition → 409 so Stripe retries. This reproduces network's guard that refuses success before `pending`.
   - Deduplicate by `event.id` with a small `StripeWebhookEvent` table (id, type, receivedAt, processedAt), or with the payment's metadata.
5. Front (`assets/`), following `wex ai::design/rules --formatter javascript-code`:
   - A TS module that mounts the **Payment Element** from `client_secret`.
   - On submit it calls `stripe.confirmPayment({elements, confirmParams: {return_url}})`.
   - On return, it polls `symfony-payment`'s `/_payment/status/{id}` until the status is final.
   - This replaces the 7 legacy `PaymentType*` classes: Card, SepaDebit, Sofort, Bancontact, MobilePay, Cash, Transfer. network's async ones never had a submit handler. Cash and transfer belong to the manual provider UI, not Stripe.
   - Keep test-card hints outside prod, as network's `StripeService.init` did.
6. Extend `StripeHelper` with `buildFakeWebhookPayload(string $intentId, string $type = 'payment_intent.succeeded', array $object = []): array`. The network version took the `Payment` entity. Add a `StripeWebhookTestTrait` that posts a correctly signed payload to the webhook route.
7. Document the local `stripe listen` / `stripe trigger` procedure in the package README.
8. Expose `StripeClientFactory` for `symfony-accounting`. The ledger import (`getBalanceTransactionRange`, fee invoices, "STRIPE FEES", #122) will use it there. Leave a note in the README; do not implement it here.

## Do not

- Do not bypass signature verification in any environment.
- Do not keep one route per event type (`/stripe/hook/payment-intent/succeeded`). If network 2027 needs the old URL during migration, add an alias in the app.
- Do not port the legacy Elements `PaymentType*` classes, `payment-stripe.ts`, or the `sleep()` wait loop.
- Do not import Cart, Invoice, tunnel classes or `App\`.
- Do not port accounting import code (`importLastPayments`, `saveNewTransactionFromBalanceTransaction`) into this package.

## Acceptance (tests in the package)

- Webhook with a valid signature and `payment_intent.succeeded` for a `pending` payment → 200, payment `succeeded`, one event dispatched. Replay → 200, no second event.
- Invalid signature → 400 in every environment, including `test`.
- Success for a `created` (not yet pending) payment → 409 (network behaviour).
- Unknown intent → 200 ignored.
- Provider `init` with a mocked `StripeClient`: sends the idempotency key and metadata, and refuses zero amounts.
- `buildFakeSignature` + `Webhook::constructEvent` round-trip test.
